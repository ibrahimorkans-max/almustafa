// Unit tests for the PWA sync engine: `node --test tests/js/`
import assert from 'node:assert/strict';
import { beforeEach, describe, it } from 'node:test';
import { MemoryStore } from '../../public/pwa/js/store-contract.js';
import { ApiError, NetworkError, State, SyncEngine } from '../../public/pwa/js/sync-engine.js';
import { isValidVehicleNumber, normalizeVehicleNumber } from '../../public/pwa/js/validation.js';

/** Minimal in-memory server speaking the same contract as /api/v1/sync. */
class FakeServer {
  constructor() {
    this.records = new Map(); // id -> {type, data}
    this.feed = [];
    this.seen = new Map(); // event_id -> result
    this.offline = false;
    this.wipe = false;
    this.unauthorized = false;
    this.pushed = [];
    this.wipeAcked = false;
    this.failNextPushAfter = null;
  }

  guard() {
    if (this.offline) throw new NetworkError('offline');
    if (this.unauthorized) throw new ApiError(401, { error: 'session_invalid' });
    if (this.wipe) throw new ApiError(403, { error: 'wipe_required' });
  }

  async bootstrap() {
    this.guard();
    return { max_offline_hours: 72, max_push_events: 2, cursor: this.feed.length };
  }

  async push(events) {
    this.guard();
    this.pushed.push(...events.map((e) => e.client_seq));
    const results = [];
    for (const e of events) {
      if (this.seen.has(e.event_id)) {
        results.push({ ...this.seen.get(e.event_id), duplicate: true });
        continue;
      }
      if (this.failNextPushAfter !== null && results.length >= this.failNextPushAfter) {
        this.failNextPushAfter = null;
        throw new NetworkError('connection dropped');
      }
      const [type] = e.type.split('.');
      let result;
      if (e.payload.registration_no === 'BAD') {
        result = { status: 'rejected', reason: 'validation_failed', errors: { registration_no: ['invalid'] } };
      } else if (e.payload.big) {
        result = { status: 'pending_online', reason: 'online_confirmation_required' };
      } else {
        const current = this.records.get(e.record_id);
        const version = (current?.data.version || 0) + 1;
        const data = { ...(current?.data || {}), ...e.payload, id: e.record_id, version };
        this.records.set(e.record_id, { type, data });
        this.feed.push({ type, op: 'upsert', id: e.record_id });
        result = { status: 'applied', version };
      }
      result = { event_id: e.event_id, client_seq: e.client_seq, ...result };
      if (result.status !== 'pending_online') this.seen.set(e.event_id, result);
      results.push(result);
    }
    return { results, cursor: this.feed.length };
  }

  async pull(cursor, limit) {
    this.guard();
    const slice = this.feed.slice(cursor, cursor + limit);
    return {
      changes: slice.map((c) => (c.op === 'archive'
        ? { type: c.type, op: 'archive', id: c.id }
        : { type: c.type, op: 'upsert', id: c.id, data: this.records.get(c.id).data })),
      next_cursor: cursor + slice.length,
      has_more: cursor + slice.length < this.feed.length,
    };
  }

  async wipeAck() {
    this.wipeAcked = true;
    return { status: 'wiped' };
  }

  /** A change made by someone else on the server. */
  serverEdit(type, id, data) {
    const version = (this.records.get(id)?.data.version || 0) + 1;
    this.records.set(id, { type, data: { ...(this.records.get(id)?.data || {}), ...data, id, version } });
    this.feed.push({ type, op: 'upsert', id });
  }

  serverArchive(type, id) {
    this.feed.push({ type, op: 'archive', id });
  }
}

let store;
let server;
let engine;
let clock;
let n;

beforeEach(() => {
  store = new MemoryStore();
  server = new FakeServer();
  clock = new Date('2026-10-08T06:00:00Z');
  n = 0;
  engine = new SyncEngine({
    store, api: server, accountId: 'acc-1', now: () => clock, uuid: () => `00000000-0000-4000-8000-${String(++n).padStart(12, '0')}`,
  });
});

describe('offline writes', () => {
  it('saves locally first and counts unsynced events', async () => {
    server.offline = true;
    const v = await engine.upsert('vehicle', null, { registration_no: 'HR05AB1234', vehicle_type: 'truck' });

    assert.equal((await store.getRecord('vehicle', v.id)).registration_no, 'HR05AB1234');
    assert.equal((await engine.status()).unsynced, 1);

    const status = await engine.sync();
    assert.equal(status.state, State.OFFLINE);
    assert.equal(status.unsynced, 1, 'nothing is lost while offline');
  });

  it('uploads in client_seq order in batches once online, then pulls', async () => {
    server.offline = true;
    for (const reg of ['HR05AB0001', 'HR05AB0002', 'HR05AB0003']) {
      await engine.upsert('vehicle', null, { registration_no: reg, vehicle_type: 'truck' });
    }
    server.offline = false;

    const status = await engine.sync();
    assert.equal(status.state, State.ONLINE);
    assert.equal(status.unsynced, 0);
    assert.deepEqual(server.pushed, [1, 2, 3]);
    const local = await store.listRecords('vehicle');
    assert.equal(local.length, 3);
    assert.ok(local.every((v) => v.version === 1 && v._pending === false));
    assert.equal(await store.getMeta('cursor'), 3);
  });

  it('sends base_version for edits of synced records', async () => {
    const v = await engine.upsert('vehicle', null, { registration_no: 'HR05AB1234', vehicle_type: 'truck' });
    await engine.sync();
    await engine.upsert('vehicle', v.id, { vehicle_type: 'tempo' });

    const [event] = await store.listEvents();
    assert.equal(event.base_version, 1);
    assert.deepEqual(event.payload, { vehicle_type: 'tempo' });
  });
});

describe('reliability', () => {
  it('resends after a dropped connection without duplicating (idempotent event ids)', async () => {
    await engine.upsert('vehicle', null, { registration_no: 'HR05AB0001', vehicle_type: 'truck' });
    await engine.upsert('vehicle', null, { registration_no: 'HR05AB0002', vehicle_type: 'truck' });
    server.failNextPushAfter = 1; // server applies the first event, then the connection drops

    assert.equal((await engine.sync()).state, State.OFFLINE);
    assert.equal((await engine.status()).unsynced, 2, 'client did not get the answer, keeps both');

    const status = await engine.sync();
    assert.equal(status.unsynced, 0);
    assert.equal(server.records.size, 2);
    assert.equal([...server.records.values()].every((r) => r.data.version === 1), true, 'no double apply');
  });

  it('keeps local unsynced edits when the server sends an older state', async () => {
    const v = await engine.upsert('vehicle', null, { registration_no: 'HR05AB1234', vehicle_type: 'truck' });
    await engine.sync();
    server.offline = true;
    await engine.upsert('vehicle', v.id, { vehicle_type: 'tempo' });
    server.offline = false;
    server.serverEdit('vehicle', v.id, { capacity_kg: '9000.000' }); // pulled before our push result is known

    await engine.pullAll();
    assert.equal((await store.getRecord('vehicle', v.id)).vehicle_type, 'tempo');
  });

  it('drops archived records from local storage', async () => {
    const v = await engine.upsert('vehicle', null, { registration_no: 'HR05AB1234', vehicle_type: 'truck' });
    await engine.sync();
    server.serverArchive('vehicle', v.id);

    await engine.sync();
    assert.equal(await store.getRecord('vehicle', v.id), null);
  });

  it('surfaces rejected events and removes them from the outbox', async () => {
    const v = await engine.upsert('vehicle', null, { registration_no: 'BAD', vehicle_type: 'truck' });
    const status = await engine.sync();

    assert.equal(status.unsynced, 0);
    assert.deepEqual((await store.getRecord('vehicle', v.id))._error, { registration_no: ['invalid'] });
    assert.equal((await store.getMeta('notices'))[0].level, 'error');
  });

  it('holds online-only actions without blocking the queue', async () => {
    await engine.upsert('payment', null, { big: true });
    await engine.upsert('vehicle', null, { registration_no: 'HR05AB1234', vehicle_type: 'truck' });

    const status = await engine.sync();
    assert.equal(status.held, 1);
    assert.equal(status.unsynced, 1);
    assert.equal(server.records.size, 1, 'the vehicle behind it was still uploaded');
  });
});

describe('security', () => {
  it('wipes everything and acknowledges when the server orders a remote wipe', async () => {
    await engine.upsert('vehicle', null, { registration_no: 'HR05AB1234', vehicle_type: 'truck' });
    await store.setMeta('tokens', { access: 'rma_x', refresh: 'rmr_x' });
    server.wipe = true;

    const status = await engine.sync();
    assert.equal(status.state, State.WIPED);
    assert.equal(server.wipeAcked, true);
    assert.equal(await store.getMeta('tokens'), null);
    assert.deepEqual(await store.listRecords('vehicle'), []);
    await assert.rejects(() => engine.upsert('vehicle', null, {}), /wiped/);
  });

  it('asks for sign-in on 401 but keeps unsynced work', async () => {
    await engine.upsert('vehicle', null, { registration_no: 'HR05AB1234', vehicle_type: 'truck' });
    server.unauthorized = true;

    const status = await engine.sync();
    assert.equal(status.state, State.AUTH_REQUIRED);
    assert.equal(status.unsynced, 1);
  });

  it('locks after the maximum offline duration', async () => {
    await engine.sync(); // records server contact
    server.offline = true;
    clock = new Date(clock.getTime() + 73 * 3600 * 1000);

    const status = await engine.sync();
    assert.equal(status.state, State.LOCKED);
    await assert.rejects(() => engine.upsert('vehicle', null, {}), /locked/);
  });

  it('only uploads events created by the signed-in account', async () => {
    await store.addEvent({ event_id: 'e-other', client_seq: 99, type: 'vehicle.upsert', record_id: 'r', payload: {}, account_id: 'acc-2' });
    await engine.sync();

    assert.deepEqual(server.pushed, []);
    assert.equal((await store.listEvents()).length, 1);
  });
});

describe('validation mirror', () => {
  it('matches the server rules for vehicle numbers', () => {
    assert.equal(normalizeVehicleNumber('hr 05 ab-1234'), 'HR05AB1234');
    assert.ok(isValidVehicleNumber('HR05AB1234'));
    assert.ok(isValidVehicleNumber('22BH1234AA'));
    assert.ok(!isValidVehicleNumber('NOTAPLATE'));
  });
});

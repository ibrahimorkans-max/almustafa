/**
 * Offline-first sync engine shared by every screen of the PWA.
 *
 *  - Every write goes to local storage first and becomes an event in the outbox
 *    (client-generated UUIDs, strictly increasing client_seq, base_version).
 *  - sync(): push the outbox in order, then pull changes after the cursor until done.
 *  - The engine never loses an event: it is only removed once the server returned a
 *    final status for it (see docs/api/openapi.yaml, SyncResult.status).
 *
 * Storage and network are injected, so the same code runs in the browser (IndexedDB +
 * fetch) and in `node --test` (in-memory fakes).
 */

export const State = Object.freeze({
  ONLINE: 'online',
  OFFLINE: 'offline',
  SYNCING: 'syncing',
  AUTH_REQUIRED: 'auth_required',
  LOCKED: 'locked',
  WIPED: 'wiped',
});

/** Statuses after which the event leaves the outbox. */
const FINAL = new Set(['applied', 'conflict_client_won', 'conflict_server_won', 'needs_review', 'rejected']);

export class ApiError extends Error {
  constructor(status, body) {
    super(`HTTP ${status}`);
    this.status = status;
    this.body = body || {};
  }
}

export class NetworkError extends Error {}

export class SyncEngine {
  /**
   * @param {{
   *   store: import('./store-contract.js').Store,
   *   api: { bootstrap(): Promise<any>, push(events: any[]): Promise<any>, pull(cursor: number, limit: number): Promise<any>, wipeAck(): Promise<any> },
   *   accountId: string,
   *   now?: () => Date,
   *   uuid?: () => string,
   *   onChange?: (status: object) => void,
   * }} options
   */
  constructor({ store, api, accountId, now = () => new Date(), uuid = () => crypto.randomUUID(), onChange = () => {}, clock = null }) {
    this.store = store;
    this.api = api;
    this.accountId = accountId;
    this.now = now;
    this.uuid = uuid;
    this.onChange = onChange;
    // Device clock for the server's time check: () => ({ boot_id, monotonic_ms, wall_time }).
    this.clock = clock;
    this.state = State.ONLINE;
    this.running = null;
  }

  // ---------------------------------------------------------------- local writes

  /**
   * Create or update a record locally and queue the event. Returns the local record.
   * @param {string} type e.g. "vehicle"
   * @param {string|null} id null = new record
   * @param {object} fields writable fields (partial allowed for updates)
   */
  async upsert(type, id, fields) {
    this.assertUsable();
    const existing = id ? await this.store.getRecord(type, id) : null;
    const recordId = id || this.uuid();
    const record = { ...(existing || {}), ...fields, id: recordId, _pending: true };
    if (!existing) record.version = 0;

    const seq = (await this.store.getMeta('client_seq', 0)) + 1;
    await this.store.setMeta('client_seq', seq);
    await this.store.putRecord(type, record);
    await this.store.addEvent({
      event_id: this.uuid(),
      client_seq: seq,
      occurred_at: this.now().toISOString(),
      type: `${type}.upsert`,
      record_id: recordId,
      base_version: existing && existing.version > 0 ? existing.version : null,
      payload: fields,
      account_id: this.accountId,
    });
    await this.emit();
    return record;
  }

  /**
   * Queue an append-only event (no local record), e.g. an attendance punch. Returns the event.
   * @param {string} type e.g. "attendance.punch"
   * @param {object} payload
   */
  async append(type, payload) {
    this.assertUsable();
    const seq = (await this.store.getMeta('client_seq', 0)) + 1;
    await this.store.setMeta('client_seq', seq);
    const event = {
      event_id: this.uuid(), client_seq: seq, occurred_at: this.now().toISOString(), type,
      record_id: null, base_version: null, payload, account_id: this.accountId,
    };
    await this.store.addEvent(event);
    await this.emit();
    return event;
  }

  /**
   * Keep a file (e.g. the attendance photo) until it is uploaded; events refer to it by its SHA-256,
   * and the server waits for the file rather than losing the evidence.
   */
  async addFile(sha256, mime, base64) {
    const files = await this.store.getMeta('files', {});
    files[sha256] = { mime, base64 };
    await this.store.setMeta('files', files);
  }

  async uploadFiles() {
    const files = await this.store.getMeta('files', {});
    for (const [sha, file] of Object.entries(files)) {
      const result = await this.api.uploadFile(file.base64, file.mime);
      if (result.sha256 !== sha) throw new ApiError(500, { error: 'file_hash_mismatch' });
      const left = await this.store.getMeta('files', {});
      delete left[sha];
      await this.store.setMeta('files', left);
    }
  }

  // ---------------------------------------------------------------- sync

  /** Runs one full sync; concurrent calls share the same run. */
  sync() {
    if (!this.running) {
      this.running = this.runSync().finally(() => {
        this.running = null;
      });
    }
    return this.running;
  }

  async runSync() {
    if (this.state === State.WIPED) return this.status();
    this.state = State.SYNCING;
    await this.emit();
    try {
      const boot = await this.api.bootstrap();
      await this.store.setMeta('max_offline_hours', boot.max_offline_hours);
      await this.store.setMeta('max_push_events', boot.max_push_events);
      await this.touchServerContact();
      await this.uploadFiles();
      await this.pushAll(boot.max_push_events || 500);
      await this.pullAll();
      await this.store.setMeta('last_sync_at', this.now().toISOString());
      await this.store.setMeta('server_message', null);
      this.state = State.ONLINE;
    } catch (error) {
      await this.handleError(error);
    }
    await this.emit();
    return this.status();
  }

  async pushAll(batchSize) {
    for (;;) {
      const events = (await this.store.listEvents())
        .filter((e) => e.account_id === this.accountId && !e._held)
        .sort((a, b) => a.client_seq - b.client_seq)
        .slice(0, batchSize);
      if (events.length === 0) return;

      const body = await this.api.push(events.map(({ account_id, _held, ...wire }) => wire), this.clock ? this.clock() : undefined);
      await this.touchServerContact();
      let progressed = false;
      for (const result of body.results) {
        progressed = (await this.applyResult(result, events.find((e) => e.event_id === result.event_id))) || progressed;
      }
      if (!progressed) return; // only retry/held results left: try again next sync
    }
  }

  /** @returns {Promise<boolean>} whether the event left the outbox */
  async applyResult(result, event) {
    if (!event) return false;
    const [type] = event.type.split('.');

    if (result.status === 'pending_online') {
      await this.store.updateEvent({ ...event, _held: true });
      await this.addNotice('info', `${type}: waiting for online confirmation`, event);
      return false;
    }
    if (!FINAL.has(result.status)) return false; // "retry": keep it

    await this.store.removeEvent(event.event_id);
    if (result.attendance_status) {
      // The server's verdict on a punch, in its own words, kept for the employee to see.
      const verdicts = await this.store.getMeta('punch_results', {});
      verdicts[event.event_id] = { status: result.attendance_status, messages: result.messages || [] };
      await this.store.setMeta('punch_results', verdicts);
      if (result.attendance_status !== 'valid') {
        await this.addNotice(result.attendance_status === 'rejected' ? 'error' : 'warning',
          `Attendance ${event.payload.kind}: ${result.attendance_status} — ${(result.messages || []).join('; ')}`, event);
      }
    }
    const local = event.record_id ? await this.store.getRecord(type, event.record_id) : null;
    const stillPending = (await this.store.listEvents()).some((e) => e.record_id === event.record_id);

    if (result.status === 'conflict_server_won' && result.record) {
      await this.store.putRecord(type, { ...result.record, _pending: stillPending });
      await this.addNotice('warning', `${type}: your change was older than one made on the server and was not applied`, event);
    } else if (local && (result.status === 'applied' || result.status === 'conflict_client_won')) {
      await this.store.putRecord(type, { ...local, version: result.version, _pending: stillPending, _error: undefined });
    } else if (result.status === 'needs_review') {
      if (local) await this.store.putRecord(type, { ...local, _pending: stillPending, _review: true });
      await this.addNotice('warning', `${type}: sent to a supervisor for review (${result.reason})`, event);
    } else if (result.status === 'rejected') {
      if (local) await this.store.putRecord(type, { ...local, _pending: stillPending, _error: result.errors || result.reason });
      await this.addNotice('error', `${type}: rejected (${result.reason})`, event, result.errors);
    }
    return true;
  }

  async pullAll() {
    let cursor = await this.store.getMeta('cursor', 0);
    for (;;) {
      const page = await this.api.pull(cursor, 500);
      await this.touchServerContact();
      const pendingIds = new Set((await this.store.listEvents()).map((e) => e.record_id));
      for (const change of page.changes) {
        if (change.op === 'archive') {
          await this.store.deleteRecord(change.type, change.id);
        } else if (!pendingIds.has(change.id)) {
          // Local unsynced edits win locally until the server has decided on them.
          await this.store.putRecord(change.type, { ...change.data, _pending: false });
        }
      }
      cursor = page.next_cursor;
      await this.store.setMeta('cursor', cursor);
      if (!page.has_more) return;
    }
  }

  async handleError(error) {
    if (error instanceof ApiError && error.status === 403 && error.body.error === 'wipe_required') {
      await this.wipe();
      try {
        await this.api.wipeAck();
      } catch {
        // The server keeps asking until it gets the acknowledgement; local data is gone either way.
      }
      return;
    }
    // Lockdown, maintenance or withdrawn access: show the server's own words; the outbox is kept.
    if (error instanceof ApiError && (error.status === 401 || error.status === 503)) {
      await this.store.setMeta('server_message', error.body.message || null);
    }
    if (error instanceof ApiError && error.status === 401) {
      this.state = State.AUTH_REQUIRED;
      return;
    }
    this.state = State.OFFLINE;
    if (await this.offlineTooLong()) this.state = State.LOCKED;
  }

  // ---------------------------------------------------------------- safety

  async touchServerContact() {
    await this.store.setMeta('last_server_contact_at', this.now().toISOString());
  }

  /** After max_offline_hours without server contact the app locks until an online sign-in. */
  async offlineTooLong() {
    const last = await this.store.getMeta('last_server_contact_at', null);
    const hours = await this.store.getMeta('max_offline_hours', 72);
    if (!last) return false;
    return this.now().getTime() - new Date(last).getTime() > hours * 3600 * 1000;
  }

  assertUsable() {
    if (this.state === State.WIPED || this.state === State.LOCKED) {
      throw new Error(`App is ${this.state}; sign in online first.`);
    }
  }

  /** Remote wipe or logout: everything local goes, including tokens. */
  async wipe() {
    await this.store.clearAll();
    this.state = State.WIPED;
  }

  // ---------------------------------------------------------------- status

  async addNotice(level, message, event, details) {
    const notices = await this.store.getMeta('notices', []);
    notices.unshift({ level, message, at: this.now().toISOString(), record_id: event.record_id, details });
    await this.store.setMeta('notices', notices.slice(0, 50));
  }

  async status() {
    const events = await this.store.listEvents();
    return {
      state: this.state,
      unsynced: events.filter((e) => e.account_id === this.accountId).length,
      held: events.filter((e) => e._held).length,
      lastSyncAt: await this.store.getMeta('last_sync_at', null),
      serverMessage: await this.store.getMeta('server_message', null),
    };
  }

  async emit() {
    this.onChange(await this.status());
  }
}

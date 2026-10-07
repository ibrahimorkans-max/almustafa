/**
 * IndexedDB implementation of the Store contract (see store-contract.js).
 *
 * Stores: meta (key/value), outbox (events by event_id, indexed by client_seq),
 * records (by [type, id], indexed by type).
 */

const DB_NAME = 'ricemill';
const DB_VERSION = 1;

function request(req) {
  return new Promise((resolve, reject) => {
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

export async function openIdbStore() {
  const open = indexedDB.open(DB_NAME, DB_VERSION);
  open.onupgradeneeded = () => {
    const db = open.result;
    db.createObjectStore('meta');
    db.createObjectStore('outbox', { keyPath: 'event_id' }).createIndex('client_seq', 'client_seq', { unique: true });
    db.createObjectStore('records', { keyPath: ['_type', 'id'] }).createIndex('type', '_type');
  };
  return new IdbStore(await request(open));
}

class IdbStore {
  constructor(db) {
    this.db = db;
  }

  tx(store, mode = 'readonly') {
    return this.db.transaction(store, mode).objectStore(store);
  }

  async getMeta(key, fallback = null) {
    const value = await request(this.tx('meta').get(key));
    return value === undefined ? fallback : value;
  }

  async setMeta(key, value) {
    await request(this.tx('meta', 'readwrite').put(value, key));
  }

  async addEvent(event) {
    await request(this.tx('outbox', 'readwrite').add(event));
  }

  async updateEvent(event) {
    await request(this.tx('outbox', 'readwrite').put(event));
  }

  async removeEvent(eventId) {
    await request(this.tx('outbox', 'readwrite').delete(eventId));
  }

  async listEvents() {
    return request(this.tx('outbox').index('client_seq').getAll());
  }

  async putRecord(type, record) {
    // JSON round-trip drops undefined fields and anything not structured-cloneable.
    await request(this.tx('records', 'readwrite').put(JSON.parse(JSON.stringify({ ...record, _type: type }))));
  }

  async getRecord(type, id) {
    return (await request(this.tx('records').get([type, id]))) || null;
  }

  async deleteRecord(type, id) {
    await request(this.tx('records', 'readwrite').delete([type, id]));
  }

  async listRecords(type) {
    return request(this.tx('records').index('type').getAll(type));
  }

  async clearAll() {
    for (const name of ['meta', 'outbox', 'records']) {
      await request(this.tx(name, 'readwrite').clear());
    }
  }
}

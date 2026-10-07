/**
 * Storage contract used by the sync engine, plus an in-memory implementation
 * (used by unit tests and as a fallback when IndexedDB is unavailable).
 *
 * @typedef {object} Store
 * @property {(key: string, fallback?: any) => Promise<any>} getMeta
 * @property {(key: string, value: any) => Promise<void>} setMeta
 * @property {(event: object) => Promise<void>} addEvent
 * @property {(event: object) => Promise<void>} updateEvent
 * @property {(eventId: string) => Promise<void>} removeEvent
 * @property {() => Promise<object[]>} listEvents
 * @property {(type: string, record: object) => Promise<void>} putRecord
 * @property {(type: string, id: string) => Promise<object|null>} getRecord
 * @property {(type: string, id: string) => Promise<void>} deleteRecord
 * @property {(type: string) => Promise<object[]>} listRecords
 * @property {() => Promise<void>} clearAll
 */

const clone = (value) => (value === undefined ? undefined : JSON.parse(JSON.stringify(value)));

/** @implements {Store} */
export class MemoryStore {
  constructor() {
    this.meta = new Map();
    this.events = new Map();
    this.records = new Map();
  }

  async getMeta(key, fallback = null) {
    return this.meta.has(key) ? clone(this.meta.get(key)) : fallback;
  }

  async setMeta(key, value) {
    this.meta.set(key, clone(value));
  }

  async addEvent(event) {
    this.events.set(event.event_id, clone(event));
  }

  async updateEvent(event) {
    this.events.set(event.event_id, clone(event));
  }

  async removeEvent(eventId) {
    this.events.delete(eventId);
  }

  async listEvents() {
    return [...this.events.values()].map(clone);
  }

  async putRecord(type, record) {
    this.records.set(`${type}:${record.id}`, clone({ ...record, _type: type }));
  }

  async getRecord(type, id) {
    return clone(this.records.get(`${type}:${id}`)) || null;
  }

  async deleteRecord(type, id) {
    this.records.delete(`${type}:${id}`);
  }

  async listRecords(type) {
    return [...this.records.values()].filter((r) => r._type === type).map(clone);
  }

  async clearAll() {
    this.meta.clear();
    this.events.clear();
    this.records.clear();
  }
}

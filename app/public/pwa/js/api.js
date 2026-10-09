/**
 * Client for /api/v1 (auth + sync). Device-bound tokens: every call sends X-Device-Id.
 * An expired access token is refreshed once, transparently; refresh tokens are single use.
 */
import { ApiError, NetworkError } from './sync-engine.js';

const BASE = '/api/v1';

export class Api {
  /** @param {import('./store-contract.js').Store} store */
  constructor(store) {
    this.store = store;
  }

  async deviceUid() {
    let uid = await this.store.getMeta('device_uid', null);
    if (!uid) {
      uid = `pwa-${crypto.randomUUID()}`;
      await this.store.setMeta('device_uid', uid);
    }
    return uid;
  }

  async login(username, password, otp) {
    const body = await this.send('POST', '/auth/login', {
      username, password, otp: otp || undefined,
      device_uid: await this.deviceUid(), device_name: navigator.userAgent.slice(0, 110), platform: 'pwa',
    }, false);
    if (body.status === 'ok') await this.saveTokens(body);
    return body;
  }

  async logout() {
    try {
      await this.send('POST', '/auth/logout');
    } catch {
      // Offline logout still wipes local data.
    }
  }

  bootstrap() {
    return this.send('GET', '/sync/bootstrap');
  }

  push(events, deviceClock) {
    return this.send('POST', '/sync/push', deviceClock ? { events, device_clock: deviceClock } : { events });
  }

  /** Upload a kept file (base64) as a browser form; the server answers with its SHA-256. */
  async uploadFile(base64, mime) {
    const bytes = Uint8Array.from(atob(base64), (ch) => ch.charCodeAt(0));
    const form = new FormData();
    form.append('file', new Blob([bytes], { type: mime }), mime === 'image/png' ? 'photo.png' : 'photo.jpg');
    return this.send('POST', '/files', form);
  }

  attendanceMe() {
    return this.send('GET', '/attendance/me');
  }

  attendanceConsent(noticeVersion) {
    return this.send('POST', '/attendance/consent', { notice_version: noticeVersion });
  }

  attendanceRequestDevice() {
    return this.send('POST', '/attendance/device', {});
  }

  pull(cursor, limit) {
    return this.send('GET', `/sync/pull?cursor=${encodeURIComponent(cursor)}&limit=${encodeURIComponent(limit)}`);
  }

  wipeAck() {
    return this.send('POST', '/sync/wipe-ack');
  }

  async saveTokens(body) {
    await this.store.setMeta('tokens', { access: body.access_token, refresh: body.refresh_token });
    if (body.account) await this.store.setMeta('account', body.account);
  }

  async send(method, path, json, authenticated = true, retried = false) {
    const headers = { Accept: 'application/json', 'X-Device-Id': await this.deviceUid() };
    const isForm = typeof FormData !== 'undefined' && json instanceof FormData;
    if (json !== undefined && !isForm) headers['Content-Type'] = 'application/json';
    const tokens = await this.store.getMeta('tokens', null);
    if (authenticated && tokens) headers.Authorization = `Bearer ${tokens.access}`;

    let response;
    try {
      const requestBody = json === undefined ? undefined : (isForm ? json : JSON.stringify(json));
      response = await fetch(BASE + path, { method, headers, body: requestBody, credentials: 'omit' });
    } catch (e) {
      throw new NetworkError(String(e));
    }
    const body = await response.json().catch(() => ({}));

    if (response.status === 401 && authenticated && !retried && tokens && (await this.refresh(tokens))) {
      return this.send(method, path, json, authenticated, true);
    }
    if (!response.ok) throw new ApiError(response.status, body);
    return body;
  }

  async refresh(tokens) {
    try {
      const body = await this.send('POST', '/auth/refresh', { refresh_token: tokens.refresh }, false, true);
      await this.saveTokens(body);
      return true;
    } catch {
      await this.store.setMeta('tokens', null);
      return false;
    }
  }
}

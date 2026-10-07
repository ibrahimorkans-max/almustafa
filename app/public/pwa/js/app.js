/**
 * PWA shell: sign-in, Online/Offline/Syncing badge with unsynced count, and the first
 * offline screen — vehicles at the gate (list + register, works with no network).
 */
import { Api } from './api.js';
import { openIdbStore } from './idb-store.js';
import { MemoryStore } from './store-contract.js';
import { ApiError, State, SyncEngine } from './sync-engine.js';
import { isValidVehicleNumber, normalizeVehicleNumber } from './validation.js';

const SYNC_INTERVAL_MS = 60_000;
const view = document.getElementById('view');
const badge = document.getElementById('status');

let store;
let api;
let engine = null;
let timer = null;

async function start() {
  try {
    store = await openIdbStore();
  } catch {
    store = new MemoryStore(); // private mode without IndexedDB: works until the tab closes
  }
  api = new Api(store);

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js', { scope: './' }).catch(() => {});
    navigator.serviceWorker.addEventListener('message', (e) => {
      if (e.data === 'sync') engine?.sync();
    });
  }
  window.addEventListener('online', () => engine?.sync());
  window.addEventListener('offline', () => renderBadge({ state: State.OFFLINE, unsynced: badge.dataset.unsynced || 0 }));

  const account = await store.getMeta('account', null);
  const tokens = await store.getMeta('tokens', null);
  if (account && tokens) {
    await startEngine(account);
  } else {
    showLogin();
  }
}

// ------------------------------------------------------------------ engine

async function startEngine(account) {
  engine = new SyncEngine({ store, api, accountId: account.id, onChange: onStatus });
  if (await engine.offlineTooLong()) {
    engine.state = State.LOCKED;
    return onStatus(await engine.status());
  }
  await showMain();
  await engine.emit();
  engine.sync();
  clearInterval(timer);
  timer = setInterval(() => navigator.onLine && engine.sync(), SYNC_INTERVAL_MS);
  if ('serviceWorker' in navigator && 'SyncManager' in window) {
    navigator.serviceWorker.ready.then((reg) => reg.sync.register('ricemill-sync')).catch(() => {});
  }
}

async function onStatus(status) {
  renderBadge(status);
  if (status.state === State.AUTH_REQUIRED) {
    showMessage('Please sign in again', 'Your session ended. Unsynced changes are kept on this device and will upload after you sign in.', showLogin);
  } else if (status.state === State.LOCKED) {
    showMessage('Offline too long', 'This device has not reached the server for too long. Connect to the internet and sign in again.', showLogin);
  } else if (status.state === State.WIPED) {
    clearInterval(timer);
    showMessage('Device wiped', 'An administrator removed this device. All local data has been erased.', showLogin);
  } else if (document.getElementById('vehicles')) {
    await renderVehicles();
    await renderNotices();
  }
}

function renderBadge(status) {
  const labels = { online: 'Online', offline: 'Offline', syncing: 'Syncing…', auth_required: 'Sign in', locked: 'Locked', wiped: 'Wiped' };
  const state = !navigator.onLine && status.state === State.ONLINE ? State.OFFLINE : status.state;
  badge.textContent = `${labels[state] || state}${status.unsynced ? ` · ${status.unsynced} unsynced` : ''}`;
  badge.className = `badge ${state}`;
  badge.dataset.unsynced = status.unsynced;
}

// ------------------------------------------------------------------ screens

function mount(templateId) {
  view.replaceChildren(document.getElementById(templateId).content.cloneNode(true));
  view.focus();
}

function showLogin() {
  mount('tpl-login');
  const form = document.getElementById('login-form');
  const error = form.querySelector('.error');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    error.textContent = '';
    const data = Object.fromEntries(new FormData(form));
    try {
      const result = await api.login(data.username, data.password, data.otp);
      if (result.status === 'mfa_required') {
        form.querySelector('.otp').hidden = false;
        form.querySelector('[name=otp]').focus();
        return;
      }
      const previous = await store.getMeta('account_id_last', null);
      if (previous && previous !== result.account.id) {
        // A different user on this device: never mix their data, and never drop the previous
        // user's unsynced work — they must sign in and sync first.
        const theirs = (await store.listEvents()).filter((ev) => ev.account_id === previous).length;
        if (theirs > 0) {
          await api.logout();
          error.textContent = `The previous user has ${theirs} change(s) not uploaded yet. They must sign in and sync first.`;
          return;
        }
        const deviceUid = await store.getMeta('device_uid', null);
        const tokens = await store.getMeta('tokens', null);
        await store.clearAll();
        await store.setMeta('device_uid', deviceUid);
        await store.setMeta('tokens', tokens);
      }
      await store.setMeta('account', result.account);
      await store.setMeta('account_id_last', result.account.id);
      await startEngine(result.account);
    } catch (err) {
      error.textContent = err instanceof ApiError
        ? (err.body.message || 'Sign-in failed.')
        : 'No connection. The first sign-in on a device needs the internet.';
    }
  });
}

function showMessage(title, text, action) {
  mount('tpl-message');
  view.querySelector('.title').textContent = title;
  view.querySelector('.text').textContent = text;
  view.querySelector('.action').addEventListener('click', action);
}

async function showMain() {
  mount('tpl-main');
  document.getElementById('sync-now').addEventListener('click', () => engine.sync());
  document.getElementById('search').addEventListener('input', () => renderVehicles());
  document.getElementById('logout').addEventListener('click', async () => {
    const { unsynced } = await engine.status();
    if (unsynced > 0 && !confirm(`${unsynced} change(s) have not been uploaded and will be lost. Sign out anyway?`)) return;
    await api.logout();
    await engine.wipe();
    clearInterval(timer);
    showLogin();
  });

  const form = document.getElementById('vehicle-form');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const error = form.querySelector('.error');
    const data = Object.fromEntries(new FormData(form));
    const reg = normalizeVehicleNumber(data.registration_no);
    if (!isValidVehicleNumber(reg)) {
      error.textContent = 'Not a valid registration number (e.g. HR05AB1234 or 22BH1234AA).';
      return;
    }
    const existing = (await store.listRecords('vehicle')).find((v) => v.registration_no === reg);
    if (existing) {
      error.textContent = `${reg} is already registered.`;
      return;
    }
    error.textContent = '';
    await engine.upsert('vehicle', null, { registration_no: reg, vehicle_type: data.vehicle_type });
    form.reset();
    if (navigator.onLine) engine.sync();
  });
}

async function renderVehicles() {
  const list = document.getElementById('vehicles');
  if (!list) return;
  const q = normalizeVehicleNumber(document.getElementById('search').value || '');
  const vehicles = (await store.listRecords('vehicle'))
    .filter((v) => !q || (v.registration_no || '').includes(q))
    .sort((a, b) => (a.registration_no || '').localeCompare(b.registration_no || ''));

  document.getElementById('vehicle-count').textContent = `(${vehicles.length})`;
  list.replaceChildren(...vehicles.slice(0, 200).map((v) => {
    const li = document.createElement('li');
    const name = document.createElement('span');
    name.className = 'mono';
    name.textContent = v.registration_no;
    const meta = document.createElement('span');
    meta.className = 'muted';
    meta.textContent = [v.vehicle_type, v.is_blocked ? `BLOCKED: ${v.blocked_reason || ''}` : '', v._pending ? 'not uploaded yet' : '', v._review ? 'in review' : '', v._error ? 'rejected' : '']
      .filter(Boolean).join(' · ');
    if (v.is_blocked || v._error) li.className = 'danger';
    li.append(name, meta);
    return li;
  }));
}

async function renderNotices() {
  const list = document.getElementById('notices');
  if (!list) return;
  const notices = await store.getMeta('notices', []);
  list.replaceChildren(...notices.slice(0, 10).map((n) => {
    const li = document.createElement('li');
    li.className = n.level;
    li.textContent = `${new Date(n.at).toLocaleString()} — ${n.message}`;
    return li;
  }));
}

start();

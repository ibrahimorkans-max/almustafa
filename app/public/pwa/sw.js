/*
 * Service worker: caches the app shell so the PWA opens with no network.
 * API calls are never cached — data lives in IndexedDB and moves only through the sync API.
 */
const VERSION = 'ricemill-pwa-v2'; // bump on every shell change so phones fetch the new files
const SHELL = [
  './',
  'index.html',
  'manifest.webmanifest',
  'icon.svg',
  'css/app.css',
  'js/app.js',
  'js/attendance.js',
  'js/api.js',
  'js/idb-store.js',
  'js/store-contract.js',
  'js/sync-engine.js',
  'js/validation.js',
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(VERSION).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (event.request.method !== 'GET' || url.origin !== self.location.origin || url.pathname.startsWith('/api/')) {
    return; // network only
  }
  // Shell: cache first, refresh in the background.
  event.respondWith(
    caches.match(event.request, { ignoreSearch: true }).then((cached) => {
      const network = fetch(event.request)
        .then((response) => {
          if (response.ok) caches.open(VERSION).then((cache) => cache.put(event.request, response.clone()));
          return response;
        })
        .catch(() => cached);
      return cached || network;
    }),
  );
});

// Background Sync: ask an open window to run the sync engine (tokens live in its IndexedDB session).
self.addEventListener('sync', (event) => {
  if (event.tag === 'ricemill-sync') {
    event.waitUntil(self.clients.matchAll({ type: 'window' }).then((clients) => clients.forEach((c) => c.postMessage('sync'))));
  }
});

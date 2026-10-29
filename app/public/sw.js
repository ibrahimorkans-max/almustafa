/*
 * MillNexa's service worker: makes the site installable as an app (PWA). It deliberately keeps no
 * copy of any page or data: every request goes to the server, so nothing of the mill's business is
 * left on a phone that is lost, and a signed-out or wiped device shows nothing offline.
 */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {
    // Network only: the browser handles the request as usual.
});

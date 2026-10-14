/*
 * After sign-in, asks the browser once for its position (the browser shows its own permission
 * prompt; refusing is fine) and attaches it to this session, so Security → Sessions shows where the
 * person really is, not only the city of the IP address. Sent once per browser tab.
 */
(function () {
    const flag = 'rm_session_location_sent';
    try { if (sessionStorage.getItem(flag)) return; sessionStorage.setItem(flag, '1'); } catch (e) { return; }
    if (!navigator.geolocation || !window.isSecureContext) return;
    const token = document.querySelector('meta[name="csrf-token"]');
    const url = document.currentScript && document.currentScript.dataset.url;
    if (!token || !url) return;
    navigator.geolocation.getCurrentPosition(
        (p) => fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token.content, 'Accept': 'application/json' },
            body: JSON.stringify({ latitude: p.coords.latitude, longitude: p.coords.longitude, accuracy: Math.round(p.coords.accuracy) }),
        }).catch(() => {}),
        () => {},
        { enableHighAccuracy: false, timeout: 15000, maximumAge: 300000 },
    );
})();

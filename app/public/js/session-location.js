/*
 * After sign-in, asks the browser for its exact position (GPS on phones, Wi-Fi elsewhere; the browser
 * shows its own permission prompt) and attaches it to this session, so Security → Sessions shows the
 * area, city, region and country where the person really is, not only the city of the IP address. Sent
 * once when a page opens and again every 10 minutes while the page stays open.
 *
 * For a mill with "Only inside the mill" switched on, the position is required: a session that leaves
 * the mill, or stops sending its position, is ended, and the page goes back to sign-in.
 */
(function () {
    const key = 'rm_session_location_at';
    const every = 10 * 60 * 1000;
    if (!navigator.geolocation || !window.isSecureContext) return;
    const token = document.querySelector('meta[name="csrf-token"]');
    const url = document.currentScript && document.currentScript.dataset.url;
    if (!token || !url) return;

    function last() {
        try { return parseInt(sessionStorage.getItem(key) || '0', 10) || 0; } catch (e) { return Date.now(); }
    }

    function send() {
        if (Date.now() - last() < every) return;
        try { sessionStorage.setItem(key, String(Date.now())); } catch (e) { return; }
        navigator.geolocation.getCurrentPosition(
            (p) => fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token.content, 'Accept': 'application/json' },
                body: JSON.stringify({ latitude: p.coords.latitude, longitude: p.coords.longitude, accuracy: Math.round(p.coords.accuracy) }),
            })
                .then((r) => r.json())
                .then((r) => { if (r && r.signed_out) window.location.reload(); })
                .catch(() => {}),
            () => {},
            { enableHighAccuracy: true, timeout: 20000, maximumAge: 60000 },
        );
    }

    send();
    setInterval(send, 60 * 1000);
})();

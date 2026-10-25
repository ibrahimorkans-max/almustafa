{{--
    The device's position for "Only inside the mill" (Security → Sign-in rules). Read quietly when the
    browser already allows it; asked for only when the server says this mill needs it, and then the
    sign-in is sent again by itself. Nothing is sent anywhere else.
--}}
<div
    x-data="{
        asking: false,
        put(p) {
            $wire.$set('data.latitude', p.coords.latitude, false);
            $wire.$set('data.longitude', p.coords.longitude, false);
            $wire.$set('data.accuracy', Math.round(p.coords.accuracy), false);
        },
        read(thenSend) {
            if (! navigator.geolocation) return;
            this.asking = thenSend;
            navigator.geolocation.getCurrentPosition(
                (p) => { this.put(p); this.asking = false; if (thenSend) $wire.authenticate(); },
                () => { this.asking = false; },
                { enableHighAccuracy: true, timeout: 20000, maximumAge: 60000 },
            );
        },
        init() {
            navigator.permissions?.query({ name: 'geolocation' }).then((s) => { if (s.state === 'granted') this.read(false); }).catch(() => {});
        },
    }"
    x-on:rm-need-location.window="read(true)"
>
    <p x-show="asking" x-cloak class="text-center text-xs font-medium text-primary-600 dark:text-primary-400">Checking that you are at the mill…</p>
</div>

{{--
    Where this entry is made: asked of the browser only because the mill records it (Security → Sign-in
    rules → Record location with entries). Refusing is fine: the entry is saved without a place.
--}}
<div
    x-data="{
        state: 'asking',
        init() {
            if (! navigator.geolocation) { this.state = 'unsupported'; return; }
            navigator.geolocation.getCurrentPosition(
                (p) => {
                    $wire.$set('data.loc_lat', p.coords.latitude, false);
                    $wire.$set('data.loc_lng', p.coords.longitude, false);
                    $wire.$set('data.loc_acc', Math.round(p.coords.accuracy), false);
                    this.state = 'ok';
                },
                () => { this.state = 'refused'; },
                { enableHighAccuracy: true, timeout: 20000, maximumAge: 120000 },
            );
        },
    }"
    class="text-xs text-gray-500"
>
    <span x-show="state === 'asking'">Getting this device's location (your mill records where entries are made)…</span>
    <span x-show="state === 'ok'" x-cloak class="text-success-600">Location will be kept with this entry.</span>
    <span x-show="state === 'refused' || state === 'unsupported'" x-cloak>Location not shared: the entry is saved without it.</span>
</div>

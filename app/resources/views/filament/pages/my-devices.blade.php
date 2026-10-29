<x-filament-panels::page>
    @php($limit = $this->getLimit())
    <x-filament::section>
        <div class="flex flex-wrap items-start gap-6 text-sm">
            <div>
                <div class="text-xs font-medium text-gray-500">Devices in use</div>
                <div class="text-lg font-semibold">{{ $limit['used'] }}{{ $limit['limit'] > 0 ? ' of '.$limit['limit'] : '' }}</div>
                <div class="text-xs text-gray-500">{{ $limit['limit'] > 0 ? 'Your mill allows '.$limit['limit'].'. Remove one you no longer use to add a new one.' : 'Your mill sets no limit.' }}</div>
            </div>
            <div x-data="{
                    state: 'checking',
                    async check() {
                        if (! ('geolocation' in navigator)) { this.state = 'unsupported'; return; }
                        try {
                            const p = await navigator.permissions.query({ name: 'geolocation' });
                            this.state = p.state;
                            p.onchange = () => this.state = p.state;
                        } catch (e) { this.state = 'unknown'; }
                    },
                    ask() {
                        navigator.geolocation.getCurrentPosition(() => this.check(), () => this.check(), { enableHighAccuracy: true, timeout: 15000 });
                    },
                }" x-init="check()">
                <div class="text-xs font-medium text-gray-500">Location on this device</div>
                <div class="text-lg font-semibold">
                    <span x-show="state === 'granted'" class="text-success-600">Allowed</span>
                    <span x-show="state === 'prompt'">Not asked yet</span>
                    <span x-show="state === 'denied'" class="text-danger-600">Blocked</span>
                    <span x-show="state === 'unsupported' || state === 'unknown' || state === 'checking'">Unknown</span>
                </div>
                <div class="text-xs text-gray-500">
                    <span x-show="state === 'denied'">Allow it in the browser's site settings (the lock icon by the address).</span>
                    <span x-show="state !== 'denied'">Asked only when a screen needs it (sign-in inside the mill, attendance, entries that keep their place).</span>
                </div>
                <x-filament::button size="xs" color="gray" class="mt-2" x-show="state === 'prompt'" x-on:click="ask()">Allow location</x-filament::button>
            </div>
            <div>
                <div class="text-xs font-medium text-gray-500">Install the app</div>
                <div class="text-xs text-gray-500">On a phone, open this site in Chrome and choose "Add to Home screen" (Safari: Share → Add to Home Screen). The installed app is listed here as "Installed app" after you sign in from it.</div>
            </div>
        </div>
    </x-filament::section>
    {{ $this->table }}
</x-filament-panels::page>

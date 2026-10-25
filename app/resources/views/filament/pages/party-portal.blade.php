@php
    $st = $this->statement();
    $money = fn (int $p) => \App\Support\Values\Money::paise($p)->format();
    $kg = fn (?string $v) => $v === null ? '—' : number_format((float) $v, 0);
    $th = 'px-3 py-2 text-left text-xs font-semibold uppercase text-gray-500';
    $td = 'px-3 py-1.5 text-sm';
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="text-xs uppercase tracking-wide text-gray-500">Your account</div>
                <div class="text-2xl font-bold {{ $st['balance'] < 0 ? 'text-success-600' : ($st['balance'] > 0 ? 'text-danger-600' : '') }}">{{ $st['standing'] }}</div>
            </div>
            <div class="flex gap-2">
                @foreach (['vehicles' => 'Vehicles', 'entries' => 'Account entries', 'bills' => 'Bills'] as $key => $label)
                    <x-filament::button :color="$tab === $key ? 'primary' : 'gray'" wire:click="$set('tab', '{{ $key }}')" size="sm">{{ $label }}</x-filament::button>
                @endforeach
            </div>
        </div>
    </x-filament::section>

    @if (\App\Domain\Messaging\Gateways\FirebasePush::webReady())
        {{-- Notifications on this phone or browser (Firebase Cloud Messaging). --}}
        <x-filament::section>
            <div x-init="if ('Notification' in window && Notification.permission === 'granted') { connect().then(() => state = 'on').catch(() => {}) }"
                x-data="{
                    state: 'off',
                    async load(src) {
                        if (document.querySelector(`script[src='${src}']`)) return;
                        await new Promise((ok, fail) => { const s = document.createElement('script'); s.src = src; s.onload = ok; s.onerror = fail; document.head.appendChild(s); });
                    },
                    registration: null,
                    // Firebase ready on this page; a message arriving while the page is open is shown as a notification too.
                    async connect() {
                        await this.load(@js(\App\Http\Controllers\FirebaseServiceWorkerController::SDK.'firebase-app-compat.js'));
                        await this.load(@js(\App\Http\Controllers\FirebaseServiceWorkerController::SDK.'firebase-messaging-compat.js'));
                        if (!firebase.apps.length) firebase.initializeApp(@js((array) config('ricemill.messaging.firebase.web')));
                        if (!this.registration) {
                            this.registration = await navigator.serviceWorker.register('/firebase-messaging-sw.js');
                            firebase.messaging().onMessage((payload) => {
                                const n = payload.notification || {};
                                this.registration.showNotification(n.title || 'Al Mustafa Rice Mills', { body: n.body || '', data: { url: '/admin/my-account' } });
                            });
                        }
                        return this.registration;
                    },
                    async turnOn() {
                        try {
                            this.state = 'working';
                            if (!('serviceWorker' in navigator) || !('Notification' in window)) { this.state = 'unsupported'; return; }
                            if (await Notification.requestPermission() !== 'granted') { this.state = 'denied'; return; }
                            const registration = await this.connect();
                            const token = await firebase.messaging().getToken({ vapidKey: @js((string) config('ricemill.messaging.firebase.vapid_key')), serviceWorkerRegistration: registration });
                            const r = await fetch(@js(route('filament.admin.push-token')), { method: 'POST', credentials: 'same-origin',
                                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                body: JSON.stringify({ token, device: navigator.userAgent.slice(0, 160) }) });
                            this.state = r.ok ? 'on' : 'error';
                        } catch (e) { this.state = 'error'; }
                    },
                }" class="flex flex-wrap items-center gap-3 text-sm">
                <x-filament::button icon="heroicon-o-bell-alert" x-on:click="turnOn()" x-bind:disabled="state === 'working'">Get notifications on this device</x-filament::button>
                <span x-show="state === 'on'" class="text-success-600">Notifications are on for this device.</span>
                <span x-show="state === 'denied'" class="text-danger-600">Notifications are blocked: allow them for this site in the browser settings.</span>
                <span x-show="state === 'unsupported'" class="text-gray-500">This browser cannot show notifications.</span>
                <span x-show="state === 'error'" class="text-danger-600">Could not turn notifications on. Try again.</span>
                <span x-show="state === 'off'" class="text-gray-500">Your vehicles, payments and bills will appear as notifications.</span>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        <div class="overflow-x-auto">
            @if ($tab === 'vehicles')
                <table class="w-full">
                    <thead><tr><th class="{{ $th }}">Date</th><th class="{{ $th }}">Token</th><th class="{{ $th }}">Vehicle</th><th class="{{ $th }}">Item</th><th class="{{ $th }} text-right">Loaded kg</th><th class="{{ $th }} text-right">Empty kg</th><th class="{{ $th }} text-right">Net kg</th><th class="{{ $th }} text-right">Payable kg</th><th class="{{ $th }}">Lab</th><th class="{{ $th }}">Status</th></tr></thead>
                    <tbody>
                        @forelse ($st['vehicles'] as $v)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="{{ $td }}">{{ $v['date']->timezone('Asia/Karachi')->format('d M Y H:i') }}</td>
                                <td class="{{ $td }}">{{ $v['token'] ?? '—' }}</td>
                                <td class="{{ $td }} font-mono">{{ $v['vehicle'] }}</td>
                                <td class="{{ $td }}">{{ $v['item'] }}</td>
                                <td class="{{ $td }} text-right">{{ $kg($v['gross']) }}</td>
                                <td class="{{ $td }} text-right">{{ $kg($v['tare']) }}</td>
                                <td class="{{ $td }} text-right font-semibold">{{ $kg($v['net']) }}</td>
                                <td class="{{ $td }} text-right">{{ $kg($v['payable']) }}</td>
                                <td class="{{ $td }}">{{ $v['lab'] ?? '—' }}</td>
                                <td class="{{ $td }}">{{ $v['status'] }}</td>
                            </tr>
                        @empty
                            <tr><td class="{{ $td }} text-gray-500" colspan="10">No vehicles yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            @elseif ($tab === 'entries')
                <table class="w-full">
                    <thead><tr><th class="{{ $th }}">Date</th><th class="{{ $th }}">Entry</th><th class="{{ $th }}">Details</th><th class="{{ $th }} text-right">You owe (debit)</th><th class="{{ $th }} text-right">We owe (credit)</th><th class="{{ $th }} text-right">Balance</th></tr></thead>
                    <tbody>
                        @forelse ($st['entries'] as $e)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="{{ $td }}">{{ \Carbon\Carbon::parse($e['date'])->format('d M Y') }}</td>
                                <td class="{{ $td }} font-mono">{{ $e['number'] }}</td>
                                <td class="{{ $td }}">{{ $e['what'] }}</td>
                                <td class="{{ $td }} text-right">{{ $e['dr'] ? $money($e['dr']) : '' }}</td>
                                <td class="{{ $td }} text-right">{{ $e['cr'] ? $money($e['cr']) : '' }}</td>
                                <td class="{{ $td }} text-right">{{ $e['balance'] < 0 ? 'We owe '.$money(-$e['balance']) : ($e['balance'] > 0 ? 'You owe '.$money($e['balance']) : 'Settled') }}</td>
                            </tr>
                        @empty
                            <tr><td class="{{ $td }} text-gray-500" colspan="6">No account entries yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            @else
                <table class="w-full">
                    <thead><tr><th class="{{ $th }}">Date</th><th class="{{ $th }}">Bill</th><th class="{{ $th }}">Kind</th><th class="{{ $th }}">Due</th><th class="{{ $th }} text-right">Amount</th><th class="{{ $th }} text-right">Still open</th></tr></thead>
                    <tbody>
                        @forelse ($st['bills'] as $b)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="{{ $td }}">{{ $b['date']->format('d M Y') }}</td>
                                <td class="{{ $td }} font-mono">{{ $b['number'] }}</td>
                                <td class="{{ $td }}">{{ $b['kind'] }}</td>
                                <td class="{{ $td }}">{{ $b['due']?->format('d M Y') ?? '—' }}</td>
                                <td class="{{ $td }} text-right">{{ $money($b['amount']) }}</td>
                                <td class="{{ $td }} text-right">{{ $b['open'] ? $money($b['open']) : 'Paid' }}</td>
                            </tr>
                        @empty
                            <tr><td class="{{ $td }} text-gray-500" colspan="6">No bills yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            @endif
        </div>
    </x-filament::section>
</x-filament-panels::page>

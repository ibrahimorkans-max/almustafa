<x-filament-panels::page>
    @php
        $requests = $this->requests();
        $scannedState = $this->scannedState();
    @endphp

    <x-verify.busy target="pick,deny" :text="__('Checking the number')" />

    <div wire:poll.5s class="space-y-4">
        @if ($scannedState !== null)
            <div class="rounded-lg bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">
                {{ match ($scannedState) {
                    'approved' => __('This sign-in is already approved.'),
                    'denied' => __('This sign-in was refused or replaced by a newer QR code.'),
                    'expired' => __('This QR code is too old: ask them to show a new one.'),
                    default => __('This QR code is not known. Ask them to show a new one.'),
                } }}
            </div>
        @endif

        @forelse ($requests as $r)
            @php
                $who = $r->loginAccount;
                $info = $this->details($r);
                $isNetwork = $r->purpose === \App\Domain\Auth\EmailCode::NEW_NETWORK;
                $place = implode(', ', array_filter([$info['city'] ?? null, $info['region'] ?? null, $info['country'] ?? null]));
            @endphp
            <x-filament::section :heading="$isNetwork ? __('New network: :username', ['username' => $who->username]) : __('First sign-in: :username', ['username' => $who->username])" :icon="$isNetwork ? 'heroicon-o-globe-alt' : 'heroicon-o-user-plus'" class="rmv-enter">
                <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    <div><dt class="text-gray-500">{{ __('Name') }}</dt><dd class="font-medium">{{ $who->person?->full_name ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('Role') }}</dt><dd class="font-medium">{{ $who->role?->name ?? '—' }} · {{ $who->entity?->name }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('E-mail (confirmed)') }}</dt><dd class="font-medium" dir="ltr">{{ $who->email ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('IP address') }}</dt><dd class="font-medium" dir="ltr">{{ $info['ip'] ?? '—' }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('Place') }}</dt><dd class="font-medium">{{ $place !== '' ? $place : '—' }}
                        @if (isset($info['latitude'], $info['longitude']))
                            · <a class="text-primary-600 underline" target="_blank" rel="noopener" href="https://maps.google.com/?q={{ $info['latitude'] }},{{ $info['longitude'] }}">{{ __('map') }}</a>
                        @endif
                    </dd></div>
                    <div><dt class="text-gray-500">{{ __('Device') }}</dt><dd class="font-medium">{{ implode(' · ', array_filter([$info['browser'] ?? null, $info['os'] ?? null])) ?: '—' }}</dd></div>
                    <div><dt class="text-gray-500">{{ __('Asked') }}</dt><dd class="font-medium">{{ $r->created_at->timezone('Asia/Karachi')->format('d M Y, h:i A') }} ({{ $r->created_at->diffForHumans() }})</dd></div>
                </dl>

                <div class="mt-6 border-t border-gray-200 pt-4 dark:border-white/10">
                    <p class="mb-3 text-sm font-semibold">{{ __('Pick the number shown on their screen:') }}</p>
                    <div class="flex flex-wrap items-center gap-3">
                        @foreach ($r->choices as $n)
                            <button type="button" wire:click="pick('{{ $r->id }}', {{ (int) $n }})" wire:loading.attr="disabled"
                                class="rounded-xl border-2 border-primary-600 bg-white font-bold text-primary-700 shadow-sm hover:bg-primary-50 dark:bg-gray-900 dark:text-primary-400"
                                style="min-width:5.5rem;padding:.9rem 1.2rem;font-size:2rem;line-height:1" dir="ltr">{{ $n }}</button>
                        @endforeach
                        <x-filament::button color="danger" outlined icon="heroicon-o-x-mark" wire:click="deny('{{ $r->id }}')"
                            wire:confirm="{{ __('Refuse this sign-in and end it?') }}">{{ __('Not expected — refuse') }}</x-filament::button>
                    </div>
                    <p class="mt-3 text-xs text-gray-500">{{ __('Only approve a sign-in you expect, with the person in front of you or on a call you made. A wrong number refuses it.') }}</p>
                </div>
            </x-filament::section>
        @empty
            <x-filament::section>
                <p class="text-sm text-gray-500">{{ __('No sign-in is waiting for approval. When a new person signs in for the first time, it appears here (and you are notified).') }}</p>
            </x-filament::section>
        @endforelse
    </div>
</x-filament-panels::page>

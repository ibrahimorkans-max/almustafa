<x-filament-panels::page>
    @php
        $info = $this->info();
        $verified = $this->verified();
        $place = implode(', ', array_filter([$info['city'] ?? null, $info['region'] ?? null, $info['country'] ?? null]));
    @endphp

    {{-- Steps: 1 this sign-in, 2 e-mail, 3 your details --}}
    <ol class="grid grid-cols-1 gap-2 sm:grid-cols-3">
        @foreach ([__('This sign-in is recorded'), __('Confirm your e-mail'), __('Your details')] as $i => $step)
            @php $done = $i === 0 || ($i === 1 && $verified); $current = ($i === 1 && ! $verified) || ($i === 2 && $verified); @endphp
            <li class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm {{ $current ? 'border-primary-500 bg-primary-50 font-semibold dark:bg-primary-500/10' : 'border-gray-200 dark:border-white/10' }}">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs {{ $done ? 'bg-success-600 text-white' : 'bg-gray-200 dark:bg-white/10' }}">{{ $done ? '✓' : $i + 1 }}</span>
                {{ $step }}
            </li>
        @endforeach
    </ol>

    <x-filament::section :heading="__('This sign-in')" icon="heroicon-o-map-pin">
        <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
            <div><dt class="text-gray-500">{{ __('IP address') }}</dt><dd class="font-medium" dir="ltr">{{ $info['ip'] ?? '—' }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Place') }}</dt><dd class="font-medium">{{ $place !== '' ? $place : '—' }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Device') }}</dt><dd class="font-medium">{{ implode(' · ', array_filter([$info['browser'] ?? null, $info['os'] ?? null])) ?: '—' }}</dd></div>
            <div><dt class="text-gray-500">{{ __('Location') }}</dt><dd class="font-medium" dir="ltr">
                @if (isset($info['latitude'], $info['longitude']))
                    <a class="text-primary-600 underline" target="_blank" rel="noopener" href="https://maps.google.com/?q={{ $info['latitude'] }},{{ $info['longitude'] }}">{{ $info['latitude'] }}, {{ $info['longitude'] }}</a>
                    @if (($info['location_source'] ?? '') === 'browser' && isset($info['accuracy_m'])) <span class="text-gray-500">(± {{ $info['accuracy_m'] }} m)</span> @endif
                @else
                    <span class="text-gray-500">{{ __('Allow location in the browser when it asks.') }}</span>
                @endif
            </dd></div>
        </dl>
    </x-filament::section>

    @if (! $verified)
        <x-filament::section :heading="__('Confirm your e-mail')" icon="heroicon-o-envelope">
            <form wire:submit="sendEmail" class="space-y-4">
                {{ $this->emailForm }}
                <x-filament::button type="submit" icon="heroicon-o-paper-airplane">{{ $emailSent ? __('Send the e-mail again') : __('Send verification e-mail') }}</x-filament::button>
            </form>
            @if ($emailSent)
                <div wire:poll.4s="checkVerified" class="mt-4 flex items-center gap-2 rounded-lg bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">
                    <x-filament::loading-indicator class="h-5 w-5" />
                    {{ __('Waiting for you to press "Verify my e-mail" in the e-mail… Check the spam folder too.') }}
                </div>
            @endif
        </x-filament::section>
    @else
        <x-filament::section :heading="__('Your details')" icon="heroicon-o-user">
            <p class="mb-4 text-sm text-success-700 dark:text-success-400">✓ {{ __('E-mail confirmed: :email', ['email' => $this->account()->email]) }}</p>
            <form wire:submit="saveDetails" class="space-y-4">
                {{ $this->detailsForm }}
                <x-filament::button type="submit" icon="heroicon-o-check">{{ __('Save and continue') }}</x-filament::button>
            </form>
        </x-filament::section>
    @endif

    {{-- The browser's own position, once allowed (more exact than the IP's city). --}}
    <script>
        document.addEventListener('livewire:initialized', () => {
            if (!navigator.geolocation) return;
            navigator.geolocation.getCurrentPosition(
                (p) => @this.saveLocation(p.coords.latitude, p.coords.longitude, Math.round(p.coords.accuracy)),
                () => {}, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
        }, { once: true });
    </script>
</x-filament-panels::page>

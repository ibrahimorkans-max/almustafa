<x-filament-panels::page>
    @if (count($recoveryCodes) > 0)
        <x-filament::section>
            <x-slot name="heading">Recovery codes</x-slot>
            <x-slot name="description">
                Store these somewhere safe (printed, in a locked drawer). Each code works once if you lose your phone.
                They will not be shown again.
            </x-slot>
            <div class="grid grid-cols-2 gap-2 font-mono text-sm">
                @foreach ($recoveryCodes as $code)
                    <div class="rounded bg-gray-100 px-3 py-2 dark:bg-gray-800">{{ $code }}</div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    @if ($this->isEnrolled())
        <x-filament::section>
            <x-slot name="heading">Two-factor authentication is on</x-slot>
            Every sign-in asks for a code from your authenticator app.
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Set up two-factor authentication</x-slot>
            <x-slot name="description">
                @if ($this->isRequired())
                    Your role requires two-factor authentication. You cannot use the system until it is set up.
                @else
                    Recommended for every account.
                @endif
            </x-slot>

            <ol class="mb-4 list-decimal space-y-1 ps-5 text-sm">
                <li>Install an authenticator app (Google Authenticator, Microsoft Authenticator, Aegis, 2FAS…).</li>
                <li>Scan this QR code, or type the key shown below it.</li>
                <li>Enter the 6-digit code the app shows.</li>
            </ol>

            <div class="mb-2 inline-block rounded bg-white p-2">{!! $this->qrSvg() !!}</div>
            <p class="mb-4 font-mono text-sm">{{ $this->manualKey() }}</p>

            <form wire:submit="confirm" class="max-w-sm space-y-4">
                {{ $this->form }}
                <x-filament::button type="submit">Turn on</x-filament::button>
            </form>
        </x-filament::section>
    @endif
</x-filament-panels::page>

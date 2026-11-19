{{--
    "Prove it is you" (App\Domain\Auth\SecondFactor, page trait ProvesSecondFactor): the person's own passkey,
    authenticator code, recovery code or mobile code. Nobody else is asked.
--}}
@php
    $methods = $this->factorMethods();
    $current = $this->currentFactor();
    $labels = [
        'passkey' => [__('Passkey'), 'heroicon-o-finger-print'],
        'totp' => [__('Authenticator app'), 'heroicon-o-device-phone-mobile'],
        'recovery' => [__('Recovery code'), 'heroicon-o-key'],
        'mobile' => [__('Code to my mobile'), 'heroicon-o-chat-bubble-left-ellipsis'],
    ];
@endphp
<div class="rmv-enter space-y-5" {{ $attributes }}>
    <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('One more check that only you can pass: use something you have. Nobody else has to approve this.') }}</p>

    @if (count($methods) > 1)
        <div class="flex flex-wrap gap-2" role="tablist">
            @foreach ($methods as $m)
                <x-filament::button size="sm" :color="$m === $current ? 'primary' : 'gray'" :outlined="$m !== $current" :icon="$labels[$m][1]"
                    wire:click="chooseFactor('{{ $m }}')" role="tab" :aria-selected="$m === $current ? 'true' : 'false'">{{ $labels[$m][0] }}</x-filament::button>
            @endforeach
        </div>
    @endif

    @if ($current === 'passkey')
        <div class="space-y-3" wire:key="sf-passkey">
            <p class="text-sm">{{ __('Use the fingerprint, face or PIN of your own phone or computer (your passkey). If the passkey is on your phone, the browser shows a QR code to scan with it.') }}</p>
            <x-filament::button type="button" icon="heroicon-o-finger-print" x-on:click="rmPasskeyVerify($wire)">{{ __('Use my passkey') }}</x-filament::button>
        </div>
    @elseif ($current === 'totp')
        <div class="space-y-3" wire:key="sf-totp">
            <x-verify.otp model="factorCode" submit="proveFactorCode" :label="__('Code from your authenticator app')" />
            <x-filament::button wire:click="proveFactorCode" icon="heroicon-o-check-badge">{{ __('Confirm') }}</x-filament::button>
            <p class="text-xs text-gray-500">{{ __('A new code — the one used to sign in does not count again.') }}</p>
        </div>
    @elseif ($current === 'recovery')
        <form wire:submit="proveFactorCode" class="max-w-md space-y-3" wire:key="sf-recovery">
            <label class="block text-sm font-medium" for="sf-recovery-code">{{ __('One of your recovery codes') }}</label>
            <input id="sf-recovery-code" type="text" wire:model="factorText" autocomplete="off" dir="ltr" maxlength="40"
                class="block w-full rounded-lg border-gray-300 font-mono shadow-sm dark:border-white/10 dark:bg-white/5">
            <x-filament::button type="submit" icon="heroicon-o-check-badge">{{ __('Confirm') }}</x-filament::button>
            <p class="text-xs text-gray-500">{{ __('Each recovery code works once. Make new ones later under Security → Two-factor.') }}</p>
        </form>
    @elseif ($current === 'mobile')
        <div class="space-y-3" wire:key="sf-mobile">
            @if (! $factorMobileSent)
                <p class="text-sm">{{ __('We will send a 6-digit code to your mobile :number (WhatsApp or SMS). It works only on this screen.', ['number' => $this->maskedFactorMobile()]) }}</p>
                <x-filament::button wire:click="sendFactorMobile" icon="heroicon-o-chat-bubble-left-ellipsis">{{ __('Send a code to my mobile') }}</x-filament::button>
            @else
                <x-verify.otp model="factorCode" submit="proveFactorMobile" :label="__('Code from your mobile')" />
                <div class="flex flex-wrap gap-3">
                    <x-filament::button wire:click="proveFactorMobile" icon="heroicon-o-check-badge">{{ __('Confirm the code') }}</x-filament::button>
                    <x-filament::button color="gray" wire:click="sendFactorMobile" icon="heroicon-o-arrow-path">{{ __('Send it again') }}</x-filament::button>
                </div>
            @endif
        </div>
    @endif
</div>

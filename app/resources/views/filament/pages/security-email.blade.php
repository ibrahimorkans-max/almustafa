<x-filament-panels::page>
    @php
        $stored = $this->stored();
        $confirmed = $this->confirmed();
        $phone = $this->phone();
        $top = $this->account()->isTopRole();
        $complete = $confirmed && ($phone !== null || ! $top) && ! $codeSent;
        $at = $complete ? 3 : ($confirmed ? 2 : ($codeSent ? 1 : 0));
        $title = match (true) {
            $justConfirmed => __('E-mail confirmed'),
            $complete => __('Your security e-mail is set'),
            $stored !== null && ! $confirmed => __('Confirm the e-mail on your login'),
            default => __('Set the e-mail your security codes go to'),
        };
    @endphp

    <x-verify.panel :steps="[__('Your e-mail'), __('E-mail code'), __('Mobile number')]" :at="$at" :title="$title"
        :subtitle="$stored !== null ? __('Every security code (first sign-in, new network, forgotten password) goes to :email.', ['email' => \App\Domain\Auth\FirstSignIn::masked($stored)]) : __('Without it, a sign-in from a new network has to be approved by someone else, and a forgotten password cannot be reset.')"
        :state="$complete ? 'done' : 'working'" />
    <x-verify.busy target="sendCode,checkCode,savePhone" />

    @if ($top && ($stored === null || $phone === null))
        <div class="rounded-lg bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">
            {{ __('An e-mail and a mobile number are compulsory for the SaaS owner, Owners and Super Admins.') }}
        </div>
    @endif

    <x-filament::section :heading="__('E-mail')" icon="heroicon-o-envelope" class="rmv-enter">
        @if ($confirmed && ! $codeSent)
            <p class="text-sm">✓ {{ __('Confirmed: :email', ['email' => $stored]) }}</p>
            <p class="mt-1 text-xs text-gray-500">{{ __('Only your administrator can change it (Security → Logins).') }}</p>
        @else
            <form wire:submit="sendCode" class="max-w-lg space-y-4">
                {{ $this->emailForm }}
                <x-filament::button type="submit" icon="heroicon-o-paper-airplane" :color="$codeSent ? 'gray' : 'primary'">{{ $codeSent ? __('Send the e-mail again') : __('Send the code') }}</x-filament::button>
            </form>
            @if ($codeSent)
                <form wire:submit="checkCode" class="rmv-enter mt-6 max-w-sm space-y-4 border-t border-gray-200 pt-6 dark:border-white/10" wire:key="se-code">
                    {{ $this->codeForm }}
                    <x-filament::button type="submit" icon="heroicon-o-check-badge">{{ __('Confirm the code') }}</x-filament::button>
                </form>
            @endif
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('Mobile number')" icon="heroicon-o-device-phone-mobile" class="rmv-enter">
        @if ($phone !== null)
            <p class="text-sm" dir="ltr">✓ {{ \App\Support\Values\PakistanIds::formatMobile($phone) ?? $phone }}</p>
            <p class="mt-1 text-xs text-gray-500">{{ __('Only your administrator can change it (Security → Logins).') }}</p>
        @else
            <form wire:submit="savePhone" class="max-w-sm space-y-4">
                {{ $this->phoneForm }}
                <x-filament::button type="submit" icon="heroicon-o-check">{{ __('Save the mobile number') }}</x-filament::button>
            </form>
        @endif
    </x-filament::section>
</x-filament-panels::page>

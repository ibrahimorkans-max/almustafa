<x-filament-panels::page.simple>
    @php
        $at = $done ? 3 : ($username ? 1 : 0);
    @endphp

    <x-verify.panel :steps="[__('Username'), __('E-mail code'), __('New password')]" :at="$at"
        :title="$done ? __('Password changed') : __('Reset your password')"
        :subtitle="$done ? __('Sign in with your new password. Your authenticator code is still asked.') : __('Only for the SaaS owner, mill Owners and Super Admins: the code goes to the e-mail on the login. Everybody else: ask your administrator.')"
        :state="$done ? 'done' : 'working'" />
    <x-verify.busy target="sendCode,resetPassword" />

    @if ($done)
        <x-filament::button tag="a" :href="filament()->getLoginUrl()" icon="heroicon-o-arrow-right-end-on-rectangle" class="w-full">{{ __('Back to sign in') }}</x-filament::button>
    @elseif (! $username)
        <form wire:submit="sendCode" class="rmv-enter space-y-4" wire:key="fp-request">
            {{ $this->requestForm }}
            <x-filament::button type="submit" icon="heroicon-o-paper-airplane" class="w-full">{{ __('Send the code') }}</x-filament::button>
        </form>
    @else
        <form wire:submit="resetPassword" class="rmv-enter space-y-4" wire:key="fp-reset">
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('If this login may reset its password, a 6-digit code was sent to its e-mail. It works only in this browser, for a few minutes.') }}</p>
            <x-verify.otp />
            {{ $this->resetForm }}
            <x-filament::button type="submit" icon="heroicon-o-key" class="w-full">{{ __('Set the new password') }}</x-filament::button>
            <button type="button" wire:click="startOver" class="w-full text-center text-sm text-gray-500 underline">{{ __('Another username') }}</button>
        </form>
    @endif

    @unless ($done)
        <p class="text-center text-sm"><a href="{{ filament()->getLoginUrl() }}" class="text-primary-600 underline">{{ __('Back to sign in') }}</a></p>
    @endunless
</x-filament-panels::page.simple>

<x-filament-panels::page.simple>
    @php
        $stage = match (true) {
            $done => 'done',
            ! $username => 'username',
            $choices === [] => 'code',
            ! $numberOk => 'number',
            ! $factorOk => 'factor',
            default => 'password',
        };
        $at = ['username' => 0, 'code' => 1, 'number' => 1, 'factor' => 2, 'password' => 3, 'done' => 4][$stage];
        $sub = match ($stage) {
            'done' => __('Sign in with your new password. Your authenticator code is still asked.'),
            'code' => __('If this login may reset its password, an e-mail with a 6-digit code and a 2-digit number was sent to it. Type the code first.'),
            'number' => __('Now tap the 2-digit number shown in the same e-mail.'),
            'factor' => __('Use your passkey, authenticator app, a recovery code or a code to your mobile.'),
            'password' => __('Choose your new password.'),
            default => __('Only for the SaaS owner, mill Owners and Super Admins: the codes go to the e-mail on the login. Everybody else: ask your administrator.'),
        };
    @endphp

    <x-verify.panel :steps="[__('Username'), __('E-mail code + number'), __('Prove it is you'), __('New password')]" :at="$at"
        :title="$done ? __('Password changed') : __('Reset your password')" :subtitle="$sub"
        :state="$done ? 'done' : 'working'" />
    <x-verify.busy target="sendCode,checkCode,pickNumber,resetPassword" />

    @if ($stage === 'done')
        <x-filament::button tag="a" :href="filament()->getLoginUrl()" icon="heroicon-o-arrow-right-end-on-rectangle" class="w-full">{{ __('Back to sign in') }}</x-filament::button>
    @elseif ($stage === 'username')
        <form wire:submit="sendCode" class="rmv-enter space-y-4" wire:key="fp-request">
            {{ $this->requestForm }}
            <x-filament::button type="submit" icon="heroicon-o-paper-airplane" class="w-full">{{ __('Send the code') }}</x-filament::button>
        </form>
    @elseif ($stage === 'code')
        <div class="rmv-enter space-y-4" wire:key="fp-code">
            <x-verify.otp submit="checkCode" />
            <x-filament::button wire:click="checkCode" icon="heroicon-o-check-badge" class="w-full">{{ __('Confirm the code') }}</x-filament::button>
            <button type="button" wire:click="startOver" class="w-full text-center text-sm text-gray-500 underline">{{ __('Another username') }}</button>
        </div>
    @elseif ($stage === 'number')
        <div wire:key="fp-number"><x-verify.pick-number :choices="$choices" /></div>
    @elseif ($stage === 'factor')
        <div wire:key="fp-factor"><x-verify.second-factor /></div>
    @else
        <form wire:submit="resetPassword" class="rmv-enter space-y-4" wire:key="fp-reset">
            {{ $this->resetForm }}
            <x-filament::button type="submit" icon="heroicon-o-key" class="w-full">{{ __('Set the new password') }}</x-filament::button>
        </form>
    @endif

    @if ($stage === 'username')
        {{-- No e-mail field here on purpose: anyone who knows a username could put their own address and take the login. --}}
        <div class="rounded-lg bg-gray-50 p-3 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">
            <strong>{{ __('No e-mail on your login, or an old one?') }}</strong>
            {{ __('A code cannot be sent. Ask your administrator to add or update it (Security → Logins); the SaaS owner\'s server administrator can do it with: php artisan auth:contact. Recommended: once signed in, keep it up to date under Security → My security e-mail.') }}
        </div>
    @endif
    @unless ($done)
        <p class="text-center text-sm"><a href="{{ filament()->getLoginUrl() }}" class="text-primary-600 underline">{{ __('Back to sign in') }}</a></p>
    @endunless
</x-filament-panels::page.simple>

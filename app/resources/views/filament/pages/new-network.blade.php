<x-filament-panels::page>
    @php
        $info = $this->info();
        $byEmail = $this->byEmail();
        $addEmail = ! $byEmail && $this->canAddEmail();   // no e-mail on the login yet: it is given here
        // What this sign-in waits for: a new network, a device not approved yet, or both (one code for both).
        $network = $finished ? $addedNetwork !== null : $this->networkHeld();
        $device = $finished ? $approvedDevice !== null : $this->deviceHeld();
        $found = match (true) {
            $network && $device => __('New device and network found'),
            $device => __('New device found'),
            default => __('New network found'),
        };
        // The checks, one after the other: the e-mail code, then the QR code + number (when somebody can approve).
        $stage = $this->stage();
        $withEmail = $byEmail || $addEmail;
        $keyed = array_filter(['found' => $found, 'email' => $withEmail ? __('E-mail code + number') : null,
            'factor' => $this->hasFactorStep() ? __('Prove it is you') : null,
            'approval' => $this->hasApprovalStep() ? __('QR code + number') : null, 'final' => $device ? __('Device approved') : __('Network added')]);
        $steps = array_values($keyed);
        $at = $stage === 'done' ? count($steps) : max(1, (int) array_search($stage, array_keys($keyed), true));
        [$title, $sub] = match (true) {
            $finished && $device => [__('Device approved'), __('This device is now approved for your account. Opening your dashboard…')],
            $finished => [__('Network verified'), __('This network is added to your account. Opening your dashboard…')],
            $stage === 'factor' => [__('Prove it is you'), __('Use your passkey, authenticator app, a recovery code or a code to your mobile. Nobody else has to approve this.')],
            $stage === 'email' && $this->numberPending() => [__('Now tap the number'), __('Tap the 2-digit number shown in the same e-mail.')],
            $stage === 'approval' => [__('One more check: is it you?'), $this->hasOwnDevice()
                ? __('On a device where you are already signed in, open the bell (Is it you signing in?) or scan this QR code, and pick the number shown here.')
                : ($approvalState === null && $this->mobileAvailable() ? __('Type the 6-digit code we send to your mobile.') : __('Someone already signed in scans the QR code and picks your number.'))],
            $addEmail => [$device && ! $network ? __('You are signing in on a new device') : __('You are signing in from a new network'), __('Your login has no e-mail yet. Add yours: a 6-digit code goes there, and from then on all your security codes do.')],
            ! $byEmail => [__('You are signing in from a new network'), __('Someone already signed in scans the QR code and picks your number.')],
            $device && ! $network => [__('You are signing in on a new device'), __('To keep your account safe, confirm it is you with the code we e-mail to :email. Nobody else has to approve this device.', ['email' => $this->maskedEmail()])],
            default => [__('You are signing in from a new network'), __('To keep your account safe, confirm it is you with the code we e-mail to :email.', ['email' => $this->maskedEmail()])],
        };
    @endphp

    <x-verify.panel :steps="$steps" :at="$at" :title="$title" :subtitle="$sub"
        :state="$finished ? 'done' : ($approvalState === 'denied' ? 'failed' : ($stage === 'approval' ? 'waiting' : 'working'))" :leave="$finished" />
    <x-verify.busy target="sendCode,checkCode,pickNumber,sendMobileCode,checkMobileCode" />

    @if ($finished)
        <x-verify.scanline />
        <x-filament::section class="rmv-enter" wire:key="nn-done">
            @if ($addedNetwork)<p class="text-sm">✓ {{ __('Network added: :network', ['network' => $addedNetwork]) }}</p>@endif
            @if ($approvedDevice)<p class="text-sm">✓ {{ __('Device approved: :device', ['device' => $approvedDevice]) }}</p>@endif
            <div x-data x-init="setTimeout(() => window.location.href = @js(\Filament\Facades\Filament::getUrl()), 2200)"></div>
        </x-filament::section>
    @else
        <x-filament::section :heading="__('This sign-in')" icon="heroicon-o-map-pin">
            <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
                <div><dt class="text-gray-500">{{ __('IP address') }}</dt><dd class="font-medium" dir="ltr">{{ $info['ip'] ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">{{ __('Place') }}</dt><dd class="font-medium">{{ ($info['place'] ?? '') !== '' ? $info['place'] : '—' }}</dd></div>
                <div><dt class="text-gray-500">{{ __('Device') }}</dt><dd class="font-medium">{{ implode(' · ', array_filter([$info['browser'] ?? null, $info['os'] ?? null])) ?: '—' }}</dd></div>
            </dl>
        </x-filament::section>

        @if ($stage === 'email')
            <x-filament::section :heading="__('Confirm it is you')" icon="heroicon-o-envelope" class="rmv-enter" wire:key="nn-email">
                @if ($this->numberPending())
                    <div wire:key="nn-number"><x-verify.pick-number :choices="$this->numberChoices()" /></div>
                @elseif ($addEmail)
                    {{-- No e-mail on the login yet: given here once, then kept for every security code. --}}
                    <form wire:submit="sendCode" class="max-w-lg space-y-3">
                        <label class="block text-sm font-medium" for="nn-new-email">{{ __('Your e-mail address') }}</label>
                        <input id="nn-new-email" type="email" wire:model="newEmail" required maxlength="191" autocomplete="email" dir="ltr"
                            class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5">
                        <p class="text-xs text-gray-500">{{ __('Recommended: use an address only you can open, and keep it up to date under Security → My security e-mail. Your administrator can change it later (Security → Logins).') }}</p>
                        <x-filament::button type="submit" icon="heroicon-o-paper-airplane">{{ __('Send the code') }}</x-filament::button>
                    </form>
                @elseif (! $codeSent)
                    <p class="mb-4 text-sm">{{ __('We will e-mail a 6-digit code and a 2-digit number to :email. Type the code, then tap the number. They work only on this screen.', ['email' => $this->maskedEmail()]) }}</p>
                    <x-filament::button wire:click="sendCode" icon="heroicon-o-paper-airplane">{{ __('Send the code') }}</x-filament::button>
                @else
                    <div class="rmv-enter mt-6 space-y-4 border-t border-gray-200 pt-6 dark:border-white/10" wire:key="nn-code">
                    <x-verify.otp submit="checkCode" />
                    <div class="flex flex-wrap gap-3">
                        <x-filament::button wire:click="checkCode" icon="heroicon-o-check-badge">{{ __('Confirm the code') }}</x-filament::button>
                        <x-filament::button color="gray" wire:click="sendCode" icon="heroicon-o-arrow-path">{{ __('Send the e-mail again') }}</x-filament::button>
                    </div>
                </div>
                @endif
            </x-filament::section>
        @elseif ($stage === 'factor')
            <x-filament::section :heading="__('Prove it is you')" icon="heroicon-o-finger-print" class="rmv-enter" wire:key="nn-factor">
                <x-verify.second-factor />
            </x-filament::section>
        @else
            <x-filament::section :heading="__('Approval by someone already signed in')" icon="heroicon-o-qr-code" class="rmv-enter" wire:key="nn-approval">
                <div wire:poll.3s="checkApproval" class="grid grid-cols-1 items-center gap-6 md:grid-cols-2">
                    @if ($approvalState === 'pending' && $approvalToken)
                        <div class="flex flex-col items-center gap-2">
                            <div class="rounded-lg bg-white p-3 shadow-sm ring-1 ring-gray-200" style="line-height:0">{!! $this->approvalQr() !!}</div>
                            <p class="text-xs text-gray-500">{{ __('Scan with a phone that is signed in') }}</p>
                        </div>
                        <div class="space-y-4 text-sm">
                            @if ($this->hasOwnDevice())
                                <p>{{ __('Is it you? On your phone or computer where you are already signed in, scan this QR code (or open the bell → Approve sign-ins) and pick the number below. An Owner or your administrator can approve it too.') }}</p>
                            @else
                                <p>{{ __('Ask an Owner or your administrator to scan this QR code with their phone (or open Security → Approve sign-ins). They will see your name and where you are signing in from.') }}</p>
                            @endif
                            <div>
                                <p class="text-gray-500">{{ __('They pick this number:') }}</p>
                                <p class="mt-1 font-bold text-primary-600" style="font-size:3.5rem;line-height:1;letter-spacing:.2em" dir="ltr">{{ $approvalNumber }}</p>
                            </div>
                            <p class="flex items-center gap-2 text-warning-700 dark:text-warning-400"><x-filament::loading-indicator class="h-5 w-5" /> {{ __('Waiting for the approval… this page moves on by itself.') }}</p>
                        </div>
                    @elseif ($approvalState !== null)
                        <div class="space-y-3 text-sm md:col-span-2">
                            <p class="font-semibold text-danger-600">{{ $approvalState === 'denied' ? __('This sign-in was refused.') : __('The QR code is too old.') }}</p>
                            <x-filament::button wire:click="startApproval" icon="heroicon-o-arrow-path">{{ __('Show a new QR code') }}</x-filament::button>
                        </div>
                    @else
                        <p class="text-sm md:col-span-2">{{ __('You are not signed in on any other device, so nobody can scan a QR code: use the code sent to your mobile below.') }}</p>
                    @endif
                </div>
            </x-filament::section>

            {{-- The alternative: no other device signed in (or no phone at hand) — a code to the mobile on the login. --}}
            @if ($this->mobileAvailable() && $approvalState !== 'denied')
                <x-filament::section :heading="$approvalState === null ? __('Code on your mobile') : __('Not signed in on another device? Use your mobile')" icon="heroicon-o-device-phone-mobile" class="rmv-enter" wire:key="nn-mobile">
                    @if (! $mobileSent)
                        <p class="mb-4 text-sm">{{ __('We will send a 6-digit code to your mobile :number (WhatsApp or SMS). It works only on this screen.', ['number' => $this->maskedMobile()]) }}</p>
                        <x-filament::button wire:click="sendMobileCode" icon="heroicon-o-chat-bubble-left-ellipsis">{{ __('Send a code to my mobile') }}</x-filament::button>
                    @else
                        <div class="space-y-4" wire:key="nn-mobile-code">
                            <x-verify.otp model="mobileOtp" submit="checkMobileCode" :label="__('Code from your mobile')" />
                            <div class="flex flex-wrap gap-3">
                                <x-filament::button wire:click="checkMobileCode" icon="heroicon-o-check-badge">{{ __('Confirm the code') }}</x-filament::button>
                                <x-filament::button color="gray" wire:click="sendMobileCode" icon="heroicon-o-arrow-path">{{ __('Send it again') }}</x-filament::button>
                            </div>
                        </div>
                    @endif
                </x-filament::section>
            @endif
        @endif

        <p class="text-xs text-gray-500">{{ __('Not you, or on a public computer? Sign out at the top of the screen.') }}</p>
    @endif
</x-filament-panels::page>

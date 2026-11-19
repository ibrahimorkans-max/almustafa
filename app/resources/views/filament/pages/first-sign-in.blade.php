<x-filament-panels::page>
    @php
        $info = $this->info();
        $step = $finished ? 'finished' : $this->step();
        $withApproval = $this->hasApprovalStep();
        $place = implode(', ', array_filter([$info['city'] ?? null, $info['region'] ?? null, $info['country'] ?? null]));
        // Steps: this sign-in, e-mail code, approval (when somebody can approve), your details.
        $steps = array_values(array_filter([
            ['key' => 'recorded', 'label' => __('This sign-in is recorded')],
            ['key' => 'email', 'label' => __('E-mail code + number')],
            $this->hasFactorStep() ? ['key' => 'factor', 'label' => __('Prove it is you')] : null,
            $withApproval ? ['key' => 'approval', 'label' => __('Approval (QR + number)')] : null,
            ['key' => 'details', 'label' => __('Your details')],
        ]));
        $order = array_column($steps, 'key');
        $at = $finished ? count($steps) : array_search($step, $order, true);
        [$title, $sub] = match ($step) {
            'email' => [__('Checking it is really you'), $this->numberPending() ? __('Now tap the 2-digit number from the same e-mail.') : __('Confirm your e-mail: type the 6-digit code we send you, then tap its 2-digit number.')],
            'factor' => [__('Prove it is you'), __('Use your passkey, authenticator app, a recovery code or a code to your mobile.')],
            'approval' => [__('Waiting for approval'), __('Someone already signed in scans the QR code and picks your number.')],
            'details' => [__('Almost done'), __('Tell us your name and mobile number.')],
            default => [__('You are verified'), __('Welcome! Opening your dashboard…')],
        };
    @endphp

    <x-verify.panel :steps="array_column($steps, 'label')" :at="$at" :title="$title" :subtitle="$sub"
        :state="$finished ? 'done' : ($step === 'approval' ? 'waiting' : 'working')" :leave="$finished" />
    <x-verify.busy target="sendEmail,checkCode,pickNumber,saveDetails" />

    @if ($finished)
        <x-verify.scanline />
        <div x-data x-init="setTimeout(() => window.location.href = @js(\Filament\Facades\Filament::getUrl()), 2200)"></div>
    @else
    <x-filament::section :heading="__('This sign-in')" icon="heroicon-o-map-pin" collapsible :collapsed="$step !== 'email'">
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

    @if ($step === 'email')
        <x-filament::section :heading="__('Confirm your e-mail')" icon="heroicon-o-envelope" class="rmv-enter" wire:key="fsi-email">
            <form wire:submit="sendEmail" class="space-y-4">
                {{ $this->emailForm }}
                <x-filament::button type="submit" icon="heroicon-o-paper-airplane" :color="$emailSent ? 'gray' : 'primary'">{{ $emailSent ? __('Send the e-mail again') : __('Send the code') }}</x-filament::button>
            </form>
            @if ($this->numberPending())
                <div class="mt-6 border-t border-gray-200 pt-6 dark:border-white/10" wire:key="fsi-number">
                    <x-verify.pick-number :choices="$this->numberChoices()" />
                </div>
            @elseif ($emailSent)
                <div class="rmv-enter mt-6 space-y-4 border-t border-gray-200 pt-6 dark:border-white/10" wire:key="fsi-code">
                    <x-verify.otp submit="checkCode" />
                    <div class="flex flex-wrap gap-3">
                        <x-filament::button wire:click="checkCode" icon="heroicon-o-check-badge">{{ __('Confirm the code') }}</x-filament::button>
                    </div>
                </div>
            @endif
        </x-filament::section>
    @elseif ($step === 'factor')
        <x-filament::section :heading="__('Prove it is you')" icon="heroicon-o-finger-print" class="rmv-enter" wire:key="fsi-factor">
            <x-verify.second-factor />
        </x-filament::section>
    @elseif ($step === 'approval')
        <x-filament::section :heading="__('Approval by someone already signed in')" icon="heroicon-o-qr-code" class="rmv-enter" wire:key="fsi-approval">
            <div wire:poll.3s="checkApproval" class="grid grid-cols-1 items-center gap-6 md:grid-cols-2">
                @if ($approvalState === 'pending' && $approvalToken)
                    <div class="flex flex-col items-center gap-2">
                        <div class="rounded-lg bg-white p-3 shadow-sm ring-1 ring-gray-200" style="line-height:0">{!! $this->approvalQr() !!}</div>
                        <p class="text-xs text-gray-500">{{ __('Scan with a phone that is signed in') }}</p>
                    </div>
                    <div class="space-y-4 text-sm">
                        <p>{{ __('Ask an Owner or your administrator to scan this QR code with their phone (or open Security → Approve sign-ins). They will see your name and where you are signing in from.') }}</p>
                        <div>
                            <p class="text-gray-500">{{ __('They pick this number:') }}</p>
                            <p class="mt-1 font-bold text-primary-600" style="font-size:3.5rem;line-height:1;letter-spacing:.2em" dir="ltr">{{ $approvalNumber }}</p>
                        </div>
                        <p class="flex items-center gap-2 text-warning-700 dark:text-warning-400"><x-filament::loading-indicator class="h-5 w-5" /> {{ __('Waiting for the approval… this page moves on by itself.') }}</p>
                        <p class="text-xs text-gray-500">{{ __('Never tell the number to someone who phones you: only the person approving needs to see it, on your screen.') }}</p>
                    </div>
                @else
                    <div class="space-y-3 text-sm md:col-span-2">
                        <p class="font-semibold text-danger-600">
                            {{ $approvalState === 'denied' ? __('This sign-in was refused.') : __('The QR code is too old.') }}
                        </p>
                        <x-filament::button wire:click="startApproval" icon="heroicon-o-arrow-path">{{ __('Show a new QR code') }}</x-filament::button>
                    </div>
                @endif
            </div>
        </x-filament::section>
    @else
        <x-filament::section :heading="__('Your details')" icon="heroicon-o-user" class="rmv-enter" wire:key="fsi-details">
            <p class="mb-1 text-sm text-success-700 dark:text-success-400">✓ {{ __('E-mail confirmed: :email', ['email' => $this->account()->email]) }}</p>
            @if ($this->account()->first_sign_in_approved_at)
                <p class="mb-4 text-sm text-success-700 dark:text-success-400">✓ {{ __('Sign-in approved.') }}</p>
            @endif
            <form wire:submit="saveDetails" class="mt-4 space-y-4">
                {{ $this->detailsForm }}
                <x-filament::button type="submit" icon="heroicon-o-check">{{ __('Save and continue') }}</x-filament::button>
            </form>
        </x-filament::section>
    @endif
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

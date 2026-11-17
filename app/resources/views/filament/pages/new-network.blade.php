<x-filament-panels::page>
    @php
        $info = $this->info();
        $byEmail = $this->byEmail();
        $steps = [__('New network found'), $byEmail ? __('E-mail code') : __('Approval (QR + number)'), __('Network added')];
        $at = $finished ? 3 : 1;
        [$title, $sub] = $finished
            ? [__('Network verified'), __('This network is added to your account. Opening your dashboard…')]
            : ($byEmail
                ? [__('You are signing in from a new network'), __('To keep your account safe, confirm it is you with the code we e-mail to :email.', ['email' => $this->maskedEmail()])]
                : [__('You are signing in from a new network'), __('Someone already signed in scans the QR code and picks your number.')]);
    @endphp

    <x-verify.panel :steps="$steps" :at="$at" :title="$title" :subtitle="$sub"
        :state="$finished ? 'done' : ($approvalState === 'denied' ? 'failed' : ($byEmail ? 'working' : 'waiting'))" />
    <x-verify.busy target="sendCode,checkCode" />

    @if ($finished)
        <x-filament::section class="rmv-enter" wire:key="nn-done">
            <p class="text-sm">✓ {{ __('Network added: :network', ['network' => $addedNetwork ?? ($info['ip'] ?? '')]) }}</p>
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

        @if ($byEmail)
            <x-filament::section :heading="__('Confirm it is you')" icon="heroicon-o-envelope" class="rmv-enter" wire:key="nn-email">
                @if (! $codeSent)
                    <p class="mb-4 text-sm">{{ __('We will send a 6-digit code to :email. It works only on this screen.', ['email' => $this->maskedEmail()]) }}</p>
                    <x-filament::button wire:click="sendCode" icon="heroicon-o-paper-airplane">{{ __('Send the code') }}</x-filament::button>
                @else
                    <form wire:submit="checkCode" class="max-w-sm space-y-4">
                        {{ $this->codeForm }}
                        <div class="flex flex-wrap gap-3">
                            <x-filament::button type="submit" icon="heroicon-o-check-badge">{{ __('Confirm the code') }}</x-filament::button>
                            <x-filament::button color="gray" wire:click="sendCode" icon="heroicon-o-arrow-path">{{ __('Send the e-mail again') }}</x-filament::button>
                        </div>
                    </form>
                @endif
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
                            <p>{{ __('Ask an Owner or your administrator to scan this QR code with their phone (or open Security → Approve sign-ins). They will see your name and where you are signing in from.') }}</p>
                            <div>
                                <p class="text-gray-500">{{ __('They pick this number:') }}</p>
                                <p class="mt-1 font-bold text-primary-600" style="font-size:3.5rem;line-height:1;letter-spacing:.2em" dir="ltr">{{ $approvalNumber }}</p>
                            </div>
                            <p class="flex items-center gap-2 text-warning-700 dark:text-warning-400"><x-filament::loading-indicator class="h-5 w-5" /> {{ __('Waiting for the approval… this page moves on by itself.') }}</p>
                        </div>
                    @else
                        <div class="space-y-3 text-sm md:col-span-2">
                            <p class="font-semibold text-danger-600">{{ $approvalState === 'denied' ? __('This sign-in was refused.') : __('The QR code is too old.') }}</p>
                            <x-filament::button wire:click="startApproval" icon="heroicon-o-arrow-path">{{ __('Show a new QR code') }}</x-filament::button>
                        </div>
                    @endif
                </div>
            </x-filament::section>
        @endif

        <p class="text-xs text-gray-500">{{ __('Not you, or on a public computer? Sign out from the menu at the top.') }}</p>
    @endif
</x-filament-panels::page>

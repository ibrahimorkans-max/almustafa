<x-filament-panels::page>
    @php($state = $this->state())

    <x-filament::section>
        <x-slot name="heading">{{ __('Now') }}</x-slot>
        <p class="text-sm">
            {{ __('Screen protection is') }} <strong>{{ $state['on'] ? 'ON' : 'OFF' }}</strong>
            {{ $this->millId() === null ? 'for all mills' : 'for this mill' }}{{ $state['enforced'] ? ' (made compulsory by the Owner)' : '' }}.
        </p>
        <p class="mt-2 text-sm text-gray-500">
            When on, every screen carries a faint watermark with the user's name, IP address and the time; the page hides itself
            while the browser window is not in focus (Snipping Tool, screen recorders); Print Screen, printing and Save as PDF give a
            blank page. The Live weight screen stays visible for gate monitors. A phone camera cannot be stopped, but the watermark
            shows who took the picture.
        </p>
    </x-filament::section>

    @if ($this->isOwner())
        <form wire:submit="saveAll">
            <x-filament::section>
                <x-slot name="heading">{{ __('All mills (Owner)') }}</x-slot>
                <div class="space-y-2 text-sm">
                    <label class="flex items-start gap-2"><input type="radio" wire:model="all" value="on" class="mt-1"> <span><strong>{{ __('On') }}</strong>{{ __(': each mill\'s Super Admin may still turn it off for their mill') }}</span></label>
                    <label class="flex items-start gap-2"><input type="radio" wire:model="all" value="enforced" class="mt-1"> <span><strong>{{ __('Compulsory') }}</strong>{{ __(': on in every mill, nobody else can turn it off') }}</span></label>
                    <label class="flex items-start gap-2"><input type="radio" wire:model="all" value="off" class="mt-1"> <span><strong>{{ __('Off') }}</strong>{{ __(': each mill\'s Super Admin may turn it on for their mill') }}</span></label>
                </div>
                <x-filament::button type="submit" class="mt-4">{{ __('Save for all mills') }}</x-filament::button>
            </x-filament::section>
        </form>
    @endif

    @if ($this->millId() !== null)
        <form wire:submit="saveMill">
            <x-filament::section>
                <x-slot name="heading">{{ __('This mill') }}</x-slot>
                @if ($state['enforced'])
                    <p class="text-sm text-gray-500">{{ __('The Owner has made screen protection compulsory for every mill, so it cannot be turned off here.') }}</p>
                @else
                    <div class="space-y-2 text-sm">
                        <label class="flex items-start gap-2"><input type="radio" wire:model="mill" value="inherit" class="mt-1"> <span><strong>{{ __('Same as all mills') }}</strong> (now {{ $state['all'] === 'off' ? 'off' : 'on' }})</span></label>
                        <label class="flex items-start gap-2"><input type="radio" wire:model="mill" value="on" class="mt-1"> <span><strong>{{ __('On') }}</strong> {{ __('for this mill') }}</span></label>
                        <label class="flex items-start gap-2"><input type="radio" wire:model="mill" value="off" class="mt-1"> <span><strong>{{ __('Off') }}</strong> {{ __('for this mill') }}</span></label>
                    </div>
                    <x-filament::button type="submit" class="mt-4">{{ __('Save for this mill') }}</x-filament::button>
                @endif
            </x-filament::section>
        </form>
    @elseif (! $this->isOwner())
        <x-filament::section>
            <p class="text-sm"><a class="text-primary-600 underline" href="{{ $this->switchUrl() }}">{{ __('Choose the working entity first') }}</a>.</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>

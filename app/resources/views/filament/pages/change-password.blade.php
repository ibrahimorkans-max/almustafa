<x-filament-panels::page>
    <x-filament::section>
        @if ($this->mustChange())
            <x-slot name="description">{{ __('You are using a temporary password. Choose your own before continuing.') }}</x-slot>
        @endif
        <form wire:submit="save" class="max-w-md space-y-4">
            {{ $this->form }}
            <p class="text-xs text-gray-500">{{ __('At least 12 characters with upper and lower case letters, a number and a symbol.') }}</p>
            <x-filament::button type="submit">{{ __('Change password') }}</x-filament::button>
        </form>
    </x-filament::section>
</x-filament-panels::page>

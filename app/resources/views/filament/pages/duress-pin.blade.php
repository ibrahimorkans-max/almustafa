<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ $this->hasPin() ? 'Your duress PIN is set' : 'Set a duress PIN' }}</x-slot>
        <x-slot name="description">
            If you are ever forced to sign in, type this PIN in the password field instead of your password.
            The screen only says "Signed out. Please try again later." Meanwhile the owners, Super Admins and
            security contacts are alerted with the time, IP address and device. The system never shows made-up
            figures or fake errors.
        </x-slot>
        <form wire:submit="save" class="max-w-md space-y-4">
            {{ $this->form }}
            <div class="flex gap-2">
                <x-filament::button type="submit">{{ $this->hasPin() ? 'Change PIN' : 'Save PIN' }}</x-filament::button>
                @if ($this->hasPin())
                    <x-filament::button color="gray" wire:click="remove" type="button">Remove PIN (needs current password)</x-filament::button>
                @endif
            </div>
        </form>
    </x-filament::section>
</x-filament-panels::page>

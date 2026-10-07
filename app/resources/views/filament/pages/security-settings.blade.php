<x-filament-panels::page>
    <x-filament::section>
        <form wire:submit="save" class="space-y-4">
            {{ $this->form }}
            <x-filament::button type="submit">Save</x-filament::button>
        </form>
    </x-filament::section>
</x-filament-panels::page>

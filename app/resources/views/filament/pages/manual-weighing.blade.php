<x-filament-panels::page>
    @if ($this->firm() === null)
        <p class="text-sm">Choose one firm in the top bar (not "All firms") to set who may type weights there.</p>
    @else
        <form wire:submit="save" class="space-y-4">
            {{ $this->form }}
            <x-filament::button type="submit" icon="heroicon-o-check">Save</x-filament::button>
        </form>
    @endif
</x-filament-panels::page>

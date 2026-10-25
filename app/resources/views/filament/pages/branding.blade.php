<x-filament-panels::page>
    @if ($this->mill() === null)
        <x-filament::section>
            <p class="text-sm">Choose the mill first: each mill has its own name, logo and details on paper.</p>
            <x-filament::button class="mt-3" tag="a" :href="\App\Filament\Pages\SwitchContext::getUrl()">Choose a mill</x-filament::button>
        </x-filament::section>
    @else
        <p class="text-sm text-gray-500">
            How <strong>{{ $this->mill()->name }}</strong> appears on every slip, invoice, voucher and report. Use "Preview a document" to see it on paper.
        </p>
        <form wire:submit="save" class="space-y-6">
            {{ $this->form }}
            <x-filament::button type="submit">Save</x-filament::button>
        </form>
    @endif
</x-filament-panels::page>

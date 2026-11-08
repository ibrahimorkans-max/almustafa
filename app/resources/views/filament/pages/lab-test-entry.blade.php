<x-filament-panels::page>
    @if ($this->entryId === '')
        <p class="text-sm">Choose the vehicle on <x-filament::link :href="\App\Filament\Pages\LabQueuePage::getUrl()">Lab tests to add</x-filament::link>.</p>
    @else
    <div class="grid gap-4 lg:grid-cols-3">
        <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="mb-2 text-sm font-semibold">The vehicle</h2>
            <dl class="grid grid-cols-3 gap-x-3 gap-y-1.5 text-sm">
                @foreach ($this->details() as $label => $value)
                    <dt class="text-gray-500">{{ $label }}</dt>
                    <dd class="col-span-2 font-medium" dir="auto">{{ $value }}</dd>
                @endforeach
            </dl>
            <div class="mt-3 flex flex-wrap gap-3 text-sm">
                <x-filament::link :href="$this->vehicleUrl()" icon="heroicon-o-truck">Open the vehicle</x-filament::link>
                <x-filament::link :href="\App\Filament\Pages\LabQueuePage::getUrl()" icon="heroicon-o-arrow-left" color="gray">All lab tests to add</x-filament::link>
            </div>
        </section>

        <section class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 lg:col-span-2 dark:bg-gray-900 dark:ring-white/10">
            @if ($this->open())
                <h2 class="mb-1 text-sm font-semibold">{{ $this->hasResult() ? 'Lab result (saved; change it or decide)' : 'Lab results' }}</h2>
                <p class="mb-3 text-xs text-gray-500">Type each result in percent; leave a test empty if it was not done. The verdict by the mill's limits shows below as you type.</p>
                <form wire:submit="save" class="space-y-4">
                    {{ $this->form }}
                    <x-filament::button type="submit" icon="heroicon-o-check" wire:loading.attr="disabled">Save lab test</x-filament::button>
                </form>
            @else
                <p class="text-sm">The lab has already decided on this lot ({{ str_replace('_', ' ', $this->entry()->status) }}). Open the vehicle to see the result.</p>
            @endif
        </section>
    </div>
    @endif
</x-filament-panels::page>

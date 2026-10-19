<x-filament-panels::page>
    @if (! $this->millChosen())
        <x-filament::section>
            <p class="text-sm">Reports are for one mill. <a class="text-primary-600 underline" href="{{ \App\Filament\Pages\SwitchContext::getUrl() }}">Choose the working entity first</a>.</p>
        </x-filament::section>
    @else
        @php
            $catalog = $this->catalog();
            [$rangeFrom, $rangeTo] = $this->range();
            $all = $this->printUrl();
            $allXlsx = $this->printUrl(format: 'xlsx');
            $count = count(array_intersect(array_keys(array_merge(...array_values($catalog))), $selected));
        @endphp
        <x-filament::section>
            <div class="flex flex-wrap items-end gap-3">
                <label class="text-sm">
                    <span class="mb-1 block text-xs font-medium text-gray-500">Period</span>
                    <select wire:model.live="period" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                        @foreach (\App\Filament\Pages\BusinessReportPage::PERIODS as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                @if ($period === 'custom')
                    <label class="text-sm"><span class="mb-1 block text-xs font-medium text-gray-500">From</span>
                        <input type="date" wire:model.live="from" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"></label>
                    <label class="text-sm"><span class="mb-1 block text-xs font-medium text-gray-500">To</span>
                        <input type="date" wire:model.live="to" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5"></label>
                @endif
                @if ($all)
                    <x-filament::button tag="a" :href="$all" target="_blank" icon="heroicon-o-printer" size="lg">Print all selected reports ({{ $count }}) — PDF</x-filament::button>
                    <x-filament::button tag="a" :href="$allXlsx" color="gray" icon="heroicon-o-table-cells">Excel (all selected)</x-filament::button>
                @else
                    <span class="text-sm text-danger-600">{{ $rangeTo->lessThan($rangeFrom) ? 'The end date is before the start date.' : 'Tick at least one report.' }}</span>
                @endif
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-3 text-xs text-gray-500">
                <span>{{ $rangeFrom->format('d M Y') }} – {{ $rangeTo->format('d M Y') }}</span>
                <button type="button" wire:click="selectAll(true)" class="text-primary-600 underline">Tick all</button>
                <button type="button" wire:click="selectAll(false)" class="text-primary-600 underline">Clear</button>
                <span>One PDF: a contents page, then every report from a new page, grouped by category, with signature boxes at the end. Monthly reports (tax, payroll, attendance) print each month of the period, up to 12.</span>
            </div>
        </x-filament::section>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($catalog as $category => $reports)
                @php($allTicked = array_diff(array_keys($reports), $selected) === [])
                <x-filament::section>
                    <x-slot name="heading">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" wire:click="toggleCategory('{{ $category }}')" @checked($allTicked) class="rounded border-gray-300 text-primary-600">
                            {{ \App\Domain\Reports\ReportCatalog::CATEGORIES[$category] }}
                        </label>
                    </x-slot>
                    <x-slot name="headerEnd">
                        <x-filament::link :href="$this->printUrl(category: $category)" target="_blank" icon="heroicon-o-printer" size="sm">Print category</x-filament::link>
                    </x-slot>
                    <ul class="space-y-1.5 text-sm">
                        @foreach ($reports as $key => $title)
                            <li class="flex items-center justify-between gap-2">
                                <label class="flex items-center gap-2">
                                    <input type="checkbox" wire:model.live="selected" value="{{ $key }}" class="rounded border-gray-300 text-primary-600">
                                    {{ $title }}
                                </label>
                                <span class="flex shrink-0 gap-2">
                                    <x-filament::link :href="$this->printUrl($key)" target="_blank" icon="heroicon-o-document-arrow-down" size="sm">PDF</x-filament::link>
                                    <x-filament::link :href="$this->printUrl($key, 'xlsx')" color="gray" icon="heroicon-o-table-cells" size="sm">Excel</x-filament::link>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>

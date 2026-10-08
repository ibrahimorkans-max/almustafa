@php
    [$columns, $rows] = $this->getData();
    $th = 'px-3 py-2 text-left text-xs font-semibold uppercase text-gray-500 whitespace-nowrap';
    $td = 'px-3 py-1.5 text-sm whitespace-nowrap';
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-wrap items-end gap-3">
            <label>
                <span class="text-sm font-medium">Report</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="report">
                        @foreach (\App\Domain\Reports\OperationalReports::REPORTS as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            <label><span class="text-sm font-medium">From</span><x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper></label>
            <label><span class="text-sm font-medium">To</span><x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper></label>
            <span class="text-sm text-gray-500">{{ count($rows) }} rows</span>
        </div>
    </x-filament::section>

    <x-filament::section>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead><tr>@foreach ($columns as $c)<th class="{{ $th }}">{{ $c }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse (array_slice($rows, 0, 500) as $row)
                        <tr class="border-t border-gray-100 dark:border-white/5">@foreach ($row as $v)<td class="{{ $td }}">{{ $v }}</td>@endforeach</tr>
                    @empty
                        <tr><td class="{{ $td }} text-gray-500" colspan="{{ count($columns) }}">Nothing in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
            @if (count($rows) > 500)
                <p class="mt-2 text-xs text-gray-500">Showing the first 500 rows; the CSV export has all of them.</p>
            @endif
        </div>
    </x-filament::section>
</x-filament-panels::page>

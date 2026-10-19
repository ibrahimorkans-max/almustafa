@php
    $th = 'px-3 py-2 text-left text-xs font-semibold uppercase text-gray-500';
    $td = 'px-3 py-1.5 text-sm';
@endphp
<x-filament-panels::page>
    {{-- Read here only: printing the page gives a blank sheet. --}}
    <style>@media print { .fi-layout, .fi-main, body > * { display: none !important; } body::after { content: 'This page cannot be printed.'; } }</style>

    @if ($this->panicAction->isVisible())
        <div class="flex justify-end">{{ $this->panicAction }}</div>
    @endif

    <x-filament::section>
        <form wire:submit="search" class="flex flex-wrap items-end gap-3">
            <label>
                <span class="text-sm font-medium">Closed from</span>
                <x-filament::input.wrapper><x-filament::input type="date" wire:model="from" /></x-filament::input.wrapper>
            </label>
            <label>
                <span class="text-sm font-medium">to</span>
                <x-filament::input.wrapper><x-filament::input type="date" wire:model="to" /></x-filament::input.wrapper>
            </label>
            <label>
                <span class="text-sm font-medium">Type</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model="type">
                        <option value="">All</option>
                        @foreach (\App\Domain\Archive\ArchiveService::TYPES as $key => $def)
                            <option value="{{ $key }}">{{ $def[3] }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            <label class="min-w-[14rem] flex-1">
                <span class="text-sm font-medium">Number, vehicle or party (optional)</span>
                <x-filament::input.wrapper><x-filament::input type="text" wire:model="term" placeholder="e.g. AMR/G/2026-27/0012 or MNA211234" /></x-filament::input.wrapper>
            </label>
            <x-filament::button type="submit" icon="heroicon-o-magnifying-glass">Show records</x-filament::button>
        </form>
        <p class="mt-2 text-xs text-gray-500">
            Closed records older than {{ app(\App\Domain\Settings\SettingsService::class)->archiveRetentionMonths() }} months are kept here. They can be read and restored, not printed or downloaded.
            Restoring returns a record to the working screens. Accounts, stock and tax totals are not affected.
        </p>
    </x-filament::section>

    @if ($this->canSetRetention())
        <x-filament::section>
            <x-slot name="heading">When closed records move here</x-slot>
            <form wire:submit="saveRetention" class="flex flex-wrap items-end gap-3">
                <label>
                    <span class="text-sm font-medium">Months after closing</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="retention">
                            @foreach (\App\Domain\Settings\SettingsService::ARCHIVE_RETENTION_CHOICES as $months)
                                <option value="{{ $months }}">{{ $months }} months{{ $months % 12 === 0 ? ' ('.($months / 12).' year'.($months > 12 ? 's' : '').')' : '' }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                <x-filament::button type="submit" color="gray">Save</x-filament::button>
            </form>
            <p class="mt-2 text-xs text-gray-500">
                For all mills. Only closed records move (completed saudas, vehicles that left, closed batches, issued requisitions, settled claims),
                on the 1st of each month. Accounts, stock and tax totals are not affected. The change is recorded in the audit log.
            </p>
        </x-filament::section>
    @endif

    @if ($searched)
        <x-filament::section>
            <x-slot name="heading">{{ count($results) }} record(s){{ count($results) >= 500 ? ' (first 500, narrow the period)' : '' }}</x-slot>
            <x-slot name="headerEnd">{{ $this->restorePeriodAction }}</x-slot>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead><tr><th class="{{ $th }}">Type</th><th class="{{ $th }}">Number</th><th class="{{ $th }}">Details</th><th class="{{ $th }}">Closed</th><th class="{{ $th }}">Kept since</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($results as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="{{ $td }}">{{ $row['label'] }}</td>
                                <td class="{{ $td }} font-mono">{{ $row['number'] }}</td>
                                <td class="{{ $td }}">{{ $row['detail'] }}</td>
                                <td class="{{ $td }}">{{ $row['closed_at'] !== '' ? \Carbon\Carbon::parse($row['closed_at'])->timezone('Asia/Karachi')->format('d M Y') : '—' }}</td>
                                <td class="{{ $td }}">{{ \Carbon\Carbon::parse($row['archived_at'])->timezone('Asia/Karachi')->format('d M Y') }}</td>
                                <td class="{{ $td }} whitespace-nowrap text-right">
                                    {{ ($this->viewAction)(['type' => $row['type'], 'id' => $row['id']]) }}
                                    {{ ($this->restoreAction)(['type' => $row['type'], 'id' => $row['id']]) }}
                                </td>
                            </tr>
                        @empty
                            <tr><td class="{{ $td }} text-gray-500" colspan="6">No records in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>

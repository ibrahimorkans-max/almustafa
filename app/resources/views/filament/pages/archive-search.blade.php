@php
    $th = 'px-3 py-2 text-left text-xs font-semibold uppercase text-gray-500';
    $td = 'px-3 py-1.5 text-sm';
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <form wire:submit="search" class="flex flex-wrap items-end gap-3">
            <label class="flex-1">
                <span class="text-sm font-medium">Number, vehicle or party</span>
                <x-filament::input.wrapper><x-filament::input type="text" wire:model="term" placeholder="e.g. AMR/G/2026-27/0012 or MNA211234" /></x-filament::input.wrapper>
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
            <x-filament::button type="submit" icon="heroicon-o-magnifying-glass">Search</x-filament::button>
        </form>
        <p class="mt-2 text-xs text-gray-500">Closed records are archived {{ config('ricemill.archive.retention_months') }} months after closing. Nothing is ever deleted; books, stock ledger and audit log are not archived.</p>
    </x-filament::section>

    @if ($searched)
        <x-filament::section>
            <table class="w-full">
                <thead><tr><th class="{{ $th }}">Type</th><th class="{{ $th }}">Number</th><th class="{{ $th }}">Details</th><th class="{{ $th }}">Archived</th><th></th></tr></thead>
                <tbody>
                    @forelse ($results as $row)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="{{ $td }}">{{ $row['label'] }}</td>
                            <td class="{{ $td }} font-mono">{{ $row['number'] }}</td>
                            <td class="{{ $td }}">{{ $row['detail'] }}</td>
                            <td class="{{ $td }}">{{ \Carbon\Carbon::parse($row['archived_at'])->format('d M Y') }}</td>
                            <td class="{{ $td }} text-right">{{ ($this->restoreAction)(['type' => $row['type'], 'id' => $row['id']]) }}</td>
                        </tr>
                    @empty
                        <tr><td class="{{ $td }} text-gray-500" colspan="5">Nothing archived matches.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>

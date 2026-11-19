<x-filament-panels::page>
    @php
        $rows = $this->rows();
        $owedToUs = array_sum(array_map(fn ($r) => max(0, $r['balance_paise']), $rows));
        $weOwe = array_sum(array_map(fn ($r) => max(0, -$r['balance_paise']), $rows));
        $rs = fn (int $p) => \App\Support\Values\Money::paise($p)->format();
    @endphp

    <div class="flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-medium text-gray-500" for="pb-search">{{ __('Search party, code or phone') }}</label>
            <input id="pb-search" type="search" wire:model.live.debounce.400ms="search" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500" for="pb-show">{{ __('Show') }}</label>
            <select id="pb-show" wire:model.live="show" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                <option value="open">{{ __('With a balance') }}</option>
                <option value="owe_us">{{ __('They owe the mill (receivable)') }}</option>
                <option value="we_owe">{{ __('The mill owes them (payable)') }}</option>
                <option value="all">{{ __('All parties') }}</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500" for="pb-asof">{{ __('As on') }}</label>
            <input id="pb-asof" type="date" wire:model.live="asOf" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-filament::section><p class="text-xs text-gray-500">{{ __('They owe the mill') }}</p><p class="text-2xl font-bold text-success-600" dir="ltr">{{ $rs($owedToUs) }}</p></x-filament::section>
        <x-filament::section><p class="text-xs text-gray-500">{{ __('The mill owes them') }}</p><p class="text-2xl font-bold text-danger-600" dir="ltr">{{ $rs($weOwe) }}</p></x-filament::section>
        <x-filament::section><p class="text-xs text-gray-500">{{ __('Parties listed') }}</p><p class="text-2xl font-bold" dir="ltr">{{ count($rows) }}</p></x-filament::section>
    </div>

    <x-filament::section>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="border-b text-start text-xs text-gray-500 dark:border-white/10">
                    <th class="p-2 text-start">{{ __('Party') }}</th><th class="p-2 text-start">{{ __('Phone') }}</th>
                    <th class="p-2 text-end">{{ __('They owe the mill') }}</th><th class="p-2 text-end">{{ __('The mill owes them') }}</th>
                    <th class="p-2 text-start">{{ __('Last money in') }}</th><th class="p-2 text-start">{{ __('Last money out') }}</th>
                    <th class="p-2 text-end">{{ __('Unpaid bills') }}</th><th class="p-2"></th>
                </tr></thead>
                <tbody>
                @forelse ($rows as $r)
                    <tr class="border-b dark:border-white/5" wire:key="pb-{{ $r['party_id'] }}">
                        <td class="p-2 font-medium">{{ $r['name'] }} <span class="text-xs text-gray-500">{{ $r['code'] }}</span></td>
                        <td class="p-2" dir="ltr">{{ $r['phone'] ?? '—' }}</td>
                        <td class="p-2 text-end font-semibold text-success-700" dir="ltr">{{ $r['balance_paise'] > 0 ? $rs($r['balance_paise']) : '' }}</td>
                        <td class="p-2 text-end font-semibold text-danger-700" dir="ltr">{{ $r['balance_paise'] < 0 ? $rs(-$r['balance_paise']) : '' }}</td>
                        <td class="p-2">{{ $r['last_in'] ? \Carbon\Carbon::parse($r['last_in'])->format('d M Y') : '—' }}</td>
                        <td class="p-2">{{ $r['last_out'] ? \Carbon\Carbon::parse($r['last_out'])->format('d M Y') : '—' }}</td>
                        <td class="p-2 text-end">{{ $r['unpaid_bills'] ?: '—' }}</td>
                        <td class="p-2 text-end"><a class="text-primary-600 underline" href="{{ \App\Filament\Pages\PartyStatementPage::getUrl(['party' => $r['party_id']]) }}">{{ __('Statement') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="p-4 text-center text-gray-500">{{ __('No party with a balance.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>

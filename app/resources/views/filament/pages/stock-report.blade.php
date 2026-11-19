<x-filament-panels::page>
    @php
        $rows = $this->rows();
        $kg = fn (string $v) => number_format((float) $v, 2);
        $md = fn (string $v) => bcdiv($v, '40', 2);
        $labels = \App\Filament\Resources\Inventory\StockBalanceResource::CATEGORY_LABELS;
        $byCategory = collect($rows)->groupBy('category');
    @endphp

    <div class="flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-medium text-gray-500" for="sr-from">{{ __('From') }}</label>
            <input id="sr-from" type="date" wire:model.live="from" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500" for="sr-to">{{ __('To') }}</label>
            <input id="sr-to" type="date" wire:model.live="to" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500" for="sr-cat">{{ __('Stock') }}</label>
            <select id="sr-cat" wire:model.live="category" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                <option value="">{{ __('Paddy, rice and everything') }}</option>
                @foreach ($labels as $k => $v)<option value="{{ $k }}">{{ __($v) }}</option>@endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500" for="sr-godown">{{ __('Godown') }}</label>
            <select id="sr-godown" wire:model.live="godown" class="rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                <option value="">{{ __('All godowns') }}</option>
                @foreach (\App\Filament\Resources\Inventory\RequisitionResource::godownOptions() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
        </div>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="byGodown" class="rounded"> {{ __('Each godown separately') }}</label>
    </div>

    @forelse ($byCategory as $cat => $list)
        <x-filament::section :heading="__($labels[$cat] ?? $cat)">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="border-b text-xs text-gray-500 dark:border-white/10">
                        <th class="p-2 text-start">{{ __('Product') }}</th>
                        @if ($byGodown)<th class="p-2 text-start">{{ __('Godown') }}</th>@endif
                        <th class="p-2 text-end">{{ __('Opening (kg)') }}</th><th class="p-2 text-end">{{ __('In (kg)') }}</th>
                        <th class="p-2 text-end">{{ __('Out (kg)') }}</th><th class="p-2 text-end">{{ __('Closing (kg)') }}</th><th class="p-2 text-end">{{ __('Closing (maund)') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($list as $r)
                        <tr class="border-b dark:border-white/5">
                            <td class="p-2 font-medium">{{ $r['product'] }}</td>
                            @if ($byGodown)<td class="p-2">{{ $r['godown'] ?? '—' }}</td>@endif
                            <td class="p-2 text-end" dir="ltr">{{ $kg($r['opening_kg']) }}</td>
                            <td class="p-2 text-end text-success-700" dir="ltr">{{ $kg($r['in_kg']) }}</td>
                            <td class="p-2 text-end text-danger-700" dir="ltr">{{ $kg($r['out_kg']) }}</td>
                            <td class="p-2 text-end font-semibold" dir="ltr">{{ $kg($r['closing_kg']) }}</td>
                            <td class="p-2 text-end" dir="ltr">{{ $md($r['closing_kg']) }}</td>
                        </tr>
                    @endforeach
                    @php
                        $sum = fn (string $k) => array_reduce($list->all(), fn ($c, $r) => bcadd($c, $r[$k], 3), '0');
                    @endphp
                    <tr class="font-bold">
                        <td class="p-2" @if ($byGodown) colspan="2" @endif>{{ __('Total') }}</td>
                        <td class="p-2 text-end" dir="ltr">{{ $kg($sum('opening_kg')) }}</td>
                        <td class="p-2 text-end" dir="ltr">{{ $kg($sum('in_kg')) }}</td>
                        <td class="p-2 text-end" dir="ltr">{{ $kg($sum('out_kg')) }}</td>
                        <td class="p-2 text-end" dir="ltr">{{ $kg($sum('closing_kg')) }}</td>
                        <td class="p-2 text-end" dir="ltr">{{ $md($sum('closing_kg')) }}</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @empty
        <x-filament::section><p class="text-sm text-gray-500">{{ __('No stock movement in these dates.') }}</p></x-filament::section>
    @endforelse
    <p class="text-xs text-gray-500">{{ __('From the stock ledger: every gate receipt, quick bill, dispatch, milling input and output, transfer and adjustment. 1 maund = 40 kg.') }}</p>
</x-filament-panels::page>

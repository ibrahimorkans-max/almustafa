<x-filament-panels::page>
    @php
        $a = $this->account();
        $sel = 'block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5';
        $rs = fn (int $p) => $p === 0 ? '' : \App\Support\Values\Money::paise($p)->format();
    @endphp

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <label class="text-sm lg:col-span-2"><span class="text-gray-500">Party</span>
            <select wire:model.live="party" class="{{ $sel }}">
                <option value="">Choose a party…</option>
                @foreach ($this->parties() as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select></label>
        <label class="text-sm"><span class="text-gray-500">Period</span>
            <select wire:model.live="period" class="{{ $sel }}">@foreach ($this->periods() as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
        <label class="text-sm"><span class="text-gray-500">Show</span>
            <select wire:model.live="direction" class="{{ $sel }}"><option value="">All transactions</option><option value="out">Debits (money out / charged)</option><option value="in">Credits (money in / billed)</option></select></label>
        <label class="text-sm"><span class="text-gray-500">Search</span>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Number, description…" class="{{ $sel }}"></label>
        @if ($period === 'custom')
            <label class="text-sm"><span class="text-gray-500">From</span><input type="date" wire:model.live="from" class="{{ $sel }}"></label>
            <label class="text-sm"><span class="text-gray-500">To</span><input type="date" wire:model.live="to" class="{{ $sel }}"></label>
        @endif
    </div>

    @if ($a === null)
        <x-filament::section><p class="text-sm text-gray-500">Choose a party to see every entry on its account, like a bank statement.</p></x-filament::section>
    @else
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([['Opening balance', $this->balance($a['opening']), 'heroicon-o-flag'], ['Total debits', $rs($a['total_dr']) ?: 'Rs 0', 'heroicon-o-arrow-up-right'],
                ['Total credits', $rs($a['total_cr']) ?: 'Rs 0', 'heroicon-o-arrow-down-left'], ['Closing balance', $this->balance($a['closing']), 'heroicon-o-scale']] as [$label, $value, $icon])
                <x-filament::section compact>
                    <div class="flex items-center gap-3">
                        <x-filament::icon :icon="$icon" class="h-6 w-6 text-primary-500" />
                        <div><div class="text-xs text-gray-500">{{ $label }}</div><div class="font-mono text-lg font-semibold">{{ $value }}</div></div>
                    </div>
                </x-filament::section>
            @endforeach
        </div>
        <p class="-mt-2 text-sm"><span class="font-medium">{{ $a['standing'] }}</span>
            <span class="text-xs text-gray-500">· {{ $a['from']->format('d M Y') }} – {{ $a['to']->format('d M Y') }} · Dr: the party owes the mill · Cr: the mill owes the party</span></p>

        <x-filament::section>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase text-gray-500 dark:border-white/10">
                            <th class="py-2 pr-3">Date</th><th class="pr-3">Number</th><th class="pr-3">Type</th><th class="pr-3">Description</th>
                            <th class="pr-3 text-right">Debit</th><th class="pr-3 text-right">Credit</th><th class="text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($a['lines'] as $l)
                            <tr class="border-b border-gray-100 align-top hover:bg-gray-50 dark:border-white/5 dark:hover:bg-white/5">
                                <td class="py-2 pr-3 whitespace-nowrap">{{ \Carbon\CarbonImmutable::parse($l['date'])->format('d M Y') }}</td>
                                <td class="pr-3 whitespace-nowrap font-mono text-xs">
                                    @if ($l['voucher_id'])
                                        <a href="{{ \App\Filament\Resources\Accounts\VoucherResource::getUrl('view', ['record' => $l['voucher_id']]) }}" class="text-primary-600 hover:underline">{{ $l['number'] }}</a>
                                    @else
                                        {{ $l['number'] }}
                                    @endif
                                </td>
                                <td class="pr-3"><x-filament::badge size="sm" :color="match ($l['type']) { 'payment', 'sales', 'debit_note' => 'danger', 'receipt', 'purchase', 'credit_note' => 'success', default => 'gray' }">{{ $l['kind'] }}</x-filament::badge></td>
                                <td class="pr-3">{{ $l['narration'] }}@if ($l['memo'])<div class="text-xs text-gray-500">{{ $l['memo'] }}</div>@endif</td>
                                <td class="pr-3 text-right font-mono text-danger-600 whitespace-nowrap">{{ $rs((int) $l['dr']) }}</td>
                                <td class="pr-3 text-right font-mono text-success-600 whitespace-nowrap">{{ $rs((int) $l['cr']) }}</td>
                                <td class="text-right font-mono whitespace-nowrap">{{ $this->balance((int) $l['balance']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-4 text-center text-gray-500">No transactions in this period.</td></tr>
                        @endforelse
                    </tbody>
                    @if ($a['lines'] !== [])
                        <tfoot>
                            <tr class="border-t-2 border-gray-300 font-semibold dark:border-white/20">
                                <td colspan="4" class="py-2">Totals ({{ count($a['lines']) }} entries)</td>
                                <td class="pr-3 text-right font-mono">{{ $rs($a['total_dr']) }}</td><td class="pr-3 text-right font-mono">{{ $rs($a['total_cr']) }}</td>
                                <td class="text-right font-mono">{{ $this->balance($a['closing']) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
            <p class="mt-2 text-xs text-gray-500">Newest first. The balance is after each entry, over all the party's entries. Click a number to open its voucher.</p>
        </x-filament::section>
    @endif
    <x-filament-actions::modals />
</x-filament-panels::page>

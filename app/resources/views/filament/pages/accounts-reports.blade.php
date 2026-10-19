@php
    use App\Filament\Support\Units;
    $r = $this->getReportData();
    $money = fn (int $p) => Units::paiseToRupees($p);
    $bal = fn (int $p) => $p === 0 ? '0.00' : Units::paiseToRupees(abs($p)).($p > 0 ? ' Dr' : ' Cr');
    $th = 'px-3 py-2 text-left text-xs font-semibold uppercase text-gray-500';
    $td = 'px-3 py-1.5 text-sm';
@endphp
<x-filament-panels::page>
    <x-filament::section>{{ $this->form }}</x-filament::section>

    <x-filament::section>
        @switch($this->data['report'] ?? 'trial_balance')
            @case('ledger')
                @if (! $r['account'])
                    <p class="text-sm text-gray-500">Choose a ledger.</p>
                @else
                    <h3 class="mb-2 font-semibold">{{ $r['account']->displayName() }}</h3>
                    <table class="w-full">
                        <thead><tr><th class="{{ $th }}">Date</th><th class="{{ $th }}">Voucher</th><th class="{{ $th }}">Particulars</th><th class="{{ $th }} text-right">Debit</th><th class="{{ $th }} text-right">Credit</th><th class="{{ $th }} text-right">Balance</th></tr></thead>
                        <tbody>
                            <tr><td class="{{ $td }}" colspan="5">Opening balance</td><td class="{{ $td }} text-right">{{ $bal($r['statement']['opening']) }}</td></tr>
                            @foreach ($r['statement']['lines'] as $l)
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td class="{{ $td }}">{{ \Carbon\Carbon::parse($l['date'])->format('d M Y') }}</td>
                                    <td class="{{ $td }} font-mono">{{ $l['number'] }}</td>
                                    <td class="{{ $td }}">{{ $l['memo'] ?? $l['narration'] }}</td>
                                    <td class="{{ $td }} text-right">{{ $l['dr'] ? $money($l['dr']) : '' }}</td>
                                    <td class="{{ $td }} text-right">{{ $l['cr'] ? $money($l['cr']) : '' }}</td>
                                    <td class="{{ $td }} text-right">{{ $bal($l['balance']) }}</td>
                                </tr>
                            @endforeach
                            <tr class="border-t font-semibold"><td class="{{ $td }}" colspan="5">Closing balance</td><td class="{{ $td }} text-right">{{ $bal($r['statement']['closing']) }}</td></tr>
                        </tbody>
                    </table>
                @endif
                @break

            @case('day_book')
                @forelse ($r['vouchers'] as $v)
                    <div class="mb-4">
                        <div class="text-sm font-semibold">{{ $v['number'] }} · {{ $v['type'] }} · {{ $money($v['total']) }}</div>
                        <div class="text-xs text-gray-500">{{ $v['narration'] }}</div>
                        <table class="w-full">
                            @foreach ($v['lines'] as $l)
                                <tr><td class="{{ $td }}">{{ $l['account'] }}</td><td class="{{ $td }} text-right">{{ $l['dr'] ? $money($l['dr']).' Dr' : '' }}</td><td class="{{ $td }} text-right">{{ $l['cr'] ? $money($l['cr']).' Cr' : '' }}</td></tr>
                            @endforeach
                        </table>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">No vouchers on this day.</p>
                @endforelse
                @break

            @case('ageing')
                <table class="w-full">
                    <thead><tr><th class="{{ $th }}">Bill</th><th class="{{ $th }}">Party</th><th class="{{ $th }}">Due</th><th class="{{ $th }}">Age</th><th class="{{ $th }} text-right">Bill amount</th><th class="{{ $th }} text-right">Paid</th><th class="{{ $th }} text-right">Awaiting approval</th><th class="{{ $th }} text-right">Outstanding</th></tr></thead>
                    <tbody>
                        @forelse ($r['rows'] as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="{{ $td }} font-mono">{{ $row['number'] }}</td><td class="{{ $td }}">{{ $row['party'] }}</td>
                                <td class="{{ $td }}">{{ \Carbon\Carbon::parse($row['due_date'])->format('d M Y') }}</td><td class="{{ $td }}">{{ $row['bucket'] }}</td>
                                <td class="{{ $td }} text-right">{{ $money($row['net']) }}</td><td class="{{ $td }} text-right">{{ $money($row['paid']) }}</td>
                                <td class="{{ $td }} text-right">{{ $money($row['requested']) }}</td><td class="{{ $td }} text-right font-semibold">{{ $money($row['outstanding']) }}</td>
                            </tr>
                        @empty
                            <tr><td class="{{ $td }} text-gray-500" colspan="8">Nothing outstanding.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                @break

            @case('receivables')
                <table class="w-full">
                    <thead><tr><th class="{{ $th }}">Invoice</th><th class="{{ $th }}">Buyer</th><th class="{{ $th }}">Due</th><th class="{{ $th }}">Age</th><th class="{{ $th }} text-right">Invoice total</th><th class="{{ $th }} text-right">Received</th><th class="{{ $th }} text-right">Outstanding</th></tr></thead>
                    <tbody>
                        @forelse ($r['rows'] as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="{{ $td }} font-mono">{{ $row['number'] }}</td><td class="{{ $td }}">{{ $row['party'] }}</td>
                                <td class="{{ $td }}">{{ \Carbon\Carbon::parse($row['due_date'])->format('d M Y') }}</td><td class="{{ $td }}">{{ $row['bucket'] }}</td>
                                <td class="{{ $td }} text-right">{{ $money($row['total']) }}</td><td class="{{ $td }} text-right">{{ $money($row['received']) }}</td>
                                <td class="{{ $td }} text-right font-semibold">{{ $money($row['outstanding']) }}</td>
                            </tr>
                        @empty
                            <tr><td class="{{ $td }} text-gray-500" colspan="7">Nothing outstanding.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                @break

            @case('stock_value')
                <table class="w-full">
                    <thead><tr><th class="{{ $th }}">Product</th><th class="{{ $th }}">Godown</th><th class="{{ $th }} text-right">kg</th><th class="{{ $th }} text-right">Avg cost / kg</th><th class="{{ $th }} text-right">Value</th></tr></thead>
                    <tbody>
                        @foreach ($r['rows'] as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="{{ $td }}">{{ $r['products'][$row['product_id']] ?? '' }}</td><td class="{{ $td }}">{{ $r['godowns'][$row['godown_id']] ?? '' }}</td>
                                <td class="{{ $td }} text-right font-mono">{{ $row['quantity_kg'] }}</td>
                                <td class="{{ $td }} text-right">{{ \App\Support\Values\Money::rupeesText(((float) $row['avg_paise_per_kg']) / 100) }}</td>
                                <td class="{{ $td }} text-right">{{ $money($row['value_paise']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="border-t font-semibold"><td class="{{ $td }}" colspan="4">Total</td><td class="{{ $td }} text-right">{{ $money(array_sum(array_column($r['rows'], 'value_paise'))) }}</td></tr>
                    </tbody>
                </table>
                @break

            @default
                <table class="w-full">
                    <thead><tr><th class="{{ $th }}">Ledger</th><th class="{{ $th }}">Group</th><th class="{{ $th }} text-right">Debit</th><th class="{{ $th }} text-right">Credit</th></tr></thead>
                    <tbody>
                        @foreach ($r['tb']['rows'] as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="{{ $td }}">{{ $row['name'] }}</td><td class="{{ $td }}">{{ $row['group'] }}</td>
                                <td class="{{ $td }} text-right">{{ $row['dr'] ? $money($row['dr']) : '' }}</td><td class="{{ $td }} text-right">{{ $row['cr'] ? $money($row['cr']) : '' }}</td>
                            </tr>
                        @endforeach
                        <tr class="border-t font-semibold"><td class="{{ $td }}" colspan="2">Total</td><td class="{{ $td }} text-right">{{ $money($r['tb']['total_dr']) }}</td><td class="{{ $td }} text-right">{{ $money($r['tb']['total_cr']) }}</td></tr>
                    </tbody>
                </table>
        @endswitch
    </x-filament::section>
</x-filament-panels::page>

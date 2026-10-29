@php
    use App\Filament\Support\Units;
    use App\Domain\Accounts\PartyStatementReport;
    use App\Models\Accounts\StatementShare;
    $s = $this->getStatement();
    $view = $this->data['view'] ?? 'detailed';
    $money = fn (?int $p) => $p === null ? '' : Units::paiseToRupees($p);
    $bal = fn (int $p) => $p === 0 ? '0.00' : Units::paiseToRupees(abs($p)).($p > 0 ? ' Dr' : ' Cr');
    $kg = fn ($v) => $v === null || $v === '' ? '' : number_format((float) $v, 0);
    $d = fn (?string $date) => $date ? \Carbon\Carbon::parse($date)->format('d M Y') : '';
    $th = 'px-3 py-2 text-left text-xs font-semibold uppercase text-gray-500';
    $thr = $th.' text-right';
    $td = 'px-3 py-1.5 text-sm';
    $tdr = $td.' text-right tabular-nums';
    $row = 'border-t border-gray-100 dark:border-white/5';
@endphp
<x-filament-panels::page>
    <x-filament::section>{{ $this->form }}</x-filament::section>

    @if ($s === null)
        <x-filament::section><p class="text-sm text-gray-500">Choose a party.</p></x-filament::section>
    @else
        <x-filament::section>
            <div class="grid gap-3 sm:grid-cols-4">
                <div><div class="text-xs text-gray-500">Party</div><div class="font-semibold">{{ $s['party']['name'] }}</div>
                    <div class="font-mono text-xs text-gray-500">{{ $s['party']['code'] }}{{ $s['party']['contractor_code'] ? ' · '.$s['party']['contractor_code'] : '' }}</div>
                    <div class="text-xs text-gray-500">{{ $s['party']['phone'] }} {{ $s['party']['address'] }}</div></div>
                <div><div class="text-xs text-gray-500">Opening ({{ $d($s['from']) }})</div><div class="font-semibold tabular-nums">{{ $bal($s['opening']) }}</div></div>
                <div><div class="text-xs text-gray-500">Debit / credit in period</div><div class="tabular-nums">{{ $money($s['total_dr']) }} / {{ $money($s['total_cr']) }}</div></div>
                <div><div class="text-xs text-gray-500">Closing ({{ $d($s['to']) }})</div><div class="font-semibold tabular-nums">{{ $bal($s['closing']) }}</div>
                    <div class="text-xs text-gray-500">{{ $s['standing'] }}</div></div>
            </div>
            @if ($view === 'summary')
                <div class="mt-4 grid gap-3 sm:grid-cols-6 text-sm">
                    <div>Trips: <b>{{ $s['summary']['trips'] }}</b></div><div>Net: <b>{{ $kg($s['summary']['net_kg']) }} kg</b></div>
                    <div>Billed: <b>{{ $money($s['summary']['billed']) }}</b></div><div>Open: <b>{{ $money($s['summary']['open']) }}</b></div>
                    <div>Paid: <b>{{ $money($s['summary']['paid']) }}</b></div><div>Received: <b>{{ $money($s['summary']['received']) }}</b></div>
                </div>
            @endif
        </x-filament::section>

        @if (in_array($view, ['detailed', 'payments'], true))
            <x-filament::section :heading="$view === 'payments' ? 'Payments and receipts' : 'Account entries'">
                <div class="overflow-x-auto"><table class="w-full">
                    <thead><tr><th class="{{ $th }}">Date</th><th class="{{ $th }}">Voucher</th><th class="{{ $th }}">Particulars</th><th class="{{ $thr }}">Debit</th><th class="{{ $thr }}">Credit</th><th class="{{ $thr }}">Balance</th></tr></thead>
                    <tbody>
                        <tr><td class="{{ $td }}" colspan="5">Opening balance</td><td class="{{ $tdr }}">{{ $bal($s['opening']) }}</td></tr>
                        @forelse ($view === 'payments' ? $s['payments'] : $s['lines'] as $l)
                            <tr class="{{ $row }}"><td class="{{ $td }}">{{ $d($l['date']) }}</td><td class="{{ $td }} font-mono">{{ $l['number'] }}</td><td class="{{ $td }}">{{ $l['memo'] ?? $l['narration'] }}</td>
                                <td class="{{ $tdr }}">{{ $l['dr'] ? $money($l['dr']) : '' }}</td><td class="{{ $tdr }}">{{ $l['cr'] ? $money($l['cr']) : '' }}</td><td class="{{ $tdr }}">{{ $bal($l['balance']) }}</td></tr>
                        @empty
                            <tr><td class="{{ $td }} text-gray-500" colspan="6">No entries in this period.</td></tr>
                        @endforelse
                        <tr class="{{ $row }} font-semibold"><td class="{{ $td }}" colspan="5">Closing balance</td><td class="{{ $tdr }}">{{ $bal($s['closing']) }}</td></tr>
                    </tbody>
                </table></div>
            </x-filament::section>
        @endif

        @if (in_array($view, ['vehicle', 'sauda'], true))
            <x-filament::section :heading="$view === 'vehicle' ? 'By vehicle' : 'By sauda'">
                <table class="w-full">
                    <thead><tr><th class="{{ $th }}">{{ $view === 'vehicle' ? 'Vehicle' : 'Sauda' }}</th><th class="{{ $thr }}">Trips</th><th class="{{ $thr }}">Net (kg)</th><th class="{{ $thr }}">Amount</th></tr></thead>
                    <tbody>
                        @forelse ($view === 'vehicle' ? $s['by_vehicle'] : $s['by_sauda'] as $g)
                            <tr class="{{ $row }}"><td class="{{ $td }} font-mono">{{ $g['key'] }}</td><td class="{{ $tdr }}">{{ $g['trips'] }}</td><td class="{{ $tdr }}">{{ $kg($g['net_kg']) }}</td><td class="{{ $tdr }}">{{ $money($g['amount']) }}</td></tr>
                        @empty
                            <tr><td class="{{ $td }} text-gray-500" colspan="4">Nothing in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-filament::section>
        @endif

        @if (in_array($view, ['detailed', 'vehicle', 'sauda'], true) && $s['tokens'] !== [])
            <x-filament::section heading="Vehicles (tokens)">
                <div class="overflow-x-auto"><table class="w-full">
                    <thead><tr><th class="{{ $th }}">Date</th><th class="{{ $th }}">Token</th><th class="{{ $th }}">Vehicle</th><th class="{{ $th }}">Item</th><th class="{{ $th }}">Sauda</th>
                        <th class="{{ $thr }}">Gross</th><th class="{{ $thr }}">Tare</th><th class="{{ $thr }}">Net</th><th class="{{ $thr }}">Payable</th><th class="{{ $thr }}">Rate/md</th><th class="{{ $thr }}">Amount</th><th class="{{ $th }}">Bill</th><th class="{{ $thr }}">Open</th></tr></thead>
                    <tbody>
                        @foreach ($s['tokens'] as $t)
                            <tr class="{{ $row }}"><td class="{{ $td }}">{{ $d($t['date']) }}</td><td class="{{ $td }} font-mono">{{ $t['token'] }}</td><td class="{{ $td }} font-mono">{{ $t['vehicle'] }}</td>
                                <td class="{{ $td }}">{{ $t['product'] }}</td><td class="{{ $td }} font-mono">{{ $t['sauda'] }}</td>
                                <td class="{{ $tdr }}">{{ $kg($t['gross']) }}</td><td class="{{ $tdr }}">{{ $kg($t['tare']) }}</td><td class="{{ $tdr }}">{{ $kg($t['net']) }}</td><td class="{{ $tdr }}">{{ $kg($t['payable']) }}</td>
                                <td class="{{ $tdr }}">{{ $money($t['rate']) }}</td><td class="{{ $tdr }}">{{ $money($t['amount']) }}</td><td class="{{ $td }} font-mono text-xs">{{ $t['bill'] }}</td><td class="{{ $tdr }}">{{ $money($t['open']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table></div>
            </x-filament::section>
        @endif

        @if (in_array($view, ['summary', 'detailed'], true) && $s['bills'] !== [])
            <x-filament::section heading="Bills">
                <table class="w-full">
                    <thead><tr><th class="{{ $th }}">Bill</th><th class="{{ $th }}">Number</th><th class="{{ $th }}">Date</th><th class="{{ $th }}">Due</th><th class="{{ $thr }}">Amount</th><th class="{{ $thr }}">Open</th><th class="{{ $th }}">Status</th></tr></thead>
                    <tbody>
                        @foreach ($s['bills'] as $b)
                            <tr class="{{ $row }}"><td class="{{ $td }}">{{ $b['kind'] }}</td><td class="{{ $td }} font-mono">{{ $b['number'] }}</td><td class="{{ $td }}">{{ $d($b['date']) }}</td><td class="{{ $td }}">{{ $d($b['due']) }}</td>
                                <td class="{{ $tdr }}">{{ $money($b['amount']) }}</td><td class="{{ $tdr }}">{{ $money($b['open']) }}</td><td class="{{ $td }}">{{ PartyStatementReport::PAYMENT_STATUSES[$b['pay_status']] ?? '' }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </x-filament::section>
        @endif

        @if ($s['contractor'] !== null && in_array($view, ['contractor', 'detailed', 'summary'], true))
            @php($c = $s['contractor'])
            <x-filament::section heading="Labour contractor">
                @if ($view !== 'summary')
                    <div class="overflow-x-auto"><table class="w-full">
                        <thead><tr><th class="{{ $th }}">Date</th><th class="{{ $th }}">Token</th><th class="{{ $th }}">Vehicle</th><th class="{{ $th }}">Work</th><th class="{{ $thr }}">Gross</th><th class="{{ $thr }}">Tare</th>
                            <th class="{{ $thr }}">Net</th><th class="{{ $thr }}">Billable</th><th class="{{ $thr }}">Rate</th><th class="{{ $th }}">Per</th><th class="{{ $thr }}">Charge</th><th class="{{ $th }}">Bill</th></tr></thead>
                        <tbody>
                            @forelse ($c['tokens'] as $t)
                                <tr class="{{ $row }}"><td class="{{ $td }}">{{ $d($t['date']) }}</td><td class="{{ $td }} font-mono">{{ $t['token'] }}</td><td class="{{ $td }} font-mono">{{ $t['vehicle'] }}</td><td class="{{ $td }}">{{ ucfirst((string) $t['work']) }}</td>
                                    <td class="{{ $tdr }}">{{ $kg($t['gross']) }}</td><td class="{{ $tdr }}">{{ $kg($t['tare']) }}</td><td class="{{ $tdr }}">{{ $kg($t['net']) }}</td><td class="{{ $tdr }}">{{ $kg($t['billable']) }}</td>
                                    <td class="{{ $tdr }}">{{ $money($t['rate']) }}</td><td class="{{ $td }}">{{ $t['basis'] }}</td><td class="{{ $tdr }}">{{ $money($t['charge']) }}</td>
                                    <td class="{{ $td }} font-mono text-xs" title="{{ $t['note'] }}">{{ $t['bill'] ?? ($t['note'] ? 'Waiting: '.$t['note'] : 'Not billed') }}</td></tr>
                            @empty
                                <tr><td class="{{ $td }} text-gray-500" colspan="12">No tokens in this period.</td></tr>
                            @endforelse
                            <tr class="{{ $row }} font-semibold"><td class="{{ $td }}" colspan="10">Not billed yet</td><td class="{{ $tdr }}">{{ $money($c['unbilled_paise']) }}</td><td></td></tr>
                        </tbody>
                    </table></div>
                @endif
                <table class="mt-4 w-full">
                    <thead><tr><th class="{{ $th }}">Bill</th><th class="{{ $th }}">Period</th><th class="{{ $thr }}">Gross</th><th class="{{ $thr }}">Tax / deductions</th><th class="{{ $thr }}">Payable</th><th class="{{ $thr }}">Paid</th><th class="{{ $thr }}">Balance</th><th class="{{ $th }}">Status</th></tr></thead>
                    <tbody>
                        @foreach ($c['bills'] as $b)
                            <tr class="{{ $row }}"><td class="{{ $td }} font-mono">{{ $b['number'] }}</td><td class="{{ $td }}">{{ $b['period'] }}</td><td class="{{ $tdr }}">{{ $money($b['gross']) }}</td><td class="{{ $tdr }}">{{ $money($b['deductions']) }}</td>
                                <td class="{{ $tdr }}">{{ $money($b['payable']) }}</td><td class="{{ $tdr }}">{{ $money($b['paid']) }}</td><td class="{{ $tdr }}">{{ $money($b['balance']) }}</td>
                                <td class="{{ $td }}">{{ \App\Models\Hr\ContractorBill::PAYMENT_STATUSES[$b['pay_status']] ?? '' }}</td></tr>
                        @endforeach
                        <tr class="{{ $row }} font-semibold"><td class="{{ $td }}" colspan="4">Totals</td><td class="{{ $tdr }}">{{ $money($c['payable']) }}</td><td class="{{ $tdr }}">{{ $money($c['paid']) }}</td><td class="{{ $tdr }}">{{ $money($c['balance']) }}</td><td></td></tr>
                    </tbody>
                </table>
                @if ($c['payments'] !== [])
                    <table class="mt-4 w-full">
                        <thead><tr><th class="{{ $th }}">Paid on</th><th class="{{ $th }}">Bill</th><th class="{{ $th }}">From</th><th class="{{ $th }}">Reference</th><th class="{{ $thr }}">Amount</th></tr></thead>
                        <tbody>
                            @foreach ($c['payments'] as $p)
                                <tr class="{{ $row }}"><td class="{{ $td }}">{{ $d($p['date']) }}</td><td class="{{ $td }} font-mono">{{ $p['bill'] }}</td><td class="{{ $td }}">{{ $p['from'] }}</td><td class="{{ $td }}">{{ $p['reference'] }}</td><td class="{{ $tdr }}">{{ $money($p['amount']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-filament::section>
        @endif

        <x-filament::section heading="Sharing history" collapsible collapsed>
            <table class="w-full">
                <thead><tr><th class="{{ $th }}">When</th><th class="{{ $th }}">How</th><th class="{{ $th }}">To</th><th class="{{ $th }}">Period</th><th class="{{ $th }}">What happened</th></tr></thead>
                <tbody>
                    @forelse ($this->getShares() as $sh)
                        <tr class="{{ $row }}"><td class="{{ $td }}">{{ $sh->created_at->timezone('Asia/Karachi')->format('d M Y H:i') }}</td><td class="{{ $td }}">{{ StatementShare::CHANNELS[$sh->channel] ?? $sh->channel }}</td>
                            <td class="{{ $td }}">{{ $sh->recipient ?? '—' }}</td><td class="{{ $td }}">{{ $sh->period_from->format('d M') }} – {{ $sh->period_to->format('d M Y') }}</td>
                            <td class="{{ $td }}" title="{{ $sh->detail }}">{{ StatementShare::STATUSES[$sh->status] ?? $sh->status }}</td></tr>
                    @empty
                        <tr><td class="{{ $td }} text-gray-500" colspan="5">Not shared yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>
    @endif
</x-filament-panels::page>

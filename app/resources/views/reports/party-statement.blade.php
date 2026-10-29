{{-- A party statement (PartyStatementReport): the view decides which sections print. A4 portrait. --}}
@php
    use App\Support\Values\Money;
    $money = fn (?int $p) => $p === null ? '' : Money::paise($p)->grouped();
    $bal = fn (int $p) => $p === 0 ? '0.00' : Money::paise(abs($p))->grouped().($p > 0 ? ' Dr' : ' Cr');
    $kg = fn ($v) => $v === null || $v === '' ? '' : number_format((float) $v, 0);
    $d = fn (?string $date) => $date ? \Carbon\Carbon::parse($date)->format('d M y') : '';
    $view = $view ?? 'detailed';
    $p = $s['party'];
    $c = $s['contractor'];
    $sm = $s['summary'];
@endphp
<html>
<head><meta charset="utf-8">@include('pdf.styles')</head>
<body>
@include('pdf.letterhead', ['title' => $view === 'contractor' ? 'CONTRACTOR STATEMENT' : 'PARTY STATEMENT', 'titleUr' => 'کھاتہ', 'small' => false])

<table class="grid" style="font-size: 8.5pt;">
    <tr>
        <td class="label" style="width: 14%;">Party</td><td class="b" style="width: 36%;">{{ $p['name'] }} <span class="mono muted">{{ $p['code'] }}{{ $p['contractor_code'] ? ' · '.$p['contractor_code'] : '' }}</span></td>
        <td class="label" style="width: 14%;">Period</td><td>{{ $d($s['from']) }} to {{ $d($s['to']) }}</td>
    </tr>
    <tr>
        <td class="label">Contact</td><td>{{ $p['phone'] }} {{ $p['email'] ? '· '.$p['email'] : '' }}<br><span class="muted">{{ $p['address'] }}</span></td>
        <td class="label">Opening</td><td class="num b">{{ $bal($s['opening']) }}</td>
    </tr>
    <tr>
        <td class="label">View</td><td>{{ \App\Domain\Accounts\PartyStatementReport::VIEWS[$view] ?? $view }}@foreach ($s['filters'] as $k => $v) · {{ $k }}: {{ $v }}@endforeach</td>
        <td class="label">Closing</td><td class="num b">{{ $bal($s['closing']) }}</td>
    </tr>
</table>
<div class="box b" style="font-size: 9pt;">{{ $s['standing'] }}</div>

@if ($view === 'summary')
    <table class="grid" style="font-size: 8.5pt;">
        <tr><th>Vehicles (trips)</th><th class="r">Net weight (kg)</th><th class="r">Billed (Rs)</th><th class="r">Still open (Rs)</th><th class="r">Paid (Rs)</th><th class="r">Received (Rs)</th></tr>
        <tr><td>{{ $sm['trips'] }}</td><td class="num">{{ $kg($sm['net_kg']) }}</td><td class="num">{{ $money($sm['billed']) }}</td><td class="num">{{ $money($sm['open']) }}</td>
            <td class="num">{{ $money($sm['paid']) }}</td><td class="num">{{ $money($sm['received']) }}</td></tr>
    </table>
@endif

@if (in_array($view, ['detailed', 'payments'], true))
    <table class="grid" repeat_header="1">
        <thead>
            <tr style="font-size: 8pt;"><th style="width: 11%;">Date</th><th style="width: 17%;">Voucher</th><th>Particulars</th><th class="r" style="width: 14%;">Debit (Rs)</th><th class="r" style="width: 14%;">Credit (Rs)</th><th class="r" style="width: 16%;">Balance (Rs)</th></tr>
        </thead>
        <tbody>
            <tr style="font-size: 8pt;"><td colspan="5" class="b">Opening balance</td><td class="num b">{{ $bal($s['opening']) }}</td></tr>
            @foreach ($view === 'payments' ? $s['payments'] : $s['lines'] as $l)
                <tr style="font-size: 8pt;">
                    <td>{{ $d($l['date']) }}</td><td class="mono" style="font-size: 7pt;">{{ $l['number'] }}</td><td>{{ $l['memo'] ?? $l['narration'] }}</td>
                    <td class="num">{{ $l['dr'] ? $money($l['dr']) : '' }}</td><td class="num">{{ $l['cr'] ? $money($l['cr']) : '' }}</td><td class="num">{{ $bal($l['balance']) }}</td>
                </tr>
            @endforeach
            <tr class="grand" style="font-size: 9pt;"><td colspan="5">Closing balance</td><td class="num">{{ $bal($s['closing']) }}</td></tr>
        </tbody>
    </table>
@endif

@if (in_array($view, ['detailed', 'vehicle', 'sauda'], true) && $s['tokens'] !== [])
    @if ($view === 'vehicle' || $view === 'sauda')
        <table class="grid" style="font-size: 8pt;">
            <tr><th>{{ $view === 'vehicle' ? 'Vehicle' : 'Sauda' }}</th><th class="r">Trips</th><th class="r">Net weight (kg)</th><th class="r">Amount (Rs)</th></tr>
            @foreach ($view === 'vehicle' ? $s['by_vehicle'] : $s['by_sauda'] as $g)
                <tr><td class="mono">{{ $g['key'] }}</td><td class="num">{{ $g['trips'] }}</td><td class="num">{{ $kg($g['net_kg']) }}</td><td class="num">{{ $money($g['amount']) }}</td></tr>
            @endforeach
        </table>
    @endif
    <table class="grid" repeat_header="1" style="font-size: 7.5pt;">
        <thead><tr><th>Date</th><th>Token</th><th>Vehicle</th><th>Item</th><th>Sauda</th><th class="r">Gross</th><th class="r">Tare</th><th class="r">Net</th><th class="r">Payable</th><th class="r">Rate/md</th><th class="r">Amount</th><th>Bill</th><th class="r">Open</th></tr></thead>
        <tbody>
            @foreach ($s['tokens'] as $t)
                <tr>
                    <td>{{ $d($t['date']) }}</td><td class="mono">{{ $t['token'] }}</td><td class="mono">{{ $t['vehicle'] }}</td><td>{{ $t['product'] }}</td><td class="mono">{{ $t['sauda'] }}</td>
                    <td class="num">{{ $kg($t['gross']) }}</td><td class="num">{{ $kg($t['tare']) }}</td><td class="num">{{ $kg($t['net']) }}</td><td class="num">{{ $kg($t['payable']) }}</td>
                    <td class="num">{{ $money($t['rate']) }}</td><td class="num">{{ $money($t['amount']) }}</td><td class="mono" style="font-size: 6.5pt;">{{ $t['bill'] }}</td><td class="num">{{ $money($t['open']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if (in_array($view, ['summary', 'detailed'], true) && $s['bills'] !== [])
    <table class="grid" style="font-size: 8pt;">
        <tr><th>Bill</th><th>Number</th><th>Date</th><th>Due</th><th class="r">Amount (Rs)</th><th class="r">Open (Rs)</th><th>Status</th></tr>
        @foreach ($s['bills'] as $b)
            <tr><td>{{ $b['kind'] }}</td><td class="mono">{{ $b['number'] }}</td><td>{{ $d($b['date']) }}</td><td>{{ $d($b['due']) }}</td>
                <td class="num">{{ $money($b['amount']) }}</td><td class="num">{{ $money($b['open']) }}</td><td>{{ \App\Domain\Accounts\PartyStatementReport::PAYMENT_STATUSES[$b['pay_status']] ?? '' }}</td></tr>
        @endforeach
    </table>
@endif

@if ($c !== null && in_array($view, ['contractor', 'detailed', 'summary'], true))
    <h4 style="margin: 8pt 0 0 0;">Labour contractor {{ $p['contractor_code'] }}</h4>
    @if ($view !== 'summary' && $c['tokens'] !== [])
        <table class="grid" repeat_header="1" style="font-size: 7.5pt;">
            <thead><tr><th>Date</th><th>Token</th><th>Vehicle</th><th>Work</th><th class="r">Gross</th><th class="r">Tare</th><th class="r">Net</th><th class="r">Billable</th><th class="r">Rate</th><th>Per</th><th class="r">Charge</th><th>Bill</th></tr></thead>
            <tbody>
                @foreach ($c['tokens'] as $t)
                    <tr>
                        <td>{{ $d($t['date']) }}</td><td class="mono">{{ $t['token'] }}</td><td class="mono">{{ $t['vehicle'] }}</td><td>{{ ucfirst((string) $t['work']) }}</td>
                        <td class="num">{{ $kg($t['gross']) }}</td><td class="num">{{ $kg($t['tare']) }}</td><td class="num">{{ $kg($t['net']) }}</td><td class="num">{{ $kg($t['billable']) }}</td>
                        <td class="num">{{ $money($t['rate']) }}</td><td>{{ $t['basis'] }}</td><td class="num">{{ $money($t['charge']) }}</td>
                        <td class="mono" style="font-size: 6.5pt;">{{ $t['bill'] ?? ($t['note'] ? '—' : 'Not billed') }}</td>
                    </tr>
                @endforeach
                <tr class="total"><td colspan="10">Not billed yet</td><td class="num">{{ $money($c['unbilled_paise']) }}</td><td></td></tr>
            </tbody>
        </table>
    @endif
    <table class="grid" style="font-size: 8pt;">
        <tr><th>Bill</th><th>Period</th><th class="r">Gross</th><th class="r">Tax / deductions</th><th class="r">Payable</th><th class="r">Paid</th><th class="r">Balance</th><th>Status</th></tr>
        @foreach ($c['bills'] as $b)
            <tr><td class="mono">{{ $b['number'] }}</td><td>{{ $b['period'] }}</td><td class="num">{{ $money($b['gross']) }}</td><td class="num">{{ $money($b['deductions']) }}</td>
                <td class="num">{{ $money($b['payable']) }}</td><td class="num">{{ $money($b['paid']) }}</td><td class="num">{{ $money($b['balance']) }}</td>
                <td>{{ \App\Models\Hr\ContractorBill::PAYMENT_STATUSES[$b['pay_status']] ?? '' }}</td></tr>
        @endforeach
        <tr class="grand"><td colspan="4">Totals</td><td class="num">{{ $money($c['payable']) }}</td><td class="num">{{ $money($c['paid']) }}</td><td class="num">{{ $money($c['balance']) }}</td><td></td></tr>
    </table>
    @if ($c['payments'] !== [])
        <table class="grid" style="font-size: 8pt;">
            <tr><th>Paid on</th><th>Bill</th><th>From</th><th>Reference</th><th class="r">Amount (Rs)</th></tr>
            @foreach ($c['payments'] as $pay)
                <tr><td>{{ $d($pay['date']) }}</td><td class="mono">{{ $pay['bill'] }}</td><td>{{ $pay['from'] }}</td><td>{{ $pay['reference'] }}</td><td class="num">{{ $money($pay['amount']) }}</td></tr>
            @endforeach
        </table>
    @endif
@endif

<div class="box small muted">Dr = owed to {{ $brand['name'] }}; Cr = owed by {{ $brand['name'] }}. Balances are from the books on the day printed. Please report any difference within 15 days.</div>
@include('pdf.signatures', ['signs' => [['Accountant', 'اکاؤنٹنٹ'], ['For '.$brand['name'], 'دستخط مجاز']]])
</body>
</html>

@php
    use App\Support\Values\Money;
    $small = false;
    $money = fn (int $p) => Money::paise($p)->grouped();
    $bal = fn (int $p) => $p === 0 ? '0.00' : Money::paise(abs($p))->grouped().($p > 0 ? ' Dr' : ' Cr');
    $dr = array_sum(array_map(fn ($l) => (int) ($l['dr'] ?? 0), $statement['lines']));
    $cr = array_sum(array_map(fn ($l) => (int) ($l['cr'] ?? 0), $statement['lines']));
@endphp
<html>
<head><meta charset="utf-8">@include('pdf.styles')</head>
<body>
@include('pdf.letterhead', ['title' => 'ACCOUNT STATEMENT', 'titleUr' => 'کھاتہ', 'small' => $small])

<table class="grid">
    <tr>
        <td class="label">Account</td><td class="b">{{ $account->displayName() }}</td>
        <td class="label">Period</td><td>{{ $from->format('d M Y') }} to {{ $to->format('d M Y') }}</td>
    </tr>
</table>

<table class="grid" repeat_header="1">
    <thead>
        <tr style="font-size: 8pt;"><th style="width: 11%;">Date</th><th style="width: 18%;">Voucher</th><th style="width: 25%;">Particulars</th><th class="r" style="width: 14%;">Debit (Rs)</th><th class="r" style="width: 14%;">Credit (Rs)</th><th class="r" style="width: 18%;">Balance (Rs)</th></tr>
    </thead>
    <tbody>
        <tr style="font-size: 8pt;"><td colspan="5" class="b">Opening balance</td><td class="num b">{{ $bal($statement['opening']) }}</td></tr>
        @foreach ($statement['lines'] as $l)
            <tr style="font-size: 8pt;">
                <td>{{ \Carbon\Carbon::parse($l['date'])->format('d M y') }}</td><td class="mono" style="font-size: 7pt;">{{ $l['number'] }}</td><td>{{ $l['memo'] ?? $l['narration'] }}</td>
                <td class="num">{{ $l['dr'] ? $money($l['dr']) : '' }}</td><td class="num">{{ $l['cr'] ? $money($l['cr']) : '' }}</td><td class="num">{{ $bal($l['balance']) }}</td>
            </tr>
        @endforeach
        <tr class="total" style="font-size: 8pt;"><td colspan="3">Totals for the period</td><td class="num">{{ $money($dr) }}</td><td class="num">{{ $money($cr) }}</td><td></td></tr>
        <tr class="grand" style="font-size: 9pt;"><td colspan="5">Closing balance <span class="ur" style="font-size: 120%;">اختتامی بیلنس</span></td><td class="num">{{ $bal($statement['closing']) }}</td></tr>
    </tbody>
</table>

<div class="box small muted">Dr = owed to {{ $brand['name'] }}; Cr = owed by {{ $brand['name'] }}. Please report any difference within 15 days of receiving this statement.</div>
@include('pdf.signatures', ['signs' => [['Accountant', 'اکاؤنٹنٹ'], ['Checked by (second person)', 'جانچ کنندہ'], ['For '.$brand['name'], 'دستخط مجاز']]])
</body>
</html>

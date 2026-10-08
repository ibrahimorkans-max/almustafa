@php
    use App\Filament\Support\Units;
    $money = fn (int $p) => Units::paiseToRupees($p);
    $bal = fn (int $p) => $p === 0 ? '0.00' : Units::paiseToRupees(abs($p)).($p > 0 ? ' Dr' : ' Cr');
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; }
        h1 { font-size: 14px; margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { padding: 3px 4px; border-bottom: 1px solid #ddd; text-align: left; }
        .r { text-align: right; }
        .muted { color: #666; }
    </style>
</head>
<body>
    <h1>Ledger statement — {{ $account->displayName() }}</h1>
    <div class="muted">{{ $from->format('d M Y') }} to {{ $to->format('d M Y') }} · generated {{ now('Asia/Kolkata')->format('d M Y H:i') }} IST</div>
    <table>
        <thead><tr><th>Date</th><th>Voucher</th><th>Particulars</th><th class="r">Debit</th><th class="r">Credit</th><th class="r">Balance</th></tr></thead>
        <tbody>
            <tr><td colspan="5">Opening balance</td><td class="r">{{ $bal($statement['opening']) }}</td></tr>
            @foreach ($statement['lines'] as $l)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($l['date'])->format('d M Y') }}</td><td>{{ $l['number'] }}</td><td>{{ $l['memo'] ?? $l['narration'] }}</td>
                    <td class="r">{{ $l['dr'] ? $money($l['dr']) : '' }}</td><td class="r">{{ $l['cr'] ? $money($l['cr']) : '' }}</td><td class="r">{{ $bal($l['balance']) }}</td>
                </tr>
            @endforeach
            <tr><th colspan="5">Closing balance</th><th class="r">{{ $bal($statement['closing']) }}</th></tr>
        </tbody>
    </table>
</body>
</html>

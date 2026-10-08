<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 14px; margin: 0; }
        .muted { color: #555; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        td, th { border: 1px solid #999; padding: 3px 5px; text-align: left; }
        th { background: #eee; }
        .num { text-align: right; font-family: DejaVu Sans Mono, monospace; }
        .big { font-size: 13px; font-weight: bold; }
        .flags { color: #a00; }
        .code { font-family: DejaVu Sans Mono, monospace; font-size: 12px; letter-spacing: 1px; }
    </style>
</head>
<body>
    <h1>{{ $entity->legal_name ?? $entity->name }}</h1>
    <div class="muted">Weighment slip · {{ $entry->number }} · issued {{ $issuedAt->timezone('Asia/Kolkata')->format('d M Y H:i') }} IST</div>

    <table>
        <tr><th>Vehicle</th><td>{{ $entry->registration_no }}</td><th>Direction</th><td>{{ ucfirst($entry->direction) }}</td></tr>
        <tr><th>Party</th><td>{{ $entry->party?->name ?? '—' }}</td><th>Product</th><td>{{ $entry->product?->name ?? '—' }}</td></tr>
        <tr><th>Sauda</th><td>{{ $entry->sauda?->number ?? '—' }}</td><th>Bags</th><td>{{ $entry->bag_count ?? '—' }}</td></tr>
    </table>

    <table>
        <tr><th>Weighment</th><th>Time (IST)</th><th>Source</th><th class="num">kg</th></tr>
        @foreach ($weighments as $w)
            <tr>
                <td>{{ ucfirst($w->kind) }}{{ $w->supersedes_id ? ' (re-weigh)' : '' }}</td>
                <td>{{ $w->captured_at->timezone('Asia/Kolkata')->format('d M H:i:s') }}</td>
                <td>{{ $w->source === 'manual_override' ? 'MANUAL (approved)' : 'Indicator' }}</td>
                <td class="num">{{ number_format((float) $w->weight_kg, 3) }}</td>
            </tr>
        @endforeach
    </table>

    <table>
        <tr><th>Gross</th><td class="num">{{ $slip['gross_kg'] }} kg</td></tr>
        <tr><th>Tare</th><td class="num">{{ $slip['tare_kg'] }} kg</td></tr>
        <tr><th>Net</th><td class="num big">{{ $slip['net_kg'] }} kg</td></tr>
        <tr><th>Quality deduction</th><td class="num">{{ $slip['deduction_kg'] ?? '0.000' }} kg</td></tr>
        <tr><th>Payable weight</th><td class="num big">{{ $slip['payable_kg'] }} kg ({{ \App\Support\Values\Weight::kg($slip['payable_kg'])->in('quintal') }} qtl)</td></tr>
        @if (! empty($slip['rate_cut_paise_per_quintal']))
            <tr><th>Rate cut</th><td class="num">₹ {{ number_format($slip['rate_cut_paise_per_quintal'] / 100, 2) }} / qtl</td></tr>
        @endif
        <tr><th>Lab</th><td>{{ ($slip['lab_accepted'] ?? false) ? 'Accepted' : 'Not accepted' }}</td></tr>
    </table>

    @if (! empty($slip['flags']))
        <p class="flags">Notes: {{ implode(', ', $slip['flags']) }}</p>
    @endif

    <p>Verification code: <span class="code">{{ $code }}</span> · check at {{ url('/verify') }}</p>
    <p class="muted">Weights are recorded automatically from the weighbridge indicator. Any manual value is marked above and was approved by two people.</p>
</body>
</html>

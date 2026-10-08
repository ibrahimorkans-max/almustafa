<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 14px; margin: 0; }
        h2 { font-size: 13px; margin: 8px 0 0; text-align: center; letter-spacing: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        td, th { border: 1px solid #999; padding: 4px 6px; text-align: left; }
        th { background: #eee; width: 35%; }
        .code { font-family: DejaVu Sans Mono, monospace; font-size: 13px; letter-spacing: 1px; }
    </style>
</head>
<body>
    <h1>{{ $entity->legal_name ?? $entity->name }}</h1>
    <h2>GATE PASS</h2>
    <table>
        <tr><th>Gate pass no.</th><td>{{ $pass['number'] ?? '' }}</td></tr>
        <tr><th>Issued (IST)</th><td>{{ $issuedAt->timezone('Asia/Kolkata')->format('d M Y H:i') }}</td></tr>
        <tr><th>Vehicle</th><td>{{ $entry->registration_no }}</td></tr>
        <tr><th>Buyer</th><td>{{ $entry->party?->name }}</td></tr>
        <tr><th>Goods</th><td>{{ $entry->product?->name }}</td></tr>
        <tr><th>Net weight (kg)</th><td>{{ number_format((float) ($pass['net_kg'] ?? 0), 3) }}</td></tr>
        <tr><th>Bags</th><td>{{ $pass['bag_count'] ?? '—' }}</td></tr>
        <tr><th>Tax invoice</th><td>{{ $pass['invoice_number'] ?? '' }}</td></tr>
        <tr><th>Verification code</th><td class="code">{{ $code }}</td></tr>
    </table>
</body>
</html>

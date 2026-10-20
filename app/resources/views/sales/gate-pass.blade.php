@php
    use App\Domain\Documents\PdfRenderer;
    $small = true;
    $net = (string) ($pass['net_kg'] ?? '0');
@endphp
<html>
<head><meta charset="utf-8">@include('pdf.styles')</head>
<body>
@include('pdf.letterhead', ['title' => 'GATE PASS (OUTWARD)', 'titleUr' => 'گیٹ پاس', 'small' => $small])

<table class="grid">
    <tr>
        <td class="label">Gate pass no.</td><td class="mono b">{{ $pass['number'] ?? '' }}</td>
        <td class="label">Issued</td><td>{{ $issuedAt->timezone('Asia/Karachi')->format('d M Y H:i') }} PKT</td>
    </tr>
    <tr>
        <td class="label">Vehicle</td><td class="mono b big">{{ $entry->vehicleLabel() }}</td>
        <td class="label">Driver</td><td>{{ $entry->driver_name ?? $entry->driver?->name ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">Buyer</td><td colspan="3">{{ $entry->party?->name ?? '—' }}@if ($entry->party?->city) · {{ $entry->party->city }}@endif</td>
    </tr>
    <tr>
        <td class="label">Goods</td><td colspan="3">{{ $entry->product?->name ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">Bags</td><td class="num">{{ $pass['bag_count'] ?? '—' }}</td>
        <td class="label">Invoice</td><td class="mono">{{ $pass['invoice_number'] ?? '—' }}</td>
    </tr>
    <tr class="grand">
        <td>Net weight</td><td class="num">{{ number_format((float) $net, 3) }} kg</td>
        <td colspan="2" class="r">{{ PdfRenderer::maundKg($net) }}</td>
    </tr>
    <tr>
        <td class="label">Weighment slip</td><td class="mono" colspan="3">{{ $entry->number }}</td>
    </tr>
</table>

<div class="notice small">The vehicle may leave only with this pass. Security checks the vehicle number, bags and seal before opening the gate.
    <span class="ur" style="font-size: 120%;">گاڑی صرف اس پاس کے ساتھ باہر جا سکتی ہے۔</span></div>

@include('pdf.verify', ['small' => $small])
@include('pdf.signatures', ['signs' => [['Store keeper', 'اسٹور کیپر'], ['Security / gate', 'گیٹ / سیکیورٹی'], ['Driver', 'ڈرائیور']]])
</body>
</html>

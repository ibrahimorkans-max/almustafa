@php
    use App\Domain\Documents\PdfRenderer;
    use App\Support\Values\PakistanIds;
    $small = true;
    $total = (string) $pass->total_kg;
    $cancelled = $pass->cancellation !== null;
@endphp
<html>
<head><meta charset="utf-8">@include('pdf.styles')</head>
<body>
@include('pdf.letterhead', ['title' => 'GATE PASS', 'titleUr' => 'گیٹ پاس', 'small' => $small])

@if ($cancelled)
    <div class="notice b" style="color: #b91c1c;">CANCELLED on {{ $pass->cancellation->created_at->timezone('Asia/Karachi')->format('d M Y H:i') }}: {{ $pass->cancellation->reason }}. This pass is not valid.</div>
@endif

<table class="grid">
    <tr>
        <td class="label">Serial number</td><td class="mono b big">{{ $pass->serial }}</td>
        <td class="label">Issued</td><td>{{ $pass->created_at->timezone('Asia/Karachi')->format('d M Y H:i') }} PKT</td>
    </tr>
    <tr>
        <td class="label">Party <span class="ur">پارٹی</span></td><td colspan="3" class="b">{{ $pass->party_name }}@if ($pass->party?->city) · {{ $pass->party->city }}@endif</td>
    </tr>
    <tr>
        <td class="label">Vehicle no. <span class="ur">گاڑی نمبر</span></td><td class="mono b big" colspan="3">{{ PakistanIds::formatVehicleNumber((string) preg_replace('/[^A-Z0-9]/', '', $pass->vehicle_no)) ?: $pass->vehicle_no }}</td>
    </tr>
    @if ($pass->goods)
        <tr><td class="label">Goods</td><td colspan="3">{{ $pass->goods }}</td></tr>
    @endif
    <tr>
        <td class="label">No. of bags <span class="ur">بوریاں</span></td><td class="num b big">{{ number_format($pass->bag_count) }}</td>
        <td class="label">Weight per bag <span class="ur">فی بوری وزن</span></td><td class="num b">{{ rtrim(rtrim(number_format((float) $pass->kg_per_bag, 3), '0'), '.') }} kg</td>
    </tr>
    <tr class="grand">
        <td>Total weight</td><td class="num">{{ number_format((float) $total, 3) }} kg</td>
        <td colspan="2" class="r">{{ PdfRenderer::maundKg($total) }}</td>
    </tr>
    @if ($pass->remarks)
        <tr><td class="label">Remarks</td><td colspan="3">{{ $pass->remarks }}</td></tr>
    @endif
</table>

<div class="notice small">The vehicle may leave only with this pass. Security checks the vehicle number and counts the bags before opening the gate.
    <span class="ur" style="font-size: 120%;">گاڑی صرف اس پاس کے ساتھ باہر جا سکتی ہے۔</span></div>

@include('pdf.verify', ['small' => $small, 'note' => 'The check page shows this pass\'s party, vehicle, bags and weight, and whether it was cancelled.'])
@include('pdf.signatures', ['signs' => [['Issued by', 'جاری کنندہ'], ['Security / gate', 'گیٹ / سیکیورٹی'], ['Driver', 'ڈرائیور']]])
</body>
</html>

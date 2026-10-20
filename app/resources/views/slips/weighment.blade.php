@php
    use App\Domain\Documents\PdfRenderer;
    use App\Filament\Resources\Gate\GateEntryResource;
    $small = true;
    $kg = fn (?string $v) => $v === null ? '—' : number_format((float) $v, 3);
    $pct = fn (?int $bp) => $bp === null ? '—' : rtrim(rtrim(number_format($bp / 100, 2), '0'), '.').' %';
    $inbound = $entry->direction === 'inbound';
@endphp
<html>
<head><meta charset="utf-8">@include('pdf.styles')</head>
<body>
@include('pdf.letterhead', ['title' => 'WEIGHMENT SLIP', 'titleUr' => 'کانٹا پرچی', 'small' => $small])

<table class="grid">
    <tr>
        <td class="label">Slip no.</td><td class="mono b">{{ $entry->number }}</td>
        <td class="label">Issued</td><td>{{ $issuedAt->timezone('Asia/Karachi')->format('d M Y H:i') }} PKT</td>
    </tr>
    <tr>
        <td class="label">Vehicle</td><td class="mono b">{{ $entry->vehicleLabel() }}</td>
        <td class="label">Driver</td><td>{{ $entry->driver_name ?? $entry->driver?->name ?? $entry->vehicle?->defaultDriver?->name ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">{{ $inbound ? 'Supplier' : 'Buyer' }}</td><td>{{ $entry->party?->name ?? '—' }}@if ($entry->party?->phone)<br><span class="small muted">{{ \App\Support\Values\PakistanIds::formatMobile($entry->party->phone) }}</span>@endif</td>
        <td class="label">Direction</td><td>{{ $inbound ? 'Inward (paddy received)' : 'Outward (dispatch)' }}</td>
    </tr>
    <tr>
        <td class="label">Product</td><td>{{ $entry->product?->name ?? '—' }}</td>
        <td class="label">Sauda / bags</td><td>{{ $entry->sauda?->number ?? '—' }} · {{ $entry->bag_count ?? '—' }} bags</td>
    </tr>
</table>

<table class="grid">
    <tr><th>Weighment</th><th>Time (PKT)</th><th>Source</th><th class="r">Weight (kg)</th></tr>
    @foreach ($weighments as $w)
        <tr>
            <td>{{ $w->kind === 'gross' ? 'Gross (loaded)' : ($w->kind === 'tare' ? 'Tare (empty)' : ucfirst($w->kind)) }}{{ $w->supersedes_id ? ' — re-weigh' : '' }}</td>
            <td>{{ $w->captured_at->timezone('Asia/Karachi')->format('d M H:i:s') }}</td>
            <td>{{ $w->source === 'manual_override' ? 'MANUAL (approved by two people)' : 'Weighbridge indicator' }}</td>
            <td class="num">{{ $kg($w->weight_kg) }}</td>
        </tr>
    @endforeach
</table>

<table class="grid">
    <tr><td class="label">Gross</td><td class="num">{{ $kg($slip['gross_kg']) }} kg</td><td class="label">Tare</td><td class="num">{{ $kg($slip['tare_kg']) }} kg</td></tr>
    <tr class="total"><td>Net weight</td><td class="num">{{ $kg($slip['net_kg']) }} kg</td><td colspan="2" class="r">{{ PdfRenderer::maundKg((string) $slip['net_kg']) }}</td></tr>
    @if ($inbound)
        <tr><td class="label">Quality deduction</td><td class="num">{{ $kg($slip['deduction_kg'] ?? '0') }} kg</td><td class="label">Lab</td><td>{{ ($slip['lab_accepted'] ?? false) ? 'Accepted' : 'Not accepted' }}</td></tr>
        <tr class="grand"><td>Payable weight</td><td class="num">{{ $kg($slip['payable_kg']) }} kg</td><td colspan="2" class="r">{{ $slip['payable_kg'] !== null ? PdfRenderer::maundKg((string) $slip['payable_kg']) : '—' }}</td></tr>
        @if (! empty($slip['rate_cut_paise_per_maund']))
            <tr><td class="label">Rate cut (quality)</td><td colspan="3">{{ \App\Support\Values\Money::paise((int) $slip['rate_cut_paise_per_maund'])->format() }} per maund</td></tr>
        @endif
    @endif
</table>

@if ($inbound && $lab)
    <table class="grid">
        <tr><th colspan="6">Quality test (lab sample {{ $lab->sample_code ?? '—' }}, {{ $lab->tested_at->timezone('Asia/Karachi')->format('d M H:i') }})</th></tr>
        <tr>
            <td class="label">Moisture</td><td class="num">{{ $pct($lab->moisture_bp) }}</td>
            <td class="label">Foreign matter</td><td class="num">{{ $pct($lab->foreign_matter_bp) }}</td>
            <td class="label">Broken</td><td class="num">{{ $pct($lab->broken_bp) }}</td>
        </tr>
        <tr>
            <td class="label">Damaged</td><td class="num">{{ $pct($lab->damaged_bp) }}</td>
            <td class="label">Immature</td><td class="num">{{ $pct($lab->immature_bp) }}</td>
            <td class="label">Grade</td><td>{{ $lab->grade ?? '—' }}</td>
        </tr>
    </table>
@endif

@if (! empty($slip['flags']))
    <div class="notice flags small">Notes: {{ implode(' · ', array_map(fn ($f) => GateEntryResource::FLAG_LABELS[$f] ?? $f, $slip['flags'])) }}</div>
@endif

@include('pdf.verify', ['small' => $small, 'note' => 'Weights come straight from the weighbridge indicator; any manual figure is marked above.'])
@include('pdf.signatures', ['signs' => [['Weighbridge operator', 'کانٹا آپریٹر'], ['Driver', 'ڈرائیور'], [$inbound ? 'Supplier' : 'Store keeper', $inbound ? 'فروخت کنندہ' : 'اسٹور کیپر']]])
</body>
</html>

@php
    use App\Domain\Documents\PdfRenderer;
    use App\Domain\Gate\LabReport;
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
        <td class="label">Product / item</td><td>{{ $entry->itemLabel() }}</td>
        <td class="label">Sauda / bags</td><td>{{ $entry->sauda?->number ?? '—' }} · {{ $entry->bag_count ?? '—' }} bags</td>
    </tr>
</table>

<table class="grid">
    <tr><th>Weighment</th><th>Time (PKT)</th><th>Source</th><th class="r">Weight (kg)</th></tr>
    @foreach ($weighments as $w)
        <tr>
            <td>{{ $w->kind === 'gross' ? 'Gross (loaded)' : ($w->kind === 'tare' ? 'Tare (empty)' : ucfirst($w->kind)) }}{{ $w->supersedes_id ? ' — re-weigh' : '' }}</td>
            <td>{{ $w->captured_at->timezone('Asia/Karachi')->format('d M H:i:s') }}</td>
            <td>{{ $w->source === 'manual_override' ? (in_array('manual_entry', (array) $w->flags, true) ? 'TYPED BY HAND'.($w->reason ? ' — '.$w->reason : '') : 'MANUAL (approved)') : 'Weighbridge (computer)' }}</td>
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
    @endif
</table>

@if ($inbound && $labReport['test'] !== null)
    @php($t = $labReport['test'])
    <table class="grid">
        <tr><th colspan="5">Lab report <span class="ur">لیب رپورٹ</span> — sample {{ $t->sample_code ?? '—' }}, {{ $t->tested_at->timezone('Asia/Karachi')->format('d M H:i') }}{{ $labReport['corrections'] > 0 ? ' (corrected '.$labReport['corrections'].'×)' : '' }}</th></tr>
        @if ($labReport['rows'] !== [])
            <tr><th>Test</th><th class="r">Result</th><th class="r">Standard</th><th class="r">Grace</th><th>Reading</th></tr>
            @foreach ($labReport['rows'] as $r)
                <tr>
                    <td>{{ $r['name'] }} <span class="ur">{{ $r['name_ur'] }}</span></td>
                    <td class="num b">{{ LabReport::pct((int) $r['value_bp']) }} {{ $r['unit'] }}</td>
                    <td class="num">{{ LabReport::pct((int) $r['standard_bp']) }}</td>
                    <td class="num">{{ LabReport::pct((int) $r['grace_bp']) }}</td>
                    <td>{{ match ($r['verdict']) { 'over' => 'OVER GRACE', 'grace' => 'within grace', default => 'OK' } }}</td>
                </tr>
            @endforeach
        @else
            <tr>
                <td class="label">Moisture</td><td class="num">{{ $pct($t->moisture_bp) }}</td>
                <td class="label">Foreign matter</td><td class="num" colspan="3">{{ $pct($t->foreign_matter_bp) }}</td>
            </tr>
        @endif
        <tr><td class="label">Lab decision</td><td colspan="4" class="b">{{ LabReport::decisionLabel($labReport) }}{{ $t->grade ? ' · grade '.$t->grade : '' }}</td></tr>
        @if ($labReport['rejected_reason'])
            <tr><td class="label">Rejected because</td><td colspan="4">{{ $labReport['rejected_reason'] }}</td></tr>
        @endif
        @if ($labReport['after_negotiation'] && $labReport['accepted_reason'])
            <tr><td class="label">Taken in because</td><td colspan="4">{{ $labReport['accepted_reason'] }}</td></tr>
        @endif
    </table>
@endif

@if (! empty($slip['flags']))
    <div class="notice flags small">Notes: {{ implode(' · ', array_map(fn ($f) => GateEntryResource::FLAG_LABELS[$f] ?? $f, $slip['flags'])) }}</div>
@endif

@if ($brand['slip_note'] !== '')
    <div class="box small">{{ $brand['slip_note'] }}</div>
@endif
@include('pdf.verify', ['small' => $small, 'note' => 'Weights come straight from the weighbridge indicator; any manual figure is marked above.'])
@include('pdf.signatures', ['signs' => [['Weighbridge operator', 'کانٹا آپریٹر'], ['Driver', 'ڈرائیور'], [$inbound ? 'Supplier' : 'Store keeper', $inbound ? 'فروخت کنندہ' : 'اسٹور کیپر']]])
</body>
</html>

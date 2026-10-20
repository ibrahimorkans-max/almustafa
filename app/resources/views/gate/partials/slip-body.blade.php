{{-- One vehicle's complete slip. Param: $d = VehicleRegister::details(). Used by the single slip and by "print all slips". --}}
@php
    $row = $d['row'];
    $inbound = $row['direction'] === 'inbound';
    $price = $row['price'];
@endphp
@include('pdf.letterhead', ['title' => $inbound ? 'VEHICLE SLIP — INWARD' : 'VEHICLE SLIP — OUTWARD', 'titleUr' => 'گاڑی پرچی', 'small' => false])

@foreach (array_slice($d['sections'], 0, 2) as $section)
    <table class="grid">
        <tr><th colspan="6">{{ $section['title'] }}</th></tr>
        @foreach (array_chunk($section['fields'], 3) as $pair)
            <tr>
                @foreach ($pair as [$label, $value])
                    <td class="label">{{ $label }}</td><td @if ($loop->last && count($pair) < 3) colspan="{{ 1 + (3 - count($pair)) * 2 }}" @endif>{{ $value }}</td>
                @endforeach
            </tr>
        @endforeach
    </table>
@endforeach

<table class="grid">
    <tr><th>Weighment</th><th>Time (PKT)</th><th>Scale · source</th><th>By</th><th class="r">Weight</th></tr>
    @forelse ($d['weighments'] as $w)
        <tr>
            <td>{{ $w['kind'] }}@if ($w['note'] !== '')<br><span class="small muted">{{ $w['note'] }}</span>@endif</td>
            <td>{{ $w['when'] }}</td><td>{{ $w['scale'] }} · {{ $w['source'] }}</td><td>{{ $w['by'] }}</td><td class="num">{{ $w['weight'] }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="muted">Not weighed yet.</td></tr>
    @endforelse
</table>

<table class="grid">
    @foreach (array_chunk(array_values(array_filter($d['sections'][2]['fields'], fn ($f) => ! in_array($f[0], ['Gross', 'Tare'], true))), 2) as $pair)
        <tr>
            @foreach ($pair as [$label, $value])
                <td class="label">{{ $label }}</td><td class="b" @if (count($pair) === 1) colspan="3" @endif>{{ $value }}</td>
            @endforeach
        </tr>
    @endforeach
</table>

@if ($d['lab'] !== [])
    <table class="grid"><tr><td class="label">Lab test</td><td>{{ collect($d['lab'])->map(fn ($l) => $l[0].' '.$l[1])->implode(' · ') }}</td></tr></table>
@endif

<table class="grid">
    <tr><th style="width: 50%;">How the weight was worked out</th><th>How the {{ $inbound ? 'payable price' : 'invoice value' }} was worked out — {{ $price['label'] }}</th></tr>
    <tr>
        <td class="small" style="vertical-align: top;">@foreach ($d['weightSteps'] as $step){{ $step }}@if (! $loop->last)<br>@endif @endforeach</td>
        <td class="small" style="vertical-align: top;">@foreach ($d['priceSteps'] as $step){{ $step }}@if (! $loop->last)<br>@endif @endforeach</td>
    </tr>
    <tr class="grand"><td colspan="2">{{ $inbound ? 'Payable to supplier' : 'Receivable from buyer' }}: {{ \App\Domain\Gate\VehicleRegister::rs($price['net_paise']) }}{{ $price['state'] === 'estimate' ? ' (estimate)' : '' }}</td></tr>
</table>

@if (($d['labEval'] ?? []) !== [])
    <table class="grid">
        <tr><th colspan="5">Lab results against the limits</th></tr>
        <tr><td class="label">Test</td><td class="label">Result</td><td class="label">Standard up to</td><td class="label">Grace up to</td><td class="label">Reading</td></tr>
        @foreach ($d['labEval'] as $r)
            <tr>@foreach ($r as $i => $v)<td class="small {{ $i > 0 && $i < 4 ? 'num' : '' }}">{{ $v }}</td>@endforeach</tr>
        @endforeach
    </table>
@endif

@if (($d['prices'] ?? []) !== [])
    <table class="grid">
        <tr><th colspan="6">Negotiation (price versions)</th></tr>
        <tr><td class="label">Version</td><td class="label">Rs / maund</td><td class="label">Status</td><td class="label">Reason</td><td class="label">Proposed by</td><td class="label">Decided by</td></tr>
        @foreach ($d['prices'] as $r)
            <tr>@foreach ($r as $i => $v)<td class="small {{ $i === 1 ? 'num' : '' }}">{{ $v }}</td>@endforeach</tr>
        @endforeach
    </table>
@endif

@if (($d['settlement'] ?? []) !== [] || ($d['finance'] ?? []) !== [])
    <table class="grid">
        <tr><th colspan="4">Caat, purchase and financial settlement</th></tr>
        @foreach (array_chunk(array_merge($d['settlement'] ?? [], $d['finance'] ?? []), 2) as $pair)
            <tr>
                @foreach ($pair as [$label, $value])
                    <td class="label small">{{ $label }}</td><td class="small b" @if (count($pair) === 1) colspan="3" @endif>{{ $value }}</td>
                @endforeach
            </tr>
        @endforeach
    </table>
@endif

@if (($d['approvals'] ?? []) !== [])
    <table class="grid">
        <tr><th colspan="3">Approval history</th></tr>
        @foreach ($d['approvals'] as [$what, $who, $when])
            <tr><td class="small">{{ $what }}</td><td class="small">{{ $who }}</td><td class="small">{{ $when }}</td></tr>
        @endforeach
    </table>
@endif

@if ($d['timeline'] !== [])
    <table class="grid">
        <tr><th>Timeline</th></tr>
        <tr><td class="small">@foreach ($d['timeline'] as [$when, $what, $by]){{ $when }} — {{ $what }} ({{ $by }})@if (! $loop->last)<br>@endif @endforeach</td></tr>
    </table>
@endif

@if ($d['flags'] !== [])
    <div class="notice flags small">Notes: {{ implode(' · ', $d['flags']) }}</div>
@endif

@if ($inbound && (($d['settlement'] ?? []) !== []))
    @include('pdf.signatures', ['signs' => [['Weighbridge operator', 'کانٹا آپریٹر'], ['Prepared by', 'تیار کنندہ'], ['Checked by (second person)', 'جانچ کنندہ'], ['Approved by', 'منظور کنندہ']]])
@else
    @include('pdf.signatures', ['signs' => [['Weighbridge operator', 'کانٹا آپریٹر'], ['Checked by (second person)', 'جانچ کنندہ'], ['Driver', 'ڈرائیور']]])
@endif

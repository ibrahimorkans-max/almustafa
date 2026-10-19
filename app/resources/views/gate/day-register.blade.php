{{-- End-of-day vehicle register: every vehicle in and out on the day, with weights, price and totals. --}}
@php
    use App\Domain\Gate\VehicleRegister as R;
    use App\Support\Values\Money;
    $day = $register['day'];
    $n = fn ($kg) => $kg !== null ? number_format((float) $kg) : '—';
@endphp
<html>
<head><meta charset="utf-8">@include('pdf.styles')
<style>.reg td, .reg th { font-size: 7pt; padding: 2px 3px; } .reg .num { white-space: nowrap; }</style>
</head>
<body>
@include('pdf.letterhead', ['title' => 'VEHICLE REGISTER · '.$day->format('d M Y'), 'titleUr' => 'گاڑیوں کا روزنامچہ', 'small' => false])

@foreach ($register['sides'] as $key => $side)
    @php
        $t = $side['totals'];
        $in = $key === 'inbound';
    @endphp
    <h3 style="margin: 8px 0 4px;">{{ $side['title'] }} — {{ $t['vehicles'] }} {{ \Illuminate\Support\Str::plural('vehicle', $t['vehicles']) }}{{ $t['inside'] ? ', '.$t['inside'].' still inside' : '' }}</h3>
    <table class="grid reg">
        <tr>
            <th>#</th><th>Entry</th><th>Vehicle</th><th>Driver</th><th>{{ $in ? 'Supplier' : 'Buyer' }}</th><th>Product · sauda</th><th class="r">Bags</th>
            <th>In</th><th>Out</th><th class="r">Gross kg</th><th class="r">Tare kg</th><th class="r">Net kg</th>
            @if ($in)<th class="r">Deduct kg</th>@endif
            <th class="r">{{ $in ? 'Payable' : 'Billed' }} maund</th><th class="r">Rate / maund</th><th class="r">Amount (Rs)</th><th>Status</th>
        </tr>
        @forelse ($side['rows'] as $i => $r)
            <tr>
                <td>{{ $i + 1 }}</td><td class="mono">{{ $r['number'] }}</td><td class="mono">{{ \App\Support\Values\PakistanIds::formatVehicleNumber($r['vehicle']) }}</td><td>{{ $r['driver'] ?? '—' }}</td><td>{{ $r['party'] ?? '—' }}</td>
                <td>{{ $r['product'] ?? '—' }}{{ $r['sauda'] ? ' · '.$r['sauda'] : '' }}</td><td class="num">{{ $r['bags'] ?? '—' }}</td>
                <td>{{ $r['arrived']->format('H:i') }}</td><td>{{ $r['exited']?->format('H:i') ?? 'inside' }}</td>
                <td class="num">{{ $n($r['gross_kg']) }}</td><td class="num">{{ $n($r['tare_kg']) }}</td><td class="num">{{ $n($r['net_kg']) }}</td>
                @if ($in)<td class="num">{{ $n($r['deduction_kg']) }}</td>@endif
                <td class="num">{{ R::maund($r['payable_kg']) }}</td>
                <td class="num">{{ $r['price']['rate_paise'] !== null ? Money::paise($r['price']['rate_paise'])->grouped() : '—' }}</td>
                <td class="num">{{ $r['price']['net_paise'] !== null ? Money::paise($r['price']['net_paise'])->grouped() : '—' }}{{ $r['price']['state'] === 'estimate' ? ' *' : '' }}</td>
                <td>{{ $r['status'] }}</td>
            </tr>
        @empty
            <tr><td colspan="{{ $in ? 17 : 16 }}" class="muted">No vehicles.</td></tr>
        @endforelse
        <tr class="grand">
            <td colspan="6">Total</td><td class="num">{{ $t['bags'] }}</td><td colspan="4"></td>
            <td class="num">{{ $n($t['net_kg']) }}</td>
            @if ($in)<td class="num">{{ $n($t['deduction_kg']) }}</td>@endif
            <td class="num">{{ R::maund($t['payable_kg']) }}</td><td></td>
            <td class="num">{{ Money::paise($t['amount_paise'])->grouped() }}</td><td></td>
        </tr>
    </table>
@endforeach

<div class="box small">
    Net = gross − tare (weighbridge). Inbound: payable = net − lab deduction; amount = payable maund × sauda rate − quality rate cut, rounded to the rupee
    (income tax u/s 153 is withheld on the bill when it applies). Outbound: amount = net maund × sauda rate, before sales tax. 1 maund = 40 kg.
    * = estimate: the bill is not made yet.
</div>

@include('pdf.signatures', ['signs' => [['Weighbridge operator', 'کانٹا آپریٹر'], ['Gate keeper', 'گیٹ کیپر'], ['Checked by (second person)', 'جانچ کنندہ'], ['Manager', 'مینیجر']]])
</body>
</html>

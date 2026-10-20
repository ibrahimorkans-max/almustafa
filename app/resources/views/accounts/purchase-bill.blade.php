@php
    use App\Domain\Documents\PdfRenderer;
    use App\Support\Values\Money;
    use App\Support\Values\PakistanIds;
    $small = false;
    $m = fn (int $p) => Money::paise($p)->grouped();
    $pct = fn (int $bp) => rtrim(rtrim(number_format($bp / 100, 2), '0'), '.').'%';
    $slip = $bill->snapshot['slip'] ?? [];
    $party = $bill->party;
    $kg = (string) $bill->payable_kg;
@endphp
<html>
<head><meta charset="utf-8">@include('pdf.styles')</head>
<body>
@include('pdf.letterhead', ['title' => 'PADDY PURCHASE BILL', 'titleUr' => 'دھان کا خریداری بل', 'small' => $small])

<table class="grid">
    <tr>
        <td style="width: 50%;">
            <div class="small muted">Sold by <span class="ur">فروخت کنندہ</span></div>
            <div class="b">{{ $party->legal_name ?? $party->name }}</div>
            <div class="small">{{ trim(($party->address ?? '').' '.($party->city ?? '')) ?: '—' }}</div>
            <div class="small">
                @if ($party->ntn) NTN {{ PakistanIds::formatNtn($party->ntn) }} · @endif
                @if ($party->cnic) CNIC {{ PakistanIds::formatCnic($party->cnic) }} · @endif
                {{ $party->phone ? PakistanIds::formatMobile($party->phone) : '' }}
            </div>
        </td>
        <td>
            <table style="width: 100%;">
                <tr><td class="small muted" style="width: 45%;">Bill no.</td><td class="mono b">{{ $bill->number }}</td></tr>
                <tr><td class="small muted">Bill date</td><td>{{ $bill->bill_date->format('d M Y') }}</td></tr>
                <tr><td class="small muted">Payment due</td><td>{{ $bill->due_date->format('d M Y') }}</td></tr>
                <tr><td class="small muted">Sauda</td><td class="mono">{{ $bill->snapshot['sauda_number'] ?? '—' }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<table class="grid">
    <tr><th>Weighment slip</th><th>Vehicle</th><th>Paddy</th><th class="r">Net (kg)</th><th class="r">Deduction (kg)</th><th class="r">Payable (kg)</th></tr>
    <tr>
        <td class="mono">{{ $bill->snapshot['gate_entry_number'] ?? '—' }}</td>
        <td class="mono">{{ $bill->snapshot['vehicle_label'] ?? (PakistanIds::formatVehicleNumber((string) ($bill->snapshot['registration_no'] ?? '')) ?: '—') }}</td>
        <td>{{ $bill->product?->name ?? '—' }}</td>
        <td class="num">{{ number_format((float) ($slip['net_kg'] ?? 0), 3) }}</td>
        <td class="num">{{ number_format((float) ($slip['deduction_kg'] ?? 0), 3) }}</td>
        <td class="num b">{{ number_format((float) $kg, 3) }}</td>
    </tr>
    <tr><td colspan="6" class="r small">Payable weight: <span class="b">{{ PdfRenderer::maundKg($kg) }}</span> ({{ number_format((float) bcdiv($kg, '40', 3), 3) }} maund)</td></tr>
</table>

<table style="margin-top: 5pt;">
    <tr>
        <td style="width: 50%; vertical-align: top; padding-right: 8pt;">
            <div class="words">{{ $words }}</div>
            <div class="box small">
                Rate Rs {{ $m($bill->rate_paise_per_maund) }} per maund (40 kg) as agreed in the sauda.
                @if ($bill->rate_cut_paise > 0) Quality rate cut Rs {{ $m((int) ($bill->snapshot['rate_cut_paise_per_maund'] ?? 0)) }} per maund from the lab result. @endif
                @if ($bill->wht_paise > 0)
                    <div style="margin-top: 3pt;">Income tax of Rs {{ $m($bill->wht_paise) }} is withheld u/s 153(1)(a) ({{ $bill->wht_filer ? 'filer' : 'non-filer' }} rate) and paid to FBR in the seller's name; the seller can claim it.</div>
                @endif
            </div>
        </td>
        <td style="vertical-align: top;">
            <table class="grid" style="margin-top: 0;">
                <tr><td class="label" style="width: 62%;">Gross value ({{ number_format((float) bcdiv($kg, '40', 3), 3) }} maund × Rs {{ $m($bill->rate_paise_per_maund) }})</td><td class="num">{{ $m($bill->gross_paise) }}</td></tr>
                @if ($bill->rate_cut_paise > 0)
                    <tr><td class="label">Less: quality rate cut</td><td class="num">−{{ $m($bill->rate_cut_paise) }}</td></tr>
                @endif
                @if ($bill->round_off_paise !== 0)
                    <tr><td class="label">Round off</td><td class="num">{{ $bill->round_off_paise < 0 ? '−' : '' }}{{ $m(abs($bill->round_off_paise)) }}</td></tr>
                @endif
                @if ($bill->wht_paise > 0)
                    <tr><td class="label">Less: income tax withheld u/s 153 @ {{ $pct($bill->wht_rate_bp) }}</td><td class="num">−{{ $m($bill->wht_paise) }}</td></tr>
                @endif
                <tr class="grand"><td>Net payable (Rs) <span class="ur" style="font-size: 120%;">قابل ادائیگی</span></td><td class="num">{{ $m($bill->net_paise) }}</td></tr>
            </table>
        </td>
    </tr>
</table>

@include('pdf.verify', ['small' => $small, 'note' => 'Computed from the weighment slip, the lab result and the sauda rate; amounts cannot be typed by hand.'])
@include('pdf.signatures', ['signs' => [['Accountant', 'اکاؤنٹنٹ'], ['Checked by (second person)', 'جانچ کنندہ'], ['Seller', 'فروخت کنندہ'], ['For '.$brand['name'], 'دستخط مجاز']]])
</body>
</html>

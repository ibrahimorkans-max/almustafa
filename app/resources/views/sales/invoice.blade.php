@php
    use App\Support\Values\PakistanIds;
    $small = false;
    $m = fn (int $p) => \App\Support\Values\Money::paise($p)->grouped();
    $pct = fn (int $bp) => rtrim(rtrim(number_format($bp / 100, 2), '0'), '.').'%';
    $taxInvoice = $bill->isSalesTaxInvoice();
    $buyer = $bill->snapshot['buyer'] ?? [];
    $maund = bcdiv((string) $bill->quantity_kg, '40', 3);
    $bags = $bill->snapshot['bags'] ?? null;
    $cell = 'font-size: 8pt;';
@endphp
<html>
<head><meta charset="utf-8">@include('pdf.styles')</head>
<body>
@include('pdf.letterhead', $taxInvoice
    ? ['title' => 'SALES TAX INVOICE', 'titleUr' => 'سیلز ٹیکس انوائس', 'small' => $small]
    : ['title' => 'INVOICE', 'titleUr' => 'انوائس / بل', 'small' => $small])

<table class="grid">
    <tr>
        <td style="width: 50%;">
            <div class="small muted">Seller</div>
            <div class="b">{{ $brand['name'] }}</div>
            <div class="small">{{ $brand['address'] }}</div>
            <div class="small">NTN {{ $brand['ntn'] ?? '—' }}@if ($taxInvoice) &nbsp;·&nbsp; STRN {{ $bill->seller_strn }}@endif</div>
        </td>
        <td style="width: 50%;">
            <div class="small muted">Buyer</div>
            <div class="b">{{ $buyer['name'] ?? $bill->party->name }}</div>
            <div class="small">{{ trim(($buyer['address'] ?? '').(! empty($buyer['address']) ? ', ' : '').($buyer['city'] ?? '').(! empty($buyer['province']) ? ', '.$buyer['province'] : '')) ?: '—' }}</div>
            <div class="small">NTN / CNIC {{ PakistanIds::formatNtn($bill->buyer_ntn) ?? '—' }} &nbsp;·&nbsp; STRN {{ $bill->buyer_strn ?? 'Unregistered' }}</div>
        </td>
    </tr>
</table>

<table class="grid">
    <tr>
        <td class="label" style="width: 17%;">Invoice no.</td><td class="mono b" style="width: 33%;">{{ $bill->number }}</td>
        <td class="label" style="width: 17%;">Invoice date</td><td style="width: 33%;">{{ $bill->bill_date->format('d M Y') }}</td>
    </tr>
    <tr>
        <td class="label">Payment due</td><td>{{ $bill->due_date->format('d M Y') }}</td>
        <td class="label">Vehicle</td><td class="mono">{{ PakistanIds::formatVehicleNumber((string) ($bill->snapshot['registration_no'] ?? '')) ?: '—' }}</td>
    </tr>
    <tr>
        <td class="label">Weighment slip</td><td class="mono">{{ $bill->snapshot['gate_entry_number'] ?? '—' }}</td>
        <td class="label">Order / sauda</td><td class="mono">{{ $bill->snapshot['dispatch_order_number'] ?? '—' }} / {{ $bill->snapshot['sauda_number'] ?? '—' }}</td>
    </tr>
</table>

<table class="grid">
    <tr>
        <th style="width: 4%; {{ $cell }}">#</th>
        <th style="width: {{ $taxInvoice ? '18%' : '36%' }}; {{ $cell }}">Description</th>
        <th style="width: 11%; {{ $cell }}">HS code</th>
        <th class="r" style="width: 7%; {{ $cell }}">Bags</th>
        <th class="r" style="width: 11%; {{ $cell }}">Quantity (kg)</th>
        <th class="r" style="width: 9%; {{ $cell }}">Maund</th>
        <th class="r" style="width: 10%; {{ $cell }}">Rate / maund</th>
        <th class="r" style="width: 12%; {{ $cell }}">Value excl. tax</th>
        @if ($taxInvoice)
            <th class="r" style="width: 6%; {{ $cell }}">Rate</th>
            <th class="r" style="width: 12%; {{ $cell }}">Sales tax</th>
        @endif
    </tr>
    <tr>
        <td class="c" style="{{ $cell }}">1</td>
        <td style="{{ $cell }}">{{ $bill->snapshot['product']['name'] ?? $bill->product->name }}</td>
        <td style="{{ $cell }} font-family: dejavusanscondensed;">{{ $bill->pct_code ?? '—' }}</td>
        <td class="num" style="{{ $cell }}">{{ $bags ?? '—' }}</td>
        <td class="num" style="{{ $cell }}">{{ number_format((float) $bill->quantity_kg, 3) }}</td>
        <td class="num" style="{{ $cell }}">{{ number_format((float) $maund, 3) }}</td>
        <td class="num" style="{{ $cell }}">{{ $m($bill->rate_paise_per_maund) }}</td>
        <td class="num" style="{{ $cell }}">{{ $m($bill->taxable_paise) }}</td>
        @if ($taxInvoice)
            <td class="num" style="{{ $cell }}">{{ $bill->tax_exempt ? 'Exempt' : $pct($bill->tax_rate_bp) }}</td>
            <td class="num" style="{{ $cell }}">{{ $m($bill->sales_tax_paise) }}</td>
        @endif
    </tr>
</table>

<table style="margin-top: 6pt;">
    <tr>
        <td style="width: 48%; vertical-align: top; padding-right: 8pt;">
            <div class="words">{{ $words }}</div>
            <div class="box small">
                <div class="b">Terms</div>
                @if ($brand['invoice_terms'] !== '')
                    <div>{!! nl2br(e($brand['invoice_terms'])) !!}</div>
                @else
                    <div>Payment within {{ $bill->bill_date->diffInDays($bill->due_date) }} days of the invoice date, into the mill's bank account only.
                        Weights are from the mill weighbridge slip quoted above.</div>
                @endif
                @if (! $taxInvoice)
                    <div class="muted" style="margin-top: 3pt;">The seller is not registered for sales tax; no sales tax is charged on this invoice.</div>
                @elseif ($bill->tax_exempt)
                    <div class="muted" style="margin-top: 3pt;">Supply exempt from sales tax.</div>
                @endif
            </div>
            @if ($brand['bank'] !== [])
                <div class="box small">
                    <div class="b">Pay into</div>
                    @foreach ($brand['bank'] as $label => $value)<div>{{ $label }}: <span class="b">{{ $value }}</span></div>@endforeach
                </div>
            @endif
        </td>
        <td style="width: 52%; vertical-align: top;">
            <table class="grid" style="margin-top: 0;">
                <tr><td class="label" style="width: 62%;">Value excluding sales tax</td><td class="num" style="width: 38%;">{{ $m($bill->taxable_paise) }}</td></tr>
                @if ($taxInvoice)
                    <tr><td class="label">Sales tax{{ $bill->tax_exempt ? ' (exempt)' : ' @ '.$pct($bill->tax_rate_bp) }}</td><td class="num">{{ $m($bill->sales_tax_paise) }}</td></tr>
                    @if ($bill->further_tax_paise > 0)
                        <tr><td class="label">Further tax @ {{ $pct($bill->further_tax_rate_bp) }} (buyer not registered)</td><td class="num">{{ $m($bill->further_tax_paise) }}</td></tr>
                    @endif
                @endif
                @if ($bill->round_off_paise !== 0)
                    <tr><td class="label">Round off</td><td class="num">{{ $bill->round_off_paise < 0 ? '−' : '' }}{{ $m(abs($bill->round_off_paise)) }}</td></tr>
                @endif
                <tr class="grand"><td>Total (Rs) &nbsp;<span style="font-family: lateef; font-size: 14pt;">کل رقم</span></td><td class="num">{{ $m($bill->total_paise) }}</td></tr>
            </table>
        </td>
    </tr>
</table>

@include('pdf.verify', ['small' => $small, 'note' => 'Computed from the weighment slip and the confirmed deal; amounts cannot be typed by hand.'])
@include('pdf.signatures', ['signs' => [['Prepared by', 'تیار کنندہ'], ['Checked by (second person)', 'جانچ کنندہ'], ['Received by (buyer)', 'وصول کنندہ'], ['For '.$brand['name'], 'دستخط مجاز']]])
</body>
</html>

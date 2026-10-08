@php
    use App\Filament\Support\Units;
    $m = fn (int $p) => Units::paiseToRupees($p);
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 15px; margin: 0; }
        h2 { font-size: 12px; margin: 8px 0 0; text-align: center; letter-spacing: 1px; }
        .muted { color: #555; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        td, th { border: 1px solid #999; padding: 3px 5px; text-align: left; vertical-align: top; }
        th { background: #eee; }
        .num { text-align: right; }
        .code { font-family: DejaVu Sans Mono, monospace; font-size: 11px; letter-spacing: 1px; }
    </style>
</head>
<body>
    <h1>{{ $entity->legal_name ?? $entity->name }}</h1>
    <div class="muted">{{ $entity->address }} · GSTIN {{ $entity->gstin ?? '—' }} · State code {{ $entity->state_code ?? '—' }}</div>
    <h2>TAX INVOICE</h2>
    @if ($bill->irn && $bill->einvoice_status === 'generated')
        <table>
            <tr>
                <td style="width: 75%">
                    <strong>IRN</strong> <span class="code" style="font-size: 8px">{{ $bill->irn }}</span><br>
                    <strong>Ack no.</strong> {{ $bill->irn_ack_no }} · <strong>Ack date</strong> {{ $bill->irn_ack_at?->timezone('Asia/Kolkata')->format('d M Y H:i') }}
                    @if ($bill->eway_bill_no)
                        <br><strong>E-way bill</strong> {{ $bill->eway_bill_no }} · valid until {{ $bill->eway_valid_until?->timezone('Asia/Kolkata')->format('d M Y H:i') }}
                    @endif
                </td>
                <td style="text-align: center"><img src="{{ \App\Domain\Sales\SalesDocuments::qrDataUri((string) $bill->irn_signed_qr) }}" width="100" height="100" alt="e-invoice QR"></td>
            </tr>
        </table>
    @endif

    <table>
        <tr><th>Invoice no.</th><td>{{ $bill->number }}</td><th>Date</th><td>{{ $bill->bill_date->format('d M Y') }}</td></tr>
        <tr><th>Buyer</th><td>{{ $bill->snapshot['buyer']['name'] ?? $bill->party->name }}<br>{{ $bill->snapshot['buyer']['address'] ?? '' }} {{ $bill->snapshot['buyer']['city'] ?? '' }}</td>
            <th>Buyer GSTIN</th><td>{{ $bill->buyer_gstin ?? 'Unregistered' }}</td></tr>
        <tr><th>Place of supply</th><td>State code {{ $bill->place_of_supply ?? '—' }}</td><th>Due date</th><td>{{ $bill->due_date->format('d M Y') }}</td></tr>
        <tr><th>Vehicle</th><td>{{ $bill->snapshot['registration_no'] ?? '' }} (slip {{ $bill->snapshot['gate_entry_number'] ?? '' }})</td>
            <th>Order / sauda</th><td>{{ $bill->snapshot['dispatch_order_number'] ?? '' }} / {{ $bill->snapshot['sauda_number'] ?? '' }}</td></tr>
    </table>

    <table>
        <tr><th>Description</th><th>HSN</th><th class="num">Quantity (kg)</th><th class="num">Rate / qtl</th><th class="num">Taxable value</th></tr>
        <tr><td>{{ $bill->snapshot['product']['name'] ?? $bill->product->name }}</td><td>{{ $bill->hsn_code ?? '—' }}</td>
            <td class="num">{{ number_format((float) $bill->quantity_kg, 3) }}</td><td class="num">{{ $m($bill->rate_paise_per_quintal) }}</td>
            <td class="num">{{ $m($bill->taxable_paise) }}</td></tr>
        @if ($bill->igst_paise > 0)
            <tr><td colspan="4" class="num">IGST @ {{ number_format($bill->gst_rate_bp / 100, 2) }} %</td><td class="num">{{ $m($bill->igst_paise) }}</td></tr>
        @else
            <tr><td colspan="4" class="num">CGST @ {{ number_format($bill->gst_rate_bp / 200, 2) }} %</td><td class="num">{{ $m($bill->cgst_paise) }}</td></tr>
            <tr><td colspan="4" class="num">SGST @ {{ number_format($bill->gst_rate_bp / 200, 2) }} %</td><td class="num">{{ $m($bill->sgst_paise) }}</td></tr>
        @endif
        @if ($bill->round_off_paise !== 0)
            <tr><td colspan="4" class="num">Round off</td><td class="num">{{ $bill->round_off_paise < 0 ? '−' : '' }}{{ $m(abs($bill->round_off_paise)) }}</td></tr>
        @endif
        <tr><th colspan="4" class="num">Total</th><th class="num">{{ $m($bill->total_paise) }}</th></tr>
    </table>
    <p><strong>{{ $words }}</strong></p>
    <p class="muted">Verification code: <span class="code">{{ $code }}</span> · computer-generated from the weighment slip and the confirmed deal.</p>
</body>
</html>

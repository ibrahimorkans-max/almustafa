{{-- The provider's invoice to a customer (SaaS): who bills, who pays, the charge, and where to send the bank transfer. --}}
@php
    $rs = fn (int $paise) => \App\Filament\Support\Units::paiseToRupees($paise);
    $pk = fn ($at) => $at ? \Carbon\CarbonImmutable::parse($at)->timezone('Asia/Karachi')->format('d M Y') : '';
    $sellerName = $seller['seller_name'] ?: $product;
    $state = match ($invoice->standing()) { 'paid' => 'PAID', 'void' => 'CANCELLED', 'overdue' => 'OVERDUE', default => 'DUE' };
@endphp
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.styles')
    <style>
        .inv-head td { vertical-align: top; }
        .inv-title { font-size: 20pt; font-weight: bold; color: {{ config('ricemill.product.green') }}; }
        .muted { color: #5b6760; }
        .box { border: 0.3mm solid #c9d1cc; padding: 3mm; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 6mm; }
        table.lines th { background: #eef3ef; text-align: left; padding: 2mm; border-bottom: 0.6mm solid {{ config('ricemill.product.gold') }}; }
        table.lines td { padding: 2mm; border-bottom: 0.2mm solid #d4dad6; }
        .num { text-align: right; }
        .total td { font-weight: bold; font-size: 11pt; border-bottom: 0; }
        .stamp { font-size: 14pt; font-weight: bold; padding: 1mm 3mm; border: 0.6mm solid; }
        .stamp.PAID { color: #15803d; } .stamp.DUE { color: #a16207; } .stamp.OVERDUE { color: #b91c1c; } .stamp.CANCELLED { color: #6b7280; }
    </style>
</head>
<body>
<table class="inv-head" width="100%">
    <tr>
        <td width="60%">
            <img src="{{ public_path(config('ricemill.product.logo_print')) }}" style="height: 13mm; margin-bottom: 2mm;" alt="">
            <div style="font-size: 13pt; font-weight: bold;">{{ $sellerName }}</div>
            @if ($seller['seller_address'])<div class="muted">{{ $seller['seller_address'] }}</div>@endif
            @if ($seller['seller_ntn'])<div class="muted">NTN {{ $seller['seller_ntn'] }}</div>@endif
        </td>
        <td width="40%" style="text-align: right;">
            <div class="inv-title">INVOICE</div>
            <div><strong>{{ $invoice->number }}</strong></div>
            <div class="muted">Issued {{ $pk($invoice->issued_at) }}</div>
            <div class="muted">Due {{ $pk($invoice->due_at) }}</div>
            <div style="margin-top: 2mm;"><span class="stamp {{ $state }}">{{ $state }}</span></div>
        </td>
    </tr>
</table>

<div class="box" style="margin-top: 6mm; width: 60%;">
    <div class="muted">Bill to</div>
    <div style="font-weight: bold;">{{ $customer->name }}</div>
    <div class="muted">Customer {{ $customer->code }}@if ($customer->contact_name) · {{ $customer->contact_name }}@endif @if ($customer->phone) · {{ $customer->phone }}@endif</div>
    @if ($customer->address)<div class="muted">{{ $customer->address }}</div>@endif
</div>

<table class="lines">
    <thead><tr><th>Description</th><th>Period</th><th class="num">Amount</th></tr></thead>
    <tbody>
        <tr>
            <td>{{ $invoice->description }}</td>
            <td>@if ($invoice->period_start){{ $invoice->period_start->format('d M Y') }} – {{ $invoice->period_end?->format('d M Y') }}@endif</td>
            <td class="num">{{ $rs($invoice->amount_paise) }}</td>
        </tr>
        <tr class="total"><td colspan="2" class="num">Total</td><td class="num">{{ $rs($invoice->amount_paise) }}</td></tr>
    </tbody>
</table>

@if ($invoice->status === 'paid')
    <p style="margin-top: 6mm;"><strong>Paid</strong> {{ $pk($invoice->paid_at) }}: {{ $rs((int) $invoice->paid_amount_paise) }}, reference {{ $invoice->payment_reference }}. Thank you.</p>
@elseif ($invoice->status === 'void')
    <p style="margin-top: 6mm;"><strong>Cancelled:</strong> {{ $invoice->void_reason }}</p>
@else
    @if ($invoice->reported_reference)
        <div class="box" style="margin-top: 6mm;">
            <strong>Payment reported, being checked:</strong> {{ $invoice->reportedSummary() }}
        </div>
    @endif
    @php($methods = \App\Models\SaasPaymentMethod::query()->offered()->get())
    <div style="font-weight: bold; margin-top: 8mm; margin-bottom: 1mm;">How to pay</div>
    @if ($methods->isEmpty())
        <div class="box">
            @forelse ($bank as $label => $value)
                <div>{{ $label }}: <strong>{{ $value }}</strong></div>
            @empty
                <div class="muted">Contact us for the payment details.</div>
            @endforelse
        </div>
    @else
        <table width="100%" style="border-collapse: separate; border-spacing: 2mm;">
            @foreach ($methods->chunk(2) as $pair)
                <tr>
                    @foreach ($pair as $m)
                        <td width="50%" class="box" style="vertical-align: top;">
                            <table width="100%"><tr>
                                <td style="vertical-align: top;">
                                    <div style="font-weight: bold;">{{ $m->name }}</div>
                                    <div class="muted" style="font-size: 7.5pt;">{{ \App\Models\SaasPaymentMethod::KINDS[$m->kind] ?? $m->kind }}</div>
                                    @foreach ($m->details() as $label => $value)
                                        <div style="font-size: 8.5pt;">{{ $label }}: <strong>{{ $value }}</strong></div>
                                    @endforeach
                                    @if ($m->instructions)<div class="muted" style="font-size: 7.5pt; margin-top: 1mm;">{{ $m->instructions }}</div>@endif
                                </td>
                                @if ($m->qr_path !== null && \Illuminate\Support\Facades\Storage::disk('local')->exists($m->qr_path))
                                    <td width="26mm" style="text-align: right; vertical-align: top;"><img src="{{ \Illuminate\Support\Facades\Storage::disk('local')->path($m->qr_path) }}" style="width: 24mm; height: 24mm;" alt=""></td>
                                @elseif ($m->qr_text)
                                    <td width="26mm" style="text-align: right; vertical-align: top;"><barcode code="{{ $m->qr_text }}" type="QR" size="0.8" error="M" disableborder="1" /></td>
                                @endif
                            </tr></table>
                        </td>
                    @endforeach
                    @if ($pair->count() === 1)<td width="50%"></td>@endif
                </tr>
            @endforeach
        </table>
    @endif
    <div class="muted" style="margin-top: 2mm;">Write <strong>{{ $invoice->number }}</strong> with your payment, then press <strong>I've paid</strong> in the system under Administration → My subscription.</div>
@endif

<p class="muted" style="margin-top: 10mm; font-size: 8pt;">{{ $product }} · Thank you for working with us.</p>
</body>
</html>

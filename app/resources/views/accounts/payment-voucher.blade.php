@php
    use App\Models\Accounts\Payment;
    use App\Support\Values\Money;
    $small = true;
    $m = fn (int $p) => Money::paise($p)->grouped();
    $payee = $payment->payee;
@endphp
<html>
<head><meta charset="utf-8">@include('pdf.styles')</head>
<body>
@include('pdf.letterhead', ['title' => 'PAYMENT VOUCHER', 'titleUr' => 'ادائیگی واؤچر', 'small' => $small])

<table class="grid">
    <tr>
        <td class="label">Voucher no.</td><td class="mono b">{{ $payment->number }}</td>
        <td class="label">Date</td><td>{{ $payment->payment_date->format('d M Y') }}</td>
    </tr>
    <tr>
        <td class="label">Paid to</td><td colspan="3" class="b">{{ $payment->party->legal_name ?? $payment->party->name }}</td>
    </tr>
    <tr>
        <td class="label">Mode</td><td>{{ Payment::MODES[$payment->mode] ?? $payment->mode }}</td>
        <td class="label">Paid from</td><td>{{ $payment->fromLedger->name }}</td>
    </tr>
    @if ($payee)
        <tr>
            <td class="label">Into account</td>
            <td colspan="3">{{ $payee['holder'] }} · {{ $payee['bank_name'] ?? $payee['bank_code'] ?? '' }} · IBAN ending {{ $payee['last4'] }}</td>
        </tr>
    @endif
    @if ($payment->reference)
        <tr><td class="label">Transaction / cheque no.</td><td colspan="3" class="mono">{{ $payment->reference }}</td></tr>
    @endif
    <tr class="grand"><td>Amount (Rs)</td><td colspan="3" class="num big">{{ $m($payment->amount_paise) }}</td></tr>
</table>

<div class="words">{{ $words }}</div>

@if ($payment->allocations->isNotEmpty())
    <table class="grid">
        <tr><th>Against bill</th><th>Bill date</th><th class="r">Amount (Rs)</th></tr>
        @foreach ($payment->allocations as $a)
            <tr><td class="mono">{{ $a->bill?->number ?? '—' }}</td><td>{{ $a->bill?->bill_date?->format('d M Y') ?? '—' }}</td><td class="num">{{ $m($a->amount_paise) }}</td></tr>
        @endforeach
    </table>
@endif
@if ($payment->narration)
    <div class="box small">{{ $payment->narration }}</div>
@endif

@include('pdf.verify', ['small' => $small])
@include('pdf.signatures', ['signs' => [['Prepared by', 'تیار کنندہ', $preparedBy ?? null], ['Approved by (second person)', 'منظور کنندہ', $approvedBy ?? null], ['Received by', 'وصول کنندہ']]])
</body>
</html>

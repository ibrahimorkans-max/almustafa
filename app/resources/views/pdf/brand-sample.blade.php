{{-- Print & branding preview: the letterhead, a table, the invoice terms, the bank, the slip note and the footer, as a real document would show them. --}}
@php($small = in_array($paper ?? null, ['a6', 'roll', 'roll50'], true))
<html>
<head><meta charset="utf-8">@include('pdf.styles')</head>
<body>
@include('pdf.letterhead', ['title' => 'SAMPLE DOCUMENT', 'titleUr' => 'نمونہ', 'small' => $small])

<table class="grid">
    <tr><th>Item</th><th class="num">Quantity</th><th class="num">Rate</th><th class="num">Amount</th></tr>
    <tr><td>Paddy, Super Basmati</td><td class="num">12,400.000 kg</td><td class="num">Rs 3,850 / 40 kg</td><td class="num">Rs 1,193,500.00</td></tr>
    <tr class="grand"><td colspan="3">Total (Rs)</td><td class="num">Rs 1,193,500.00</td></tr>
</table>

<div class="box small">
    <div class="b">Terms on sales invoices</div>
    @if ($brand['invoice_terms'] !== '')
        <div>{!! nl2br(e($brand['invoice_terms'])) !!}</div>
    @else
        <div class="muted">The standard terms (none of your own yet).</div>
    @endif
</div>
@if ($brand['bank'] !== [])
    <div class="box small">
        <div class="b">Pay into</div>
        @foreach ($brand['bank'] as $label => $value)<div>{{ $label }}: <span class="b">{{ $value }}</span></div>@endforeach
    </div>
@endif
@if ($brand['slip_note'] !== '')
    <div class="box small"><span class="b">On weighment slips:</span> {{ $brand['slip_note'] }}</div>
@endif

@include('pdf.signatures', ['signs' => [['Prepared by', 'تیار کنندہ'], ['For '.$brand['name'], 'دستخط مجاز']]])
</body>
</html>

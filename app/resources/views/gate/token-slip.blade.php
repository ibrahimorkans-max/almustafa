{{--
    Gate entry token: the mill's name, then the token number (six digits, no prefix), party, vehicle,
    type (With Jack / Without Jack, beside the number, never inside it), date and time.
    Sized for 80 mm and 50 mm rolls and for A5 / A4 paper.
--}}
@php
    $narrow = ($paper ?? null) === 'roll50';
    $sizes = match (true) {
        $narrow => ['mill' => '9pt', 'label' => '7pt', 'value' => '9pt', 'token' => '30pt', 'title' => '7pt'],
        ($roll ?? false) => ['mill' => '11pt', 'label' => '8pt', 'value' => '11pt', 'token' => '40pt', 'title' => '8pt'],
        default => ['mill' => '16pt', 'label' => '11pt', 'value' => '15pt', 'token' => '72pt', 'title' => '11pt'],
    };
    $brand = \App\Domain\Documents\PdfRenderer::brand($entry->entity);
    $at = $entry->arrived_at->timezone('Asia/Karachi');
    $rows = [
        ['Party Name', 'پارٹی', $entry->partyLabel() ?? '—'],
        ['Vehicle Number', 'گاڑی', $vehicle],
        ['Type', 'قسم', $entry->jackLabel() ?? '—'],
        ['Date', 'تاریخ', $at->format('d-m-Y')],
        ['Time', 'وقت', $at->format('h:i A')],
    ];
@endphp
<html>
<head><meta charset="utf-8">@include('pdf.styles')</head>
<body>
<div style="text-align: center;">
    <div style="font-size: {{ $sizes['mill'] }}; font-weight: bold;">{{ $brand['name'] }}</div>
    <div style="font-size: {{ $sizes['title'] }}; letter-spacing: 1pt; margin-top: 1mm;">GATE ENTRY TOKEN <span class="ur">ٹوکن</span></div>
    <div style="font-size: {{ $sizes['label'] }}; margin-top: 2mm;">Token Number</div>
    <div style="font-family: dejavusansmono; font-size: {{ $sizes['token'] }}; font-weight: bold; line-height: 1;">{{ $entry->tokenLabel() }}</div>
</div>
<table style="width: 100%; margin-top: 2mm; border-collapse: collapse;">
    @foreach ($rows as [$label, $urdu, $value])
        <tr>
            <td style="font-size: {{ $sizes['label'] }}; padding: 0.8mm 0; border-top: 0.2mm solid #999; white-space: nowrap;">{{ $label }} <span class="ur">{{ $urdu }}</span></td>
            <td style="font-size: {{ $sizes['value'] }}; font-weight: bold; padding: 0.8mm 0; border-top: 0.2mm solid #999; text-align: right;{{ $label === 'Vehicle Number' ? ' font-family: dejavusansmono;' : '' }}">{{ $value }}</td>
        </tr>
    @endforeach
</table>
</body>
</html>

{{-- Token slip: only the token number and the vehicle number (first come, first served). Sized for 80 mm and 50 mm rolls. --}}
@php
    $narrow = ($paper ?? null) === 'roll50';
    $sizes = match (true) {
        $narrow => ['label' => '8pt', 'token' => '40pt', 'vehicle' => '13pt'],
        ($roll ?? false) => ['label' => '10pt', 'token' => '54pt', 'vehicle' => '20pt'],
        default => ['label' => '11pt', 'token' => '96pt', 'vehicle' => '34pt'],
    };
@endphp
<html>
<head><meta charset="utf-8">@include('pdf.styles')</head>
<body>
<div style="text-align: center;">
    <div style="font-size: {{ $sizes['label'] }}; letter-spacing: 1pt;">TOKEN <span class="ur">ٹوکن</span></div>
    <div style="font-size: {{ $sizes['token'] }}; font-weight: bold; line-height: 1;">{{ $entry->token_no ?? '—' }}</div>
    <div style="margin-top: 2mm; font-size: {{ $sizes['label'] }};">VEHICLE <span class="ur">گاڑی</span></div>
    <div style="font-family: dejavusansmono; font-size: {{ $sizes['vehicle'] }}; font-weight: bold;">{{ $vehicle }}</div>
</div>
</body>
</html>

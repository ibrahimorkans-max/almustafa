{{--
    Letterhead and bilingual title bar. Inline sizes: mPDF does not apply nested class rules inside table cells.
    Params: $title (English), $titleUr (Urdu), $small (A5)
--}}
@php
    $nameSize = $small ? '13pt' : '18pt';
    $urSize = $small ? '14pt' : '19pt';
    $lineSize = $small ? '7pt' : '8.5pt';
@endphp
<table class="letterhead">
    <tr>
        <td style="width: {{ $small ? '40%' : '36%' }}; vertical-align: middle;">
            <img src="{{ $brand['logo'] }}" style="height: {{ $small ? '16mm' : '22mm' }};" alt="">
        </td>
        <td style="text-align: right; vertical-align: middle;">
            <div style="font-size: {{ $nameSize }}; font-weight: bold; color: {{ $brand['green'] }};">{{ $brand['name'] }}</div>
            <div style="font-family: lateef; font-size: {{ $urSize }}; color: {{ $brand['green'] }};">{{ $brand['name_ur'] }}</div>
            <div style="font-size: {{ $lineSize }}; color: #2f3b33;">{{ $brand['address'] }}</div>
            <div style="font-size: {{ $lineSize }}; color: #2f3b33;">Phone {{ $brand['phone'] }}@if (! $small && $brand['ntn']) &nbsp;·&nbsp; NTN {{ $brand['ntn'] }}@endif @if (! $small && $brand['strn']) &nbsp;·&nbsp; STRN {{ $brand['strn'] }}@endif</div>
            @if ($small && ($brand['ntn'] || $brand['strn']))
                <div style="font-size: {{ $lineSize }}; color: #2f3b33;">@if ($brand['ntn'])NTN {{ $brand['ntn'] }}@endif @if ($brand['strn']) &nbsp;·&nbsp; STRN {{ $brand['strn'] }}@endif</div>
            @endif
        </td>
    </tr>
</table>
<div class="rule"></div>
<table class="titlebar">
    <tr>
        <td style="font-size: {{ $small ? '10.5pt' : '13pt' }}; font-weight: bold; letter-spacing: 0.6pt; color: #ffffff; padding: 4pt 7pt;">{{ $title }}</td>
        <td style="font-family: lateef; font-size: {{ $small ? '15pt' : '19pt' }}; text-align: right; color: #ffffff; padding: 2pt 7pt;">{{ $titleUr }}</td>
    </tr>
</table>

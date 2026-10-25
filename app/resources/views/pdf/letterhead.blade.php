{{--
    Letterhead and bilingual title bar. Inline sizes: mPDF does not apply nested class rules inside table cells.
    Params: $title (English), $titleUr (Urdu), $small (A5); on A6 and the small roll it is always compact,
    and on the roll (80 mm) the logo and the name are stacked.
--}}
@php
    $small = ($small ?? false) || in_array($paper ?? null, ['a6', 'roll', 'roll50'], true);
    $roll = ($roll ?? false);
    $nameSize = $small ? '13pt' : '18pt';
    $urSize = $small ? '14pt' : '19pt';
    $lineSize = $small ? '7pt' : '8.5pt';
@endphp
@if ($roll)
<div style="text-align: center;">
    @if ($brand['logo'])<img src="{{ $brand['logo'] }}" style="height: 11mm;" alt="">@endif
    <div style="font-size: 10pt; font-weight: bold; color: {{ $brand['green'] }};">{{ $brand['name'] }}</div>
    @if ($brand['name_ur'] !== '')<div style="font-family: lateef; font-size: 12pt; color: {{ $brand['green'] }};">{{ $brand['name_ur'] }}</div>@endif
    <div style="font-size: 6.5pt; color: #2f3b33;">{{ $brand['address'] }}@if ($brand['phone'] !== '') · {{ $brand['phone'] }}@endif</div>
</div>
@else
<table class="letterhead">
    <tr>
        @if ($brand['logo'])
        <td style="width: {{ $small ? '40%' : '36%' }}; vertical-align: middle;">
            <img src="{{ $brand['logo'] }}" style="max-height: {{ $small ? '16mm' : '22mm' }}; max-width: {{ $small ? '45mm' : '65mm' }};" alt="">
        </td>
        @endif
        <td style="text-align: {{ $brand['logo'] ? 'right' : 'left' }}; vertical-align: middle;">
            <div style="font-size: {{ $nameSize }}; font-weight: bold; color: {{ $brand['green'] }};">{{ $brand['name'] }}</div>
            @if ($brand['name_ur'] !== '')<div style="font-family: lateef; font-size: {{ $urSize }}; color: {{ $brand['green'] }};">{{ $brand['name_ur'] }}</div>@endif
            @if ($brand['tagline'] !== '')<div style="font-size: {{ $lineSize }}; font-style: italic; color: {{ $brand['gold'] }};">{{ $brand['tagline'] }}</div>@endif
            <div style="font-size: {{ $lineSize }}; color: #2f3b33;">{{ $brand['address'] }}</div>
            @if (! $small && ($brand['email'] !== '' || $brand['website'] !== ''))<div style="font-size: {{ $lineSize }}; color: #2f3b33;">{{ implode(' · ', array_filter([$brand['email'], $brand['website']])) }}</div>@endif
            <div style="font-size: {{ $lineSize }}; color: #2f3b33;">Phone {{ $brand['phone'] }}@if (! $small && $brand['ntn']) &nbsp;·&nbsp; NTN {{ $brand['ntn'] }}@endif @if (! $small && $brand['strn']) &nbsp;·&nbsp; STRN {{ $brand['strn'] }}@endif</div>
            @if ($small && ($brand['ntn'] || $brand['strn']))
                <div style="font-size: {{ $lineSize }}; color: #2f3b33;">@if ($brand['ntn'])NTN {{ $brand['ntn'] }}@endif @if ($brand['strn']) &nbsp;·&nbsp; STRN {{ $brand['strn'] }}@endif</div>
            @endif
        </td>
    </tr>
</table>
@endif
<div class="rule"></div>
<table class="titlebar">
    <tr>
        <td style="font-size: {{ $small ? '10.5pt' : '13pt' }}; font-weight: bold; letter-spacing: 0.6pt; color: #ffffff; padding: 4pt 7pt;">{{ $title }}</td>
        <td style="font-family: lateef; font-size: {{ $small ? '15pt' : '19pt' }}; text-align: right; color: #ffffff; padding: 2pt 7pt;">{{ $titleUr }}</td>
    </tr>
</table>

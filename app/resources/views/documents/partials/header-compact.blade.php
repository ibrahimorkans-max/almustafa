{{-- One-band header for small paper: logo, mill name (English and Urdu), address, and the title on the right. --}}
<table style="width: 100%; border-bottom: 1.4pt solid {{ $brand['gold'] }};">
    <tr>
        @if ($brand['emblem'])<td style="width: 13mm; vertical-align: middle; padding: 0 2pt 1pt 0;"><img src="{{ $brand['emblem'] }}" style="max-height: 11mm; max-width: 13mm;" alt=""></td>@endif
        <td style="vertical-align: middle;">
            <div style="font-size: 11.5pt; font-weight: bold; color: {{ $accent }};">{{ $brand['name'] }} <span style="font-family: lateef; font-size: 13pt; font-weight: normal;">{{ $brand['name_ur'] }}</span></div>
            <div style="font-size: 6.8pt; color: #2f3b33;">{{ $brand['address'] }} · {{ $brand['phone'] }}@if ($brand['ntn']) · NTN {{ $brand['ntn'] }}@endif</div>
        </td>
        <td style="width: 34%; vertical-align: middle; text-align: right; background-color: {{ $accent }}; color: #ffffff; padding: 2pt 5pt;">
            <div style="font-size: 10pt; font-weight: bold; letter-spacing: 0.5pt;">{{ mb_strtoupper($p->titleEn($doc['title'])) }}</div>
            @if ($p->titleUr($doc['title']) !== '')
                <div style="font-family: lateef; font-size: 13pt;">{{ $p->titleUr($doc['title']) }}</div>
            @endif
        </td>
    </tr>
</table>

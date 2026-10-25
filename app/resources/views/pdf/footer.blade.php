@if ($roll ?? false)
    {{-- Small roll: no pages, the footer is printed at the end of the slip. --}}
    <div style="border-top: 0.6pt dashed #5b6470; padding-top: 1mm; font-size: 6.5pt; color: #374151; text-align: center;">
        @if (($brand['footer_note'] ?? '') !== ''){{ $brand['footer_note'] }}<br>@endif
        {{ $brand['name'] }} · {{ $brand['phone'] }}<br>Printed {{ $printedAt->format('d M Y H:i') }} PKT{{ $printedBy ? ' by '.$printedBy : '' }}
    </div>
@else
@if (($brand['footer_note'] ?? '') !== '')
<div style="text-align: center; font-size: {{ $small ? '6.5pt' : '7.5pt' }}; color: #374151; padding-bottom: 1pt;">{{ $brand['footer_note'] }}</div>
@endif
<table style="width: 100%; border-top: 0.6pt solid #b8c0c8; font-size: {{ $small ? '6.5pt' : '7.5pt' }}; color: #5b6470;">
    <tr>
        <td style="width: 40%;">{{ $brand['name'] }} · {{ $brand['phone'] }}</td>
        <td style="text-align: center;">Printed {{ $printedAt->format('d M Y H:i') }} PKT{{ $printedBy ? ' by '.$printedBy : '' }}</td>
        <td style="width: 20%; text-align: right;">Page {PAGENO} of {nbpg}</td>
    </tr>
</table>
@endif

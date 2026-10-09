<table style="width: 100%; border-top: 0.6pt solid #b8c0c8; font-size: {{ $small ? '6.5pt' : '7.5pt' }}; color: #5b6470;">
    <tr>
        <td style="width: 40%;">{{ $brand['name'] }} · {{ $brand['phone'] }}</td>
        <td style="text-align: center;">Printed {{ $printedAt->format('d M Y H:i') }} PKT{{ $printedBy ? ' by '.$printedBy : '' }}</td>
        <td style="width: 20%; text-align: right;">Page {PAGENO} of {nbpg}</td>
    </tr>
</table>

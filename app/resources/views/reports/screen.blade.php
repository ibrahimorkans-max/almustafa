{{-- A printed screen: a list (columns + rows) or a record sheet (label / value pairs). Param: $sheet --}}
<html>
<head><meta charset="utf-8">@include('pdf.styles')</head>
<body>
@include('pdf.letterhead', ['title' => mb_strtoupper($sheet['title']), 'titleUr' => 'رپورٹ', 'small' => false])

<table class="grid">
    <tr>
        <td class="label">Printed</td><td>{{ $printedAt->format('d M Y H:i') }} PKT{{ $printedBy ? ' by '.$printedBy : '' }}</td>
    </tr>
    @foreach ($sheet['lines'] as $line)
        <tr><td colspan="2" style="font-size: 8pt;">{{ $line }}</td></tr>
    @endforeach
</table>

@if ($sheet['columns'] !== [])
    <table class="grid" repeat_header="1">
        <thead><tr style="font-size: 7.5pt;">
            @foreach ($sheet['columns'] as $col)
                <th>{{ $col }}</th>
            @endforeach
        </tr></thead>
        <tbody>
            @forelse ($sheet['rows'] as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td style="font-size: 7.5pt;">{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($sheet['columns']) }}" style="font-size: 8pt; color: #6b7280;">Nothing to show.</td></tr>
            @endforelse
        </tbody>
    </table>
@endif

@if ($sheet['fields'] !== [])
    <table class="grid">
        @foreach ($sheet['fields'] as [$label, $value])
            <tr><td class="label" style="width: 32%;">{{ $label }}</td><td>{{ $value !== '' ? $value : '—' }}</td></tr>
        @endforeach
    </table>
@endif

@include('pdf.signatures', ['signs' => [['Prepared by', 'تیار کنندہ', $printedBy], ['Checked by (second person)', 'جانچ کنندہ']]])
</body>
</html>

@php
    use App\Domain\Reports\FinancialTables;
    $cell = fn ($c) => is_int($c) ? FinancialTables::money($c) : ($c ?? '');
    $wide = count($table['columns']) > 2;
    $labelWidth = $wide ? 100 - 14 * (count($table['columns']) - 1) : 70;
@endphp
<html>
<head><meta charset="utf-8">@include('pdf.styles')</head>
<body>
@include('pdf.letterhead', ['title' => mb_strtoupper($table['title']), 'titleUr' => 'مالی رپورٹ', 'small' => false])

<table class="grid">
    <tr><td class="label">Report</td><td class="b">{{ $table['title'] }}</td><td class="label">Period</td><td>{{ $table['subtitle'] }}</td></tr>
</table>

<table class="grid" repeat_header="1">
    <thead>
        <tr style="font-size: 8pt;">
            @foreach ($table['columns'] as $i => $col)
                <th style="width: {{ $i === 0 ? $labelWidth : ($wide ? 14 : 30) }}%; {{ $i > 0 ? 'text-align: right;' : '' }}">{{ $i === 0 && $col === '' ? 'Particulars' : $col }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($table['rows'] as $row)
            @php
                $style = match ($row['style']) { 'heading' => 'font-weight: bold; background: #f3f4f6;', 'total' => 'font-weight: bold;', 'grand' => 'font-weight: bold; font-size: 9.5pt; background: #e5e7eb;', default => '' };
            @endphp
            {{-- mPDF does not pass row styles on to the cells, so each cell carries them. --}}
            <tr>
                <td style="font-size: 8pt; {{ $style }} {{ $row['style'] === 'line' ? 'padding-left: 14pt;' : '' }}">{{ $row['label'] }}</td>
                @foreach ($row['cells'] as $c)
                    <td style="font-size: 8pt; text-align: right; {{ $style }}">{{ $cell($c) }}</td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>

@foreach ($table['notes'] as $note)
    <div class="small muted">{{ $note }}</div>
@endforeach
@include('pdf.signatures', ['signs' => [['Accountant', 'اکاؤنٹنٹ'], ['Checked by (second person)', 'جانچ کنندہ'], ['For '.$brand['name'], 'دستخط مجاز']]])
</body>
</html>

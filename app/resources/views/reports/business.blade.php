@php
    use App\Support\Values\Money;
    $cell = fn ($v, int $i, array $money) => in_array($i, $money, true) && is_int($v) ? Money::paise($v)->grouped() : ($v ?? '');
@endphp
<html>
<head><meta charset="utf-8">@include('pdf.styles')</head>
<body>
@include('pdf.letterhead', ['title' => 'COMPLETE BUSINESS REPORT', 'titleUr' => 'مکمل کاروباری رپورٹ', 'small' => false])

<table class="grid">
    <tr>
        <td class="label">Period</td><td class="b">{{ \Carbon\Carbon::parse($report['from'])->format('d M Y') }} – {{ \Carbon\Carbon::parse($report['to'])->format('d M Y') }}</td>
        <td class="label">Prepared</td><td>{{ $report['generated_at'] }}</td>
    </tr>
</table>

<h3 style="font-size: 11pt; margin: 8pt 0 4pt;">Key figures</h3>
<table class="grid">
    @foreach (array_chunk($report['headline'], 2) as $pair)
        <tr>
            @foreach ($pair as [$label, $value])
                <td class="label" style="width: 25%;">{{ $label }}</td><td class="b" style="width: 25%; {{ str_contains($label, 'LOSS') ? 'color: #b91c1c;' : '' }}">{{ $value }}</td>
            @endforeach
        </tr>
    @endforeach
</table>

@foreach ($report['sections'] as $s)
    <h3 style="font-size: 10.5pt; margin: 12pt 0 4pt;">{{ $s['title'] }}</h3>
    @if ($s['rows'] === [])
        <p style="font-size: 8pt; color: #6b7280;">Nothing in this period.</p>
    @else
        <table class="grid" repeat_header="1">
            <thead><tr style="font-size: 7.5pt;">
                @foreach ($s['columns'] as $i => $col)
                    <th style="{{ in_array($i, $s['money'], true) ? 'text-align: right;' : '' }}">{{ $col }}</th>
                @endforeach
            </tr></thead>
            <tbody>
                @foreach ($s['rows'] as $row)
                    <tr>
                        @foreach (array_values($row) as $i => $v)
                            <td style="font-size: 7.5pt; {{ in_array($i, $s['money'], true) ? 'text-align: right;' : '' }}">{{ $cell($v, $i, $s['money']) }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endforeach

@include('pdf.signatures', ['signs' => [['Prepared by (accountant)', 'تیار کنندہ'], ['Checked by (second person)', 'جانچ کنندہ'], ['For '.$brand['name'], 'دستخط مجاز']]])
</body>
</html>

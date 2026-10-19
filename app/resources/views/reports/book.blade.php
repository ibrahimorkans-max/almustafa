{{--
    One or many reports in one PDF (App\Domain\Reports\ReportCatalog::pdf).
    Params: $title, $book = [[category, reports: [[key, title, parts: [[title, subtitle, columns, rows, right, lines]]]]]], $from, $to, $cover (bool)
--}}
@php
    $period = $from->isSameDay($to) ? $from->format('d M Y') : $from->format('d M Y').' – '.$to->format('d M Y');
    $n = 0;
@endphp
<html>
<head><meta charset="utf-8">@include('pdf.styles')
<style>
    .rep td, .rep th { font-size: 7.5pt; padding: 2px 4px; }
    .rep .num { text-align: right; white-space: nowrap; }
    .cat { background: #0f5132; color: #fff; font-weight: bold; font-size: 11pt; padding: 5px 8px; margin: 0 0 6px; }
    .toc td { font-size: 9pt; padding: 3px 6px; }
</style>
</head>
<body>
@include('pdf.letterhead', ['title' => mb_strtoupper($title), 'titleUr' => 'رپورٹ', 'small' => false])

<table class="grid">
    <tr><td class="label" style="width: 15%;">Period</td><td class="b">{{ $period }}</td>
        <td class="label" style="width: 15%;">Printed</td><td>{{ $printedAt->format('d M Y H:i') }} PKT{{ $printedBy ? ' by '.$printedBy : '' }}</td></tr>
</table>

@if ($cover)
    <h3 style="font-size: 12pt; margin: 10pt 0 4pt;">Contents</h3>
    <table class="grid toc">
        @foreach ($book as $c)
            <tr><th colspan="2">{{ $c['category'] }}</th></tr>
            @foreach ($c['reports'] as $r)
                @php($n++)
                <tr><td style="width: 6%;">{{ $n }}.</td><td>{{ $r['title'] }}</td></tr>
            @endforeach
        @endforeach
    </table>
    @php($n = 0)
@endif

@foreach ($book as $c)
    @foreach ($c['reports'] as $r)
        @php($n++)
        @if ($cover || ! $loop->parent->first || ! $loop->first)<pagebreak />@endif
        @if ($cover)<div class="cat">{{ $c['category'] }}</div>@endif
        @foreach ($r['parts'] as $p)
            <h3 style="font-size: 11pt; margin: 8pt 0 3pt;">{{ $cover && $loop->first ? $n.'. ' : '' }}{{ $p['title'] }} <span style="font-weight: normal; color: #6b7280; font-size: 9pt;">{{ $p['subtitle'] }}</span></h3>
            @foreach ($p['lines'] as $line)
                <div class="small muted" style="margin-bottom: 3pt;">{{ $line }}</div>
            @endforeach
            @if ($p['columns'] !== [])
                <table class="grid rep" repeat_header="1">
                    <thead><tr>@foreach ($p['columns'] as $i => $col)<th @if (in_array($i, $p['right'], true)) class="r" @endif>{{ $col }}</th>@endforeach</tr></thead>
                    <tbody>
                        @forelse ($p['rows'] as $row)
                            <tr @if (($row[0] ?? '') === 'TOTAL') class="grand" @endif>
                                @foreach (array_values($row) as $i => $v)<td @if (in_array($i, $p['right'], true)) class="num" @endif>{{ $v }}</td>@endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($p['columns']) }}" class="muted">Nothing for this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            @endif
        @endforeach
    @endforeach
@endforeach

@include('pdf.signatures', ['signs' => [['Prepared by', 'تیار کنندہ'], ['Checked by (second person)', 'جانچ کنندہ'], ['Approved by', 'منظور کنندہ']]])
</body>
</html>

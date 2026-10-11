{{-- One content table: $t = {k, cols, rows, total?}; $cols = indexes of the columns shown in this layout; $tight = small print. --}}
@php
    $tight ??= false;
    $accentColor = $layout === 'minimal' ? '#333333' : $brand['green'];
    $pad = $tight ? '1.2pt 3pt' : '2pt 4pt';
    $th = 'background-color: '.($layout === 'minimal' ? '#ffffff' : '#eef3ef').'; border-bottom: 0.8pt solid '.$accentColor.'; padding: '.$pad.'; font-weight: bold;';
    $td = 'padding: '.$pad.'; border-bottom: 0.4pt solid #d5dbe0;';
    $isNum = fn ($c) => \App\Domain\Documents\Engine\DocumentPresenter::numeric($c);
@endphp
<div style="margin-top: {{ $tight ? '3pt' : '5pt' }}; font-weight: bold; color: {{ $accentColor }}; font-size: {{ $tight ? '8pt' : '9pt' }};">{{ $p->label($t['k']) }}</div>
<table style="width: 100%; border: 0.4pt solid #b8c0c8; font-size: {{ $tight ? '7.5pt' : '8.5pt' }};">
    <tr>
        @foreach ($cols as $i)
            @php $numCol = isset($t['rows'][0][$i]) && $isNum($t['rows'][0][$i]); @endphp
            <td style="{{ $th }} text-align: {{ $numCol ? 'right' : 'left' }};">{{ $p->label($t['cols'][$i]) }}</td>
        @endforeach
    </tr>
    @foreach ($t['rows'] as $row)
        <tr>
            @foreach ($cols as $i)
                @php $cell = $row[$i] ?? null; @endphp
                <td style="{{ $td }} {{ $isNum($cell) ? 'text-align: right; white-space: nowrap;' : '' }} {{ $i > 0 && ! $isNum($cell) && count($cols) > 2 ? 'font-size: 85%; color: #3a4a40;' : '' }}">{{ $p->cell($cell) }}</td>
            @endforeach
        </tr>
    @endforeach
    @isset($t['total'])
        <tr>
            @foreach ($cols as $i)
                @php $cell = $t['total'][$i] ?? null; @endphp
                <td style="{{ $td }} font-weight: bold; background-color: {{ $layout === 'minimal' ? '#ffffff' : '#eef3ef' }}; {{ $isNum($cell) ? 'text-align: right; white-space: nowrap;' : '' }}">{{ $cell === null ? '' : $p->cell($cell) }}</td>
            @endforeach
        </tr>
    @endisset
</table>

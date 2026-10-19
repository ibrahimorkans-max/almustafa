{{--
    One layout for the printed records that had none: voucher, expense, claim, sauda, dispatch order,
    stores issue slip, production batch. Params: $doc = [title, titleUr, small, fields: [[label, value], ...],
    table?: [columns, rows, right: [col index...], totals?: [[label, value], ...]], words?, notes?, signs: [[en, ur, name?], ...]]
--}}
@php($small = $doc['small'] ?? false)
<html>
<head><meta charset="utf-8">@include('pdf.styles')</head>
<body>
@include('pdf.letterhead', ['title' => $doc['title'], 'titleUr' => $doc['titleUr'], 'small' => $small])

<table class="grid">
    @foreach (array_chunk($doc['fields'], 2) as $pair)
        <tr>
            @foreach ($pair as [$label, $value])
                <td class="label">{{ $label }}</td><td @if (count($pair) === 1) colspan="3" @endif>{{ $value }}</td>
            @endforeach
        </tr>
    @endforeach
</table>

@if (! empty($doc['table']))
    <table class="grid">
        <tr>
            @foreach ($doc['table']['columns'] as $i => $col)
                <th @if (in_array($i, $doc['table']['right'] ?? [], true)) class="r" @endif>{{ $col }}</th>
            @endforeach
        </tr>
        @foreach ($doc['table']['rows'] as $row)
            <tr>
                @foreach (array_values($row) as $i => $v)
                    <td @if (in_array($i, $doc['table']['right'] ?? [], true)) class="num" @endif>{{ $v }}</td>
                @endforeach
            </tr>
        @endforeach
        @foreach ($doc['table']['totals'] ?? [] as [$label, $value])
            <tr class="grand"><td colspan="{{ count($doc['table']['columns']) - 1 }}">{{ $label }}</td><td class="num">{{ $value }}</td></tr>
        @endforeach
    </table>
@endif

@if (! empty($doc['words']))
    <div class="words">{{ $doc['words'] }}</div>
@endif
@foreach ($doc['notes'] ?? [] as $note)
    <div class="box small">{{ $note }}</div>
@endforeach

@include('pdf.verify', ['small' => $small])
@include('pdf.signatures', ['signs' => $doc['signs']])
</body>
</html>

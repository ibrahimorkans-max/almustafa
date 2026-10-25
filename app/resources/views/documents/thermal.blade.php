{{-- Narrow receipt (80 / 58 mm): one column, figures right-aligned. Same content as the full layouts. --}}
@php
    $row = 'padding: 0.8pt 0; border-bottom: 0.3pt dotted #999999;';
    $plain = fn ($cell) => $p->cell($cell);
@endphp
<style>
    body { font-family: dejavusanscondensed; color: #000000; }
    .ur { font-family: lateef; font-size: 140%; }
    table { border-collapse: collapse; width: 100%; }
</style>
<div @if ($p->rtl()) dir="rtl" @endif>
    @if ($brand['emblem'])<div style="text-align: center;"><img src="{{ $brand['emblem'] }}" style="max-height: 12mm; max-width: 30mm;" alt=""></div>@endif
    <div style="text-align: center; font-weight: bold; font-size: 115%;">{{ $brand['name'] }}</div>
    @if ($brand['name_ur'] !== '')<div style="text-align: center; font-family: lateef; font-size: 130%;">{{ $brand['name_ur'] }}</div>@endif
    <div style="text-align: center; font-size: 85%;">{{ $brand['address'] }} · {{ $brand['phone'] }}</div>
    <div style="text-align: center; font-weight: bold; border-top: 0.6pt solid #000; border-bottom: 0.6pt solid #000; margin: 2pt 0; padding: 1pt 0;">{{ $p->title($doc['title']) }}</div>
    @if ($watermark)
        <div style="text-align: center; font-weight: bold;">*** {{ $watermark }} ***</div>
    @endif
    <table>
        @foreach ($doc['fields'] ?? [] as $f)
            @php $value = isset($f[2]) && isset($unmasked[$f[2]]) ? $unmasked[$f[2]] : $f[1]; @endphp
            <tr><td style="{{ $row }}">{{ $p->label($f[0]) }}</td><td style="{{ $row }} text-align: right; font-weight: bold;">{{ $plain($value) }}</td></tr>
        @endforeach
    </table>
    @foreach ($doc['paragraphs'] ?? [] as [$key, $vars])
        <div style="margin-top: 3pt;">{!! $p->text($key, $vars) !!}</div>
    @endforeach
    @php
        $blocks = $doc['tables'] ?? [];
        foreach ($doc['extras'] ?? [] as $key => $extra) {
            if ($key !== 'calendar' && ($show[$key] ?? $key === 'attendance') && ($extra['rows'] ?? []) !== []) {
                $blocks[] = $extra;
            }
        }
    @endphp
    @foreach ($blocks as $t)
        <div style="margin-top: 3pt; font-weight: bold; border-bottom: 0.6pt solid #000;">{{ $p->label($t['k']) }}</div>
        <table>
            @foreach ($t['rows'] as $r)
                <tr><td style="{{ $row }}">{{ $plain($r[0]) }}</td><td style="{{ $row }} text-align: right; white-space: nowrap;">{{ $plain(end($r)) }}</td></tr>
            @endforeach
            @isset($t['total'])
                <tr><td style="font-weight: bold;">{{ $plain($t['total'][0]) }}</td><td style="text-align: right; font-weight: bold;">{{ $plain(end($t['total'])) }}</td></tr>
            @endisset
        </table>
    @endforeach
    @if (($doc['summary'] ?? []) !== [])
        <table style="margin-top: 3pt; border-top: 0.8pt solid #000;">
            @foreach ($doc['summary'] as $line)
                <tr><td style="padding: 0.8pt 0; {{ ($line[2] ?? false) ? 'font-weight: bold; font-size: 115%;' : '' }}">{{ $p->label($line[0]) }}</td>
                    <td style="text-align: right; white-space: nowrap; {{ ($line[2] ?? false) ? 'font-weight: bold; font-size: 115%;' : '' }}">{{ $p->rupees((int) $line[1]) }}</td></tr>
            @endforeach
        </table>
    @endif
    @if (($doc['words'] ?? null) !== null)
        <div style="margin-top: 2pt; font-size: 90%;">{{ $p->words((int) $doc['words']) }}</div>
    @endif
    @foreach ($doc['status'] ?? [] as [$key, $cell])
        <div style="font-size: 90%;">{{ $p->label($key) }}: {{ $plain($cell) }}</div>
    @endforeach
    @if (($show['signatures'] ?? false))
        @foreach ($doc['signatures'] ?? [] as $sig)
            <div style="margin-top: 12pt; border-top: 0.5pt solid #000; width: 60%; font-size: 85%;">{{ $p->label($sig) }}</div>
        @endforeach
    @endif
    @if ($verifyUrl)
        <div style="text-align: center; margin-top: 4pt;"><barcode code="{{ $verifyUrl }}" type="QR" size="0.55" error="M" disableborder="1" /></div>
        <div style="text-align: center; font-size: 85%;">{{ $verifyDoc }} · {{ $verifyCode }}</div>
    @endif
</div>

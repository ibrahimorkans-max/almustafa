{{--
    The weighbridge as one analog clock: everything is on its face.
     - the needle sweeps 240° from 0 to the capacity (main marks in tonnes) and glides to each new reading;
     - the ring and the lamp are green when stable, amber while moving, grey without a signal (with how long ago);
     - the exact weight is in the digital window under the needle, the maund below it.
    Params: $kg (float|null), $capacity (float), $live (bool), $stable (bool), $code, $name (string), $age (int|null seconds)
    The parts marked data-lw are kept current in the browser (public/js/live-weight.js), without redrawing the page.
--}}
@php
    $capacity = max(1.0, (float) $capacity);
    $raw = $capacity / 6;
    $pow = 10 ** floor(log10($raw));
    $major = collect([1, 2, 2.5, 5, 10])->map(fn ($m) => $m * $pow)->first(fn ($m) => $m >= $raw) ?? 10 * $pow;
    $minor = $major / 5;
    $tonnes = $capacity >= 1000;
    $c = 120;
    $angleOf = fn (float $v) => -120 + 240 * max(0, min($v, $capacity)) / $capacity;
    $point = fn (float $deg, float $r) => [$c + $r * sin(deg2rad($deg)), $c - $r * cos(deg2rad($deg))];
    $needle = $kg === null || ! $live ? -120 : $angleOf((float) $kg);
    $tone = ! $live ? '--gray-400' : ($stable ? '--success-500' : '--warning-500');
    $status = ! $live ? 'NO SIGNAL'.($age !== null ? ' · '.$age.' s' : '') : ($stable ? 'STABLE' : 'MOVING');
    $label = fn (float $v) => rtrim(rtrim(number_format($tonnes ? $v / 1000 : $v, 1, '.', ''), '0'), '.');
    $statusWidth = mb_strlen($status) * 7.0;   // about 7 px a letter at this size
    $statusStart = $c - ($statusWidth + 13) / 2 + 13;   // the lamp and the words, centred together
    $figure = $kg === null ? '— — — —' : number_format((float) $kg, 0);
@endphp
<svg viewBox="0 0 240 240" role="img" style="width: 100%; max-width: 240px; height: auto; display: block; margin: 0 auto;"
    aria-label="{{ $name }}: {{ $kg === null ? 'no reading' : number_format((float) $kg, 0).' kg' }}, {{ strtolower($status) }}">
    {{-- Bezel (status colour) and face --}}
    <circle data-lw="bezel" cx="{{ $c }}" cy="{{ $c }}" r="116" style="fill: rgb(var({{ $tone }})); transition: fill .4s;"/>
    <circle cx="{{ $c }}" cy="{{ $c }}" r="109" style="fill: #fbfbf8; stroke: rgb(var(--gray-300)); stroke-width: 1;"/>

    {{-- Marks and tonnes --}}
    @for ($v = 0; $v <= $capacity + 0.001; $v += $minor)
        @php
            $isMajor = abs(fmod($v, $major)) < 0.001 || abs(fmod($v, $major) - $major) < 0.001;
            [$x1, $y1] = $point($angleOf($v), 102);
            [$x2, $y2] = $point($angleOf($v), $isMajor ? 88 : 95);
        @endphp
        <line x1="{{ $x1 }}" y1="{{ $y1 }}" x2="{{ $x2 }}" y2="{{ $y2 }}"
            style="stroke: {{ $isMajor ? '#1f2937' : '#9ca3af' }}; stroke-width: {{ $isMajor ? 2.6 : 1.1 }}; stroke-linecap: round;"/>
        @if ($isMajor)
            @php([$tx, $ty] = $point($angleOf($v), 74))
            <text x="{{ $tx }}" y="{{ $ty + 4 }}" text-anchor="middle" style="font: 700 12px sans-serif; fill: #1f2937;">{{ $label($v) }}</text>
        @endif
    @endfor

    {{-- Weighbridge and status, on the face --}}
    <text x="{{ $c }}" y="62" text-anchor="middle" style="font: 600 8.5px sans-serif; letter-spacing: .8px; fill: #6b7280;">{{ strtoupper($code) }} · {{ $tonnes ? 'TONNES' : 'KG' }}</text>
    <circle data-lw="lamp" cx="{{ $statusStart - 9 }}" cy="209.5" r="4.2" style="fill: rgb(var({{ $tone }}));"/>
    <text data-lw="status" x="{{ $statusStart }}" y="213" style="font: 700 9.5px sans-serif; letter-spacing: .6px; fill: rgb(var({{ $tone === '--gray-400' ? '--gray-500' : $tone }}));">{{ $status }}</text>

    {{-- Digital window: the exact weight --}}
    <rect x="{{ $c - 46 }}" y="150" width="92" height="30" rx="5" style="fill: #111827;"/>
    <text data-lw="figure" x="{{ $c + 28 }}" y="171.5" text-anchor="end"
        style="font: 700 18px ui-monospace, Menlo, Consolas, monospace; fill: {{ $live ? '#4ade80' : '#6b7280' }};">{{ $figure }}</text>
    <text x="{{ $c + 42 }}" y="171" text-anchor="end" style="font: 600 9px sans-serif; fill: #9ca3af;">kg</text>
    <text data-lw="maund" x="{{ $c }}" y="194" text-anchor="middle" style="font: 600 10px sans-serif; fill: #4b5563;">{{ $kg !== null ? number_format((float) $kg / 40, 2).' maund' : 'no reading yet' }}</text>

    {{-- Needle: glides to the reading --}}
    <g data-lw="needle" style="transform: rotate({{ $needle }}deg); transform-origin: {{ $c }}px {{ $c }}px; transition: transform .9s cubic-bezier(.2,.8,.2,1);">
        <polygon points="{{ $c }},26 {{ $c + 4 }},{{ $c + 4 }} {{ $c - 4 }},{{ $c + 4 }}" style="fill: #dc2626;"/>
        <polygon points="{{ $c - 3.5 }},{{ $c + 4 }} {{ $c + 3.5 }},{{ $c + 4 }} {{ $c + 2 }},{{ $c + 18 }} {{ $c - 2 }},{{ $c + 18 }}" style="fill: #1f2937;"/>
    </g>
    <circle cx="{{ $c }}" cy="{{ $c }}" r="8.5" style="fill: #1f2937; stroke: #fbfbf8; stroke-width: 2.5;"/>
</svg>

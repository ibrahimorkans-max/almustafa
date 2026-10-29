{{--
    One document in one of the four layouts. $doc is the frozen content, $p the presenter (language and digits),
    $s the template version settings, $show the toggles. Inline sizes: mPDF ignores nested class rules in table cells.
    Small paper (A5, half A4) and the compact layout pack the content side by side so a slip fits one page.
--}}
@php
    $minimal = $layout === 'minimal';
    $compact = $layout === 'compact';
    $detailed = $layout === 'detailed';
    $tight = $small || $compact;
    $accent = $minimal ? '#333333' : $brand['green'];
    $fields = $doc['fields'] ?? [];
    $tables = $doc['tables'] ?? [];
    $pairs = $tight ? 3 : 2;
    $sideBySide = count($tables) === 2 && ($tight || $layout === 'classic');
    $td = 'padding: '.($tight ? '1.2pt 3pt' : '2pt 4pt').'; border-bottom: 0.4pt solid #d5dbe0;';
    $visibleCols = fn (array $t) => array_values(array_filter(array_keys($t['cols']), fn ($i) => $detailed || ! in_array($i, $t['detail'] ?? [], true)));
    $extras = [];
    $calendar = null;
    foreach ($doc['extras'] ?? [] as $key => $extra) {
        $on = $key === 'attendance' ? ! $minimal : (bool) ($show[$key] ?? false);
        if (! $on) {
            continue;
        }
        if ($key === 'calendar') {
            $calendar = $extra;
        } elseif (($extra['rows'] ?? []) !== []) {
            $extras[] = $extra;
        }
    }
    // On small paper each extra prints as a one-line strip (item and its last figure).
    $strips = $tight ? $extras : [];
    $extras = $tight ? [] : $extras;
    // Extras share the width: as many per row as there are (up to four), so small paper keeps them short.
    $perRow = max(1, min(4, count($extras)));
    // On small paper the gross and deduction totals are already in the tables: only net pay is repeated, beside the words.
    $summary = $tight && $sideBySide ? array_values(array_filter($doc['summary'] ?? [], fn ($l) => $l[2] ?? false)) : ($doc['summary'] ?? []);
    $signs = ($show['signatures'] ?? false) ? ($doc['signatures'] ?? []) : [];
@endphp

{{-- Header --}}
@if ($minimal)
    <table style="width: 100%; border-bottom: 0.8pt solid #333333;">
        <tr>
            <td style="font-size: {{ $small ? '11pt' : '14pt' }}; font-weight: bold;">{{ $brand['name'] }}</td>
            <td style="text-align: right; font-size: {{ $small ? '10pt' : '12pt' }}; font-weight: bold;">{{ $p->title($doc['title']) }}</td>
        </tr>
        <tr><td colspan="2" style="font-size: 7pt; color: #555555;">{{ $brand['address'] }} · {{ $brand['phone'] }}@if ($brand['ntn']) · NTN {{ $brand['ntn'] }}@endif</td></tr>
    </table>
@elseif ($tight)
    @include('documents.partials.header-compact')
@else
    @include('pdf.letterhead', ['title' => mb_strtoupper($p->titleEn($doc['title'])), 'titleUr' => $p->titleUr($doc['title']), 'small' => false])
@endif
@if (($s['header_text'] ?? '') !== '')
    <div style="font-size: 7.5pt; color: #5b6470; margin-top: 1pt;">{{ $s['header_text'] }}</div>
@endif
@if (($doc['revision'] ?? 0) > 0)
    <div style="border: 0.8pt solid #c9a227; background-color: #fff8e1; padding: 2pt 5pt; margin-top: 2pt; font-size: 8pt;">{{ $p->label('revision') }} R{{ $doc['revision'] }} — {{ $p->label('supersedes') }} {{ $doc['number'] }}@if (($doc['revision_reason'] ?? null) !== null): {{ $doc['revision_reason'] }}@endif</div>
@endif

{{-- Fields --}}
<table style="width: 100%; margin-top: 3pt; border: 0.6pt solid #b8c0c8;">
    @foreach (array_chunk($fields, $pairs) as $row)
        <tr>
            @foreach ($row as $f)
                @php $value = isset($f[2]) && isset($unmasked[$f[2]]) ? $unmasked[$f[2]] : $f[1]; @endphp
                <td style="{{ $td }} color: #3a4a40; background-color: {{ $minimal ? '#ffffff' : '#f6f8f6' }}; white-space: nowrap;">{{ $p->label($f[0]) }}</td>
                <td style="{{ $td }} font-weight: bold;">{{ $p->cell($value) }}</td>
            @endforeach
            @for ($i = count($row); $i < $pairs; $i++)
                <td style="{{ $td }}"></td><td style="{{ $td }}"></td>
            @endfor
        </tr>
    @endforeach
</table>

{{-- Paragraphs (certificates, receipts) --}}
@foreach ($doc['paragraphs'] ?? [] as [$key, $vars])
    @php $vars = array_map(fn ($v) => is_array($v) && isset($v['s']) && isset($unmasked[$v['s']]) ? $unmasked[$v['s']] : $v, $vars); @endphp
    <div style="margin-top: {{ $tight ? '6pt' : '10pt' }}; font-size: {{ $tight ? '9pt' : '10.5pt' }}; line-height: 1.5;">{!! $p->text($key, $vars) !!}</div>
@endforeach

{{-- Main tables --}}
@if ($sideBySide)
    <table style="width: 100%;"><tr>
    @foreach ($tables as $t)
        <td style="width: 50%; vertical-align: top; padding: 0; {{ $loop->first ? 'padding-right: 3pt;' : 'padding-left: 3pt;' }}">
            @include('documents.partials.table', ['t' => $t, 'cols' => $visibleCols($t), 'tight' => $tight])
        </td>
    @endforeach
    </tr></table>
@else
    @foreach ($tables as $t)
        @include('documents.partials.table', ['t' => $t, 'cols' => $visibleCols($t), 'tight' => $tight])
    @endforeach
@endif

{{-- Amount in words beside the totals --}}
@if ($summary !== [] || ($doc['words'] ?? null) !== null)
    <table style="width: 100%; margin-top: 4pt;">
        <tr>
            <td style="width: {{ $tight ? '62%' : '52%' }}; vertical-align: middle; padding-right: 6pt;">
                @if (($doc['words'] ?? null) !== null)
                    <div style="border-left: 2.5pt solid {{ $minimal ? '#333333' : $brand['gold'] }}; background-color: {{ $minimal ? '#ffffff' : '#fdf7ea' }}; padding: 3pt 6pt; font-weight: bold; font-size: {{ $tight ? '8pt' : '9pt' }};">
                        {{ $p->label('amount_in_words') }}: {{ $p->words((int) $doc['words']) }}
                    </div>
                @endif
            </td>
            <td style="width: {{ $tight ? '38%' : '48%' }}; vertical-align: {{ $tight ? 'middle' : 'top' }};">
                <table style="width: 100%;">
                    @foreach ($summary as $line)
                        @php $st = ($line[2] ?? false) ? 'background-color: '.$accent.'; color: #ffffff; font-weight: bold; font-size: 110%;' : 'border-bottom: 0.4pt solid #d5dbe0;'; @endphp
                        <tr>
                            <td style="padding: 2pt 5pt; {{ $st }}">{{ $p->label($line[0]) }}</td>
                            <td style="padding: 2pt 5pt; text-align: right; white-space: nowrap; {{ $st }}">{{ $p->rupees((int) $line[1]) }}</td>
                        </tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>
@endif

{{-- Extras (by toggle): strips on small paper, else side by side --}}
@foreach ($strips as $strip)
    <table style="width: 100%; margin-top: 2.5pt; border: 0.4pt solid #b8c0c8; font-size: 7pt;">
        <tr>
            <td style="width: 15%; padding: 1.2pt 3pt; font-weight: bold; color: #ffffff; background-color: {{ $accent }};">{{ $p->label($strip['k']) }}</td>
            @foreach ($strip['rows'] as $r)
                <td style="padding: 1.2pt 3pt; border-left: 0.3pt solid #d5dbe0;">{{ $p->cell($r[0]) }} <b>{{ $p->cell(end($r)) }}</b></td>
            @endforeach
        </tr>
    </table>
@endforeach
@foreach (array_chunk($extras, $perRow) as $chunk)
    <table style="width: 100%;"><tr>
        @foreach ($chunk as $extra)
            <td style="width: {{ floor(100 / $perRow) }}%; vertical-align: top; padding: 0 {{ $loop->last ? '0' : '4pt' }} 0 0;">
                @include('documents.partials.table', ['t' => $extra, 'cols' => $visibleCols($extra), 'tight' => true])
            </td>
        @endforeach
        @for ($i = count($chunk); $i < $perRow; $i++)
            <td style="width: {{ floor(100 / $perRow) }}%;"></td>
        @endfor
    </tr></table>
@endforeach
@if ($calendar !== null && $calendar !== [])
    <table style="width: 100%; margin-top: 4pt; border: 0.4pt solid #b8c0c8; font-size: {{ $tight ? '5.5pt' : '6.5pt' }};">
        <tr>@foreach ($calendar as [$day, $code])<td style="text-align: center; border-right: 0.3pt solid #d5dbe0; padding: 0.6pt 0; color: #5b6470;">{{ $p->num((string) $day) }}</td>@endforeach</tr>
        <tr>@foreach ($calendar as [$day, $code])<td style="text-align: center; border-right: 0.3pt solid #d5dbe0; padding: 0.6pt 0; font-weight: bold; color: {{ in_array($code, ['A', 'MO'], true) ? '#9b1c1c' : '#1a1a1a' }};">{{ $code }}</td>@endforeach</tr>
    </table>
@endif

{{-- Attached images (receipts) --}}
@if (($doc['images'] ?? []) !== [])
    <div style="margin-top: 5pt; font-weight: bold; color: {{ $accent }}; font-size: 8.5pt;">{{ $p->label('receipts') }}</div>
    @foreach (array_chunk($doc['images'], 4) as $row)
        <table style="width: 100%;"><tr>
            @foreach ($row as [$caption, $fileId])
                @php $src = $imageSrc($fileId); @endphp
                <td style="width: 25%; text-align: center; vertical-align: top; padding: 2pt;">
                    @if ($src)<img src="{{ $src }}" style="max-height: 38mm; max-width: 42mm; border: 0.4pt solid #b8c0c8;" alt="">@endif
                    <div style="font-size: 6.5pt; color: #5b6470;">{{ $caption }}</div>
                </td>
            @endforeach
            @for ($i = count($row); $i < 4; $i++)<td style="width: 25%;"></td>@endfor
        </tr></table>
    @endforeach
@endif

{{-- Accounting link, terms --}}
@if (($doc['status'] ?? []) !== [])
    <table style="width: 100%; margin-top: 3pt; font-size: 7.5pt;">
        <tr>
            @foreach ($doc['status'] as [$key, $cell])
                <td style="color: #3a4a40;">{{ $p->label($key) }}: <b>{{ $p->cell($cell) }}</b></td>
            @endforeach
        </tr>
    </table>
@endif
@if (($s['terms'] ?? '') !== '')
    <div style="margin-top: 3pt; font-size: 7pt; color: #5b6470;">{!! nl2br(e($s['terms'])) !!}</div>
@endif

{{-- Signatures (with stamp) and verification on one band --}}
@if ($signs !== [] || $verifyUrl)
    <table style="width: 100%; margin-top: {{ $tight ? '5pt' : '16pt' }};">
        <tr>
            @foreach ($signs as $sig)
                <td style="text-align: center; vertical-align: bottom; padding: 0 5pt;">
                    @if ($loop->first && $stamp)
                        <img src="{{ $stamp }}" style="height: {{ $tight ? '11mm' : '16mm' }};" alt="">
                    @endif
                    @if ($loop->last && $signatureImage)
                        <img src="{{ $signatureImage }}" style="height: {{ $tight ? '8mm' : '11mm' }};" alt="">
                    @endif
                    <div style="border-top: 0.6pt solid #555555; padding-top: 1.5pt; font-size: 7pt; color: #3a4a40;">{{ $p->label($sig) }}</div>
                </td>
            @endforeach
            @if ($verifyUrl)
                <td style="width: {{ $tight ? '42%' : '38%' }}; vertical-align: bottom;">
                    <table style="width: 100%; border: 0.6pt solid #b8c0c8;">
                        <tr>
                            <td style="width: {{ $tight ? '14mm' : '22mm' }}; padding: 1.5pt;">
                                @if ($web)
                                    {!! $qrSvg !!}
                                @else
                                    <barcode code="{{ $verifyUrl }}" type="QR" size="{{ $tight ? '0.42' : '0.62' }}" error="M" disableborder="1" />
                                @endif
                            </td>
                            <td style="padding: 2pt 4pt; font-size: {{ $tight ? '6.5pt' : '7.5pt' }};">
                                <div><b>{{ $verifyDoc }}</b></div>
                                <div>Code <b style="font-family: dejavusansmono; letter-spacing: 0.6pt;">{{ $verifyCode }}</b></div>
                                <div style="color: #5b6470;">{{ url('/verify') }}</div>
                            </td>
                        </tr>
                    </table>
                </td>
            @endif
        </tr>
    </table>
@endif
@if (($s['footer_text'] ?? '') !== '')
    <div style="margin-top: 3pt; font-size: 7pt; color: #5b6470; text-align: center;">{{ $s['footer_text'] }}</div>
@endif
@if ($verifyUrl && ! $tight)
    <div style="margin-top: 2pt; font-size: 7pt; color: #5b6470;">{!! $p->text('computer_generated', []) !!}</div>
@endif

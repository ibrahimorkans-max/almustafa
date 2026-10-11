<?php

declare(strict_types=1);

namespace App\Domain\Documents\Engine;

/**
 * A document as fixed-width text for receipt printers (48 columns on 80 mm, 32 on 58 mm). English
 * only: most receipt printers have no Urdu glyphs. Same figures as the PDF.
 */
final class ThermalText
{
    /**
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $brand
     * @param  array<string, bool>  $show
     * @param  array{doc: string, code: string}|null  $verify
     */
    public static function render(array $doc, DocumentPresenter $p, array $brand, array $show, int $cols, ?string $watermark, ?array $verify): string
    {
        $out = [];
        $center = fn (string $s) => str_pad(mb_strimwidth($s, 0, $cols), $cols, ' ', STR_PAD_BOTH);
        $pair = function (string $l, string $r) use ($cols): string {
            $r = mb_strimwidth($r, 0, $cols - 2);
            $l = mb_strimwidth($l, 0, max(1, $cols - mb_strlen($r) - 1));

            return $l.str_repeat(' ', max(1, $cols - mb_strlen($l) - mb_strlen($r))).$r;
        };
        $rule = str_repeat('-', $cols);

        $out[] = $center((string) $brand['name']);
        foreach (explode("\n", wordwrap((string) $brand['address'], $cols, "\n", true)) as $line) {
            $out[] = $center($line);
        }
        $out[] = $center('Ph '.$brand['phone']);
        $out[] = $rule;
        $out[] = $center(mb_strtoupper($p->titleEn((string) $doc['title'])));
        if ($watermark !== null) {
            $out[] = $center('*** '.$watermark.' ***');
        }
        $out[] = $rule;
        foreach ($doc['fields'] ?? [] as $f) {
            $out[] = $pair($p->plain(['k' => $f[0]]), $p->plain($f[1]));
        }
        foreach ($doc['tables'] ?? [] as $t) {
            $out[] = $rule;
            $out[] = mb_strtoupper($p->plain(['k' => $t['k']]));
            foreach ($t['rows'] as $row) {
                $out[] = $pair($p->plain($row[0]), $p->plain(end($row)));
            }
            if (isset($t['total'])) {
                $out[] = $pair($p->plain($t['total'][0]), $p->plain(end($t['total'])));
            }
        }
        foreach ($doc['extras'] ?? [] as $key => $t) {
            if (($show[$key] ?? false) && isset($t['rows']) && $t['rows'] !== []) {
                $out[] = $rule;
                $out[] = mb_strtoupper($p->plain(['k' => $t['k']]));
                foreach ($t['rows'] as $row) {
                    $out[] = $pair($p->plain($row[0]), $p->plain(end($row)));
                }
            }
        }
        if (($doc['summary'] ?? []) !== []) {
            $out[] = str_repeat('=', $cols);
            foreach ($doc['summary'] as $s) {
                $out[] = $pair($p->plain(['k' => $s[0]]), $p->rupees((int) $s[1]));
            }
        }
        if (($doc['words'] ?? null) !== null) {
            $out[] = wordwrap($p->plain(['k' => 'amount_in_words']).': '.strip_tags((string) $p->words((int) $doc['words'])), $cols, "\n", true);
        }
        foreach ($doc['paragraphs'] ?? [] as [$key, $vars]) {
            $out[] = wordwrap(strip_tags((string) __("documents.text.{$key}", DocumentPresenter::vars($vars, 'en'), 'en')), $cols, "\n", true);
        }
        foreach ($doc['status'] ?? [] as $st) {
            $out[] = $pair($p->plain(['k' => $st[0]]), $p->plain($st[1]));
        }
        if ($show['signatures'] ?? false) {
            foreach ($doc['signatures'] ?? [] as $sig) {
                $out[] = '';
                $out[] = '';
                $out[] = str_repeat('_', min(28, $cols));
                $out[] = $p->plain(['k' => $sig]);
            }
        }
        if ($verify !== null && ($show['qr'] ?? false)) {
            $out[] = $rule;
            $out[] = 'Verify: '.$verify['doc'];
            $out[] = 'Code: '.$verify['code'];
            $out[] = wordwrap(url('/verify'), $cols, "\n", true);
        }

        return implode("\n", $out);
    }
}

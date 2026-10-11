<?php

declare(strict_types=1);

namespace App\Support\Export;

use RuntimeException;
use ZipArchive;

/**
 * A minimal Excel (XLSX) writer for registers and reports: one sheet, a bold header row, text and
 * numbers (numbers stay numbers so Excel can add them up). No dependencies beyond ext-zip.
 */
final class XlsxWriter
{
    /**
     * @param  list<string>  $header
     * @param  iterable<array<int, string|int|float|null>>  $rows
     */
    public static function build(string $sheetName, array $header, iterable $rows): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tmp === false) {
            throw new RuntimeException('Cannot create a temporary file.');
        }
        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot write the Excel file.');
        }
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";
        $zip->addFromString('[Content_Types].xml', $xml.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $xml.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.self::esc(mb_substr($sheetName, 0, 31)).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', $xml.'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs>'
            .'<cellXfs count="2"><xf fontId="0"/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');

        $sheet = $xml.'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $sheet .= self::row(1, $header, true);
        $r = 1;
        foreach ($rows as $row) {
            $sheet .= self::row(++$r, array_values($row), false);
        }
        $sheet .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /** @param  list<string|int|float|null>  $cells */
    private static function row(int $r, array $cells, bool $bold): string
    {
        $out = '<row r="'.$r.'">';
        foreach ($cells as $i => $v) {
            $ref = self::col($i).$r;
            $style = $bold ? ' s="1"' : '';
            if (is_int($v) || is_float($v)) {
                $out .= '<c r="'.$ref.'"'.$style.'><v>'.$v.'</v></c>';
            } elseif ($v !== null && $v !== '') {
                $out .= '<c r="'.$ref.'"'.$style.' t="inlineStr"><is><t xml:space="preserve">'.self::esc((string) $v).'</t></is></c>';
            }
        }

        return $out.'</row>';
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26).$s;
        }

        return $s;
    }

    private static function esc(string $v): string
    {
        // Spreadsheet formula injection: a leading = + - @ is shown as text.
        $v = preg_match('/^[=+\-@]/', $v) === 1 ? "'".$v : $v;

        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

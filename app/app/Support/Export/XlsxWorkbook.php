<?php

declare(strict_types=1);

namespace App\Support\Export;

use RuntimeException;
use ZipArchive;

/**
 * A multi-sheet Excel (.xlsx) workbook (XlsxWriter writes a single sheet): several sheets of text and numbers, a bold header row, frozen
 * first row and set column widths. Enough for report exports, without an extra package.
 * Numbers stay numbers in Excel (they can be summed); money is written in rupees with a
 * "#,##,##0.00" format so Excel shows Rs 1,25,000.00 style grouping.
 */
final class XlsxWorkbook
{
    /** @var list<array{name: string, rows: list<list<string|int|float|null>>, money: list<int>, widths: list<int>}> */
    private array $sheets = [];

    /**
     * @param  list<string>  $header
     * @param  list<list<string|int|float|null>>  $rows
     * @param  list<int>  $moneyColumns  0-based columns whose values are rupees
     */
    public function addSheet(string $name, array $header, array $rows, array $moneyColumns = []): self
    {
        $all = array_merge([$header], $rows);
        $widths = [];
        foreach ($all as $row) {
            foreach (array_values($row) as $i => $cell) {
                $widths[$i] = min(60, max($widths[$i] ?? 8, mb_strlen((string) $cell) + 2));
            }
        }
        $this->sheets[] = ['name' => $this->sheetName($name), 'rows' => $all, 'money' => $moneyColumns, 'widths' => $widths];

        return $this;
    }

    public function toString(): string
    {
        if ($this->sheets === []) {
            throw new RuntimeException('An Excel file needs at least one sheet.');
        }
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the Excel file.');
        }
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        foreach ($this->sheets as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet'.($i + 1).'.xml', $this->sheetXml($sheet));
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function contentTypes(): string
    {
        $sheets = '';
        foreach (array_keys($this->sheets) as $i) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet'.($i + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$sheets.'</Types>';
    }

    private function workbook(): string
    {
        $sheets = '';
        foreach ($this->sheets as $i => $sheet) {
            $sheets .= '<sheet name="'.$this->x($sheet['name']).'" sheetId="'.($i + 1).'" r:id="rId'.($i + 1).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheets.'</sheets></workbook>';
    }

    private function workbookRels(): string
    {
        $rels = '';
        foreach (array_keys($this->sheets) as $i) {
            $rels .= '<Relationship Id="rId'.($i + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($i + 1).'.xml"/>';
        }
        $rels .= '<Relationship Id="rId'.(count($this->sheets) + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>';
    }

    /** Style 0 normal, 1 bold header, 2 money (lakh grouping). */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="[&gt;=10000000]&quot;Rs &quot;##\,##\,##\,##0.00;[&gt;=100000]&quot;Rs &quot;##\,##\,##0.00;&quot;Rs &quot;##,##0.00"/></numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE8F0E8"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            .'</styleSheet>';
    }

    /** @param  array{name: string, rows: list<list<string|int|float|null>>, money: list<int>, widths: list<int>}  $sheet */
    private function sheetXml(array $sheet): string
    {
        $cols = '';
        foreach ($sheet['widths'] as $i => $w) {
            $cols .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$w.'" customWidth="1"/>';
        }
        $rows = '';
        foreach ($sheet['rows'] as $r => $row) {
            $cells = '';
            foreach (array_values($row) as $c => $value) {
                $ref = $this->column($c).($r + 1);
                if ($value === null || $value === '') {
                    continue;
                }
                if ($r === 0) {
                    $cells .= '<c r="'.$ref.'" t="inlineStr" s="1"><is><t>'.$this->x((string) $value).'</t></is></c>';
                } elseif (in_array($c, $sheet['money'], true) && is_numeric($value)) {
                    $cells .= '<c r="'.$ref.'" s="2"><v>'.$value.'</v></c>';
                } elseif (is_int($value) || is_float($value)) {
                    $cells .= '<c r="'.$ref.'"><v>'.$value.'</v></c>';
                } else {
                    $cells .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.$this->x((string) $value).'</t></is></c>';
                }
            }
            $rows .= '<row r="'.($r + 1).'">'.$cells.'</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .($cols !== '' ? '<cols>'.$cols.'</cols>' : '')
            .'<sheetData>'.$rows.'</sheetData></worksheet>';
    }

    private function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }

    /** Excel sheet names: at most 31 characters, none of []:*?/\ and unique. */
    private function sheetName(string $name): string
    {
        $base = mb_substr(trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name)), 0, 31) ?: 'Sheet';
        $taken = array_column($this->sheets, 'name');
        $candidate = $base;
        for ($i = 2; in_array($candidate, $taken, true); $i++) {
            $candidate = mb_substr($base, 0, 27).' ('.$i.')';
        }

        return $candidate;
    }

    private function x(string $s): string
    {
        // Strip characters XML 1.0 does not allow, then escape.
        return htmlspecialchars((string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

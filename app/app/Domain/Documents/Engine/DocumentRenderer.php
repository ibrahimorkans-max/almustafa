<?php

declare(strict_types=1);

namespace App\Domain\Documents\Engine;

use App\Domain\Documents\PdfRenderer;
use App\Domain\Documents\PrintSetup;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Entity;
use App\Models\StoredFile;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Mpdf\QrCode\Output\Svg;
use Mpdf\QrCode\QrCode;
use setasign\Fpdi\PdfParser\StreamReader;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Renders a document's frozen content in a layout, language and paper size: PDF (optionally
 * watermarked DRAFT / DUPLICATE and password-protected), printable HTML, PNG (through pdftoppm),
 * or plain ESC/POS text for receipt printers. Half-A4 slips are printed two to an A4 sheet.
 */
final class DocumentRenderer
{
    public const PAPERS = [
        'a4' => 'A4', 'a5' => 'A5', 'half_a4' => 'Half A4 (two slips per sheet)',
        'thermal80' => 'Thermal receipt 80 mm', 'thermal58' => 'Thermal receipt 58 mm',
    ];

    /**
     * Every document prints portrait (PrintSetup): a document saved earlier on "A4 landscape" prints on
     * A4 portrait, its tables shrunk to fit.
     */
    public static function paper(string $paper): string
    {
        return $paper === 'a4_landscape' ? 'a4' : $paper;
    }

    public const FORMATS = ['pdf' => 'PDF', 'html' => 'Printable HTML', 'png' => 'Image (PNG)', 'escpos' => 'Receipt printer text (ESC/POS)'];

    /** The HTML behind the last document rendered in this process (tests read the printed fields from it). */
    public static ?string $lastHtml = null;

    /**
     * @param  array{payload: array<string, mixed>, settings: array<string, mixed>, layout: string, language: string, digits: string, paper: string,
     *               toggles: array<string, bool>, entity: Entity|null, watermark?: string|null, verify?: array{doc: string, code: string}|null,
     *               unmasked?: array<string, string>, printed_by?: string|null}  $v
     */
    public function pdf(array $v, ?string $password = null, bool $impose = true): string
    {
        $paper = $v['paper'] = self::paper($v['paper']);
        $thermal = str_starts_with($paper, 'thermal');
        $small = in_array($paper, ['a5', 'half_a4'], true);
        $data = $this->viewData($v, web: false);
        $html = view($thermal ? 'documents.thermal' : 'documents.document', $data)->render();
        self::$lastHtml = $html;

        $tmp = storage_path('framework/cache/mpdf');
        if (! is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }
        $width = $paper === 'thermal58' ? 58 : 80;
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => match ($paper) {
                'a5' => 'A5', 'half_a4' => 'A5-L', // half A4: two to a portrait A4 sheet (impose)
                'thermal80', 'thermal58' => [$width, $this->thermalHeight($v['payload'], $v['toggles'])],
                default => 'A4',
            },
            'margin_left' => $thermal ? 3 : ($small ? 7 : 12),
            'margin_right' => $thermal ? 3 : ($small ? 7 : 12),
            'margin_top' => $thermal ? 3 : ($small ? 6 : 10),
            'margin_bottom' => $thermal ? 3 : ($small ? 11 : 16),
            'margin_footer' => $small ? 4 : 6,
            'default_font' => 'dejavusans',
            'default_font_size' => $thermal ? ($width === 58 ? 6.5 : 7.5) : ($small ? 8 : 9),
            'tempDir' => $tmp,
            // Wide tables (a register) shrink to fit the portrait page instead of being cut off.
            'shrink_tables_to_fit' => PrintSetup::WIDE_SHRINK,
        ]);
        PrintSetup::actualSize($mpdf);
        if ($v['language'] === 'ur') {
            $mpdf->SetDirectionality('rtl');
        }
        $brand = $data['brand'];
        $mpdf->SetTitle($data['p']->titleEn((string) $v['payload']['title']).' '.($v['payload']['number'] ?? '').' — '.$brand['name']);
        $mpdf->SetAuthor($brand['name']);
        $mpdf->SetCreator($brand['name']);
        if (! $thermal) {
            $mpdf->SetHTMLFooter(view('pdf.footer', ['brand' => $brand, 'printedAt' => now('Asia/Karachi'), 'printedBy' => $v['printed_by'] ?? null, 'small' => $small])->render());
        }
        if (($v['watermark'] ?? null) !== null) {
            $mpdf->SetWatermarkText((string) $v['watermark'], 0.11);
            $mpdf->showWatermarkText = true;
        }
        if ($password !== null) {
            $mpdf->SetProtection(['print', 'print-highres'], $password, bin2hex(random_bytes(16)));
        }
        $mpdf->WriteHTML($html);
        $pdf = $mpdf->Output('', Destination::STRING_RETURN);

        // A single half-A4 slip still prints on A4: in the top half, with a cut line.
        return $paper === 'half_a4' && $impose && $password === null ? $this->impose([$pdf]) : $pdf;
    }

    /** @param  array<string, mixed>  $v */
    public function html(array $v): string
    {
        $v['paper'] = self::paper((string) $v['paper']);
        $html = view('documents.web', $this->viewData($v, web: true))->render();
        self::$lastHtml = $html;

        return $html;
    }

    /** First page as a PNG image, through pdftoppm (poppler-utils). */
    public function png(string $pdf): string
    {
        $bin = config('ricemill.documents.pdftoppm') ?: (new ExecutableFinder)->find('pdftoppm');
        if ($bin === null || $bin === '') {
            throw new BusinessRuleViolation('Image output needs pdftoppm (poppler-utils) on the server; download the PDF instead.');
        }
        $in = tempnam(sys_get_temp_dir(), 'doc');
        if ($in === false) {
            throw new BusinessRuleViolation('Cannot create a temporary file.');
        }
        file_put_contents($in, $pdf);
        try {
            $process = new Process([$bin, '-png', '-r', '150', '-f', '1', '-l', '1', '-singlefile', $in, $in]);
            $process->setTimeout(30)->run();
            $out = $in.'.png';
            if (! $process->isSuccessful() || ! is_file($out)) {
                throw new BusinessRuleViolation('The image could not be made: '.trim($process->getErrorOutput()));
            }
            $png = (string) file_get_contents($out);
            @unlink($out);

            return $png;
        } finally {
            @unlink($in);
        }
    }

    /**
     * Plain text for an ESC/POS receipt printer (English, 48 or 32 columns), with initialise and cut commands.
     *
     * @param  array<string, mixed>  $v
     */
    public function escpos(array $v): string
    {
        $cols = $v['paper'] === 'thermal58' ? 32 : 48;
        $p = new DocumentPresenter('en', 'western');
        $brand = PdfRenderer::brand($v['entity']);

        return "\x1B\x40".ThermalText::render($v['payload'], $p, $brand, $v['toggles'], $cols, $v['watermark'] ?? null, $v['verify'] ?? null)."\n\n\n\x1D\x56\x41\x03";
    }

    /**
     * Prints A5-landscape pages two to an A4 portrait sheet, with a dashed cut line between them.
     *
     * @param  list<string>  $pdfs
     */
    public function impose(array $pdfs): string
    {
        $out = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0,
            'tempDir' => storage_path('framework/cache/mpdf')]);
        $slot = 0;
        foreach ($pdfs as $pdf) {
            $pages = $out->setSourceFile(StreamReader::createByString($pdf));
            for ($i = 1; $i <= $pages; $i++) {
                if ($slot % 2 === 0) {
                    $out->AddPage();
                    $out->SetLineWidth(0.2);
                    $out->SetDash(2, 2);
                    $out->Line(5, 148.5, 205, 148.5);
                    $out->SetDash();
                }
                $out->useTemplate($out->importPage($i), 0, ($slot % 2) * 148.5, 210, 148.5);
                $slot++;
            }
        }

        return PrintSetup::actualSize($out)->Output('', Destination::STRING_RETURN);
    }

    /** A copy of a PDF that opens only with the password (page content unchanged). */
    public function protect(string $pdf, string $password): string
    {
        $out = new Mpdf(['mode' => 'utf-8', 'tempDir' => storage_path('framework/cache/mpdf')]);
        $pages = $out->setSourceFile(StreamReader::createByString($pdf));
        for ($i = 1; $i <= $pages; $i++) {
            $tpl = $out->importPage($i);
            $size = $out->getTemplateSize($tpl);
            $out->AddPageByArray(['orientation' => $size['width'] > $size['height'] ? 'L' : 'P', 'sheet-size' => [$size['width'], $size['height']]]);
            $out->useTemplate($tpl);
        }
        $out->SetProtection(['print', 'print-highres'], $password, bin2hex(random_bytes(16)));

        return PrintSetup::actualSize($out)->Output('', Destination::STRING_RETURN);
    }

    /**
     * One PDF of many documents (bulk printing), each starting on a new page.
     *
     * @param  list<string>  $pdfs
     */
    public function merge(array $pdfs): string
    {
        $out = new Mpdf(['mode' => 'utf-8', 'tempDir' => storage_path('framework/cache/mpdf')]);
        foreach ($pdfs as $pdf) {
            $pages = $out->setSourceFile(StreamReader::createByString($pdf));
            for ($i = 1; $i <= $pages; $i++) {
                $tpl = $out->importPage($i);
                $size = $out->getTemplateSize($tpl);
                $out->AddPageByArray(['orientation' => $size['width'] > $size['height'] ? 'L' : 'P', 'sheet-size' => [$size['width'], $size['height']]]);
                $out->useTemplate($tpl);
            }
        }

        return PrintSetup::actualSize($out)->Output('', Destination::STRING_RETURN);
    }

    /**
     * @param  array<string, mixed>  $v
     * @return array<string, mixed>
     */
    private function viewData(array $v, bool $web): array
    {
        $s = $v['settings'];
        $brand = PdfRenderer::brand($v['entity']);
        if (($s['accent'] ?? null) !== null) {
            $brand['green'] = (string) $s['accent'];
        }
        if (! ($s['show_ntn'] ?? true)) {
            $brand['ntn'] = null;
            $brand['strn'] = null;
        }
        if ($web) {   // a browser cannot open a server file path: the logos go inline
            foreach (['logo', 'emblem'] as $key) {
                $path = $brand[$key];
                $brand[$key] = is_string($path) && is_file($path)
                    ? 'data:'.(mime_content_type($path) ?: 'image/png').';base64,'.base64_encode((string) file_get_contents($path)) : null;
            }
        }
        $verify = $v['toggles']['qr'] ? ($v['verify'] ?? null) : null;
        $verifyUrl = $verify !== null ? PdfRenderer::verifyUrl($verify['doc'], $verify['code']) : null;
        $image = function (?string $id) use ($web): ?string {
            $file = $id !== null ? StoredFile::query()->find($id) : null;
            if ($file === null) {
                return null;
            }

            return $web ? 'data:'.$file->mime.';base64,'.base64_encode((string) Storage::disk('local')->get($file->path)) : Storage::disk('local')->path($file->path);
        };

        return [
            'doc' => $v['payload'],
            'p' => new DocumentPresenter($v['language'], $v['digits']),
            's' => $s,
            'layout' => $v['layout'],
            'paper' => $v['paper'],
            'small' => in_array($v['paper'], ['a5', 'half_a4'], true),
            'show' => $v['toggles'],
            'brand' => $brand,
            'unmasked' => $v['unmasked'] ?? [],
            'watermark' => $v['watermark'] ?? null,
            'verifyUrl' => $verifyUrl,
            'verifyCode' => $verify['code'] ?? null,
            'verifyDoc' => $verify['doc'] ?? null,
            'qrSvg' => $web && $verifyUrl !== null ? (new Svg)->output(new QrCode($verifyUrl, 'M'), 110) : null,
            'stamp' => $v['toggles']['stamp'] ? $image($s['stamp_file_id'] ?? null) : null,
            'imageSrc' => $image,
            'signatureImage' => $v['toggles']['signatures'] ? $image($s['signature_file_id'] ?? null) : null,
            'web' => $web,
        ];
    }

    /**
     * Thermal paper is one long page: its height follows the content.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, bool>  $toggles
     */
    private function thermalHeight(array $payload, array $toggles): int
    {
        $lines = 14 + count($payload['fields'] ?? []) + count($payload['summary'] ?? []) + count($payload['status'] ?? []) + 3 * count($payload['paragraphs'] ?? []);
        foreach ($payload['tables'] ?? [] as $t) {
            $lines += 2 + count($t['rows']) + (isset($t['total']) ? 1 : 0);
        }
        foreach ($payload['extras'] ?? [] as $key => $t) {
            if (($toggles[$key] ?? false) && isset($t['rows'])) {
                $lines += 2 + count($t['rows']);
            }
        }

        return (int) min(1200, 30 + $lines * 4.6 + ($toggles['qr'] ? 32 : 0) + ($toggles['signatures'] ? 14 : 0));
    }
}

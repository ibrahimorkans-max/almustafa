<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Auth\ActorContext;
use App\Models\Entity;
use App\Support\Values\PakistanIds;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Every printed document (slips, invoices, vouchers, statements) goes through here, so they share one
 * letterhead, one footer and one font set. mPDF is used because it joins Urdu letters correctly
 * (the Lateef font, right to left), which the old dompdf could not.
 */
final class PdfRenderer
{
    /** The HTML behind the last document rendered in this process (tests check the printed fields with it). */
    public static ?string $lastHtml = null;

    public function __construct(private readonly ActorContext $actor) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  'A4'|'A5'  $format
     */
    public function render(string $view, array $data, ?Entity $entity, string $format = 'A4', ?string $verifyDoc = null, ?string $verifyCode = null): string
    {
        $brand = self::brand($entity);
        $html = view($view, $data + [
            'brand' => $brand,
            'printedBy' => $this->actor->account()?->username,
            'printedAt' => now('Asia/Karachi'),
            'verifyDoc' => $verifyDoc,
            'verifyCode' => $verifyCode,
            'verifyUrl' => $verifyDoc !== null && $verifyCode !== null ? self::verifyUrl($verifyDoc, $verifyCode) : null,
            'format' => $format,
        ])->render();
        self::$lastHtml = $html;

        $tmp = storage_path('framework/cache/mpdf');
        if (! is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }
        $small = $format === 'A5';
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => $format,
            'margin_left' => $small ? 7 : 12,
            'margin_right' => $small ? 7 : 12,
            'margin_top' => $small ? 6 : 10,
            'margin_bottom' => $small ? 12 : 16,
            'margin_footer' => $small ? 4 : 6,
            'default_font' => 'dejavusans',
            'default_font_size' => $small ? 8 : 9,
            // Urdu is always marked up explicitly (class "ur", the Lateef font), so no script guessing.
            'tempDir' => $tmp,
            // Keep the designed font sizes: tables are laid out to fit, never shrunk.
            'shrink_tables_to_fit' => 1,
        ]);
        $mpdf->SetTitle(strip_tags((string) ($data['title'] ?? 'Document')).' — '.$brand['name']);
        $mpdf->SetAuthor($brand['name']);
        $mpdf->SetCreator($brand['name']);
        $mpdf->SetHTMLFooter(view('pdf.footer', ['brand' => $brand, 'printedAt' => now('Asia/Karachi'),
            'printedBy' => $this->actor->account()?->username, 'small' => $small])->render());
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * Letterhead details: the mill entity's own (as registered) with the configured brand as fall-back.
     *
     * @return array{name: string, name_ur: string, address: string, phone: string, ntn: string|null, strn: string|null, logo: string, emblem: string, green: string, gold: string}
     */
    public static function brand(?Entity $entity): array
    {
        $b = config('ricemill.brand');
        $address = $entity?->address !== null && $entity->address !== ''
            ? trim($entity->address.($entity->city ? ', '.$entity->city : '').($entity->province ? ', '.$entity->province : ''), ', ')
            : (string) $b['address'];

        return [
            'name' => (string) ($entity?->legal_name ?: $b['name']),
            'name_ur' => (string) $b['name_ur'],
            'address' => $address,
            'phone' => (string) ($entity?->phone ?: $b['phone']),
            'ntn' => PakistanIds::formatNtn($entity?->ntn),
            'strn' => $entity?->strn,
            'logo' => public_path('images/brand/logo-wide-print.png'),
            'emblem' => public_path('images/brand/emblem-print.png'),
            'green' => (string) $b['green'],
            'gold' => (string) $b['gold'],
        ];
    }

    public static function verifyUrl(string $doc, string $code): string
    {
        return url('/verify').'?'.http_build_query(['doc' => $doc, 'code' => $code]);
    }

    /** "512.300" kg → "12 maund 32.300 kg" (how weights are read out at the kanta). */
    public static function maundKg(string $kg): string
    {
        $grams = (int) round(((float) $kg) * 1000);
        $sign = $grams < 0 ? '−' : '';
        $grams = abs($grams);
        $maund = intdiv($grams, 40_000);
        $rest = number_format(($grams % 40_000) / 1000, 3, '.', '');

        return $sign.number_format($maund).' maund '.$rest.' kg';
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Auth\ActorContext;
use App\Domain\Settings\SettingsService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Entity;
use App\Support\Values\PakistanIds;
use Illuminate\Support\Facades\Storage;
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
     * @param  'A4'|'A4-L'|'A5'  $format  A4-L marks a wide report: it prints portrait too, its tables shrunk to fit (PrintSetup)
     */
    public function render(string $view, array $data, ?Entity $entity, string $format = 'A4', ?string $verifyDoc = null, ?string $verifyCode = null, ?string $paper = null): string
    {
        $paper ??= PaperSize::resolve($view, $entity?->id);
        $wide = PrintSetup::isWide($format);
        $format = PrintSetup::portrait($format);
        $roll = PaperSize::isRoll($paper);
        $rollWidth = PaperSize::ROLL_WIDTHS[$paper] ?? 80;
        $small = PaperSize::isSmall($paper, $format);
        self::$lastPaper = $paper ?? $format;
        $brand = self::brand($entity);
        $html = view($view, $data + [
            'brand' => $brand,
            'printedBy' => $this->actor->account()?->username,
            'printedAt' => now('Asia/Karachi'),
            'verifyDoc' => $verifyDoc,
            'verifyCode' => $verifyCode,
            'verifyUrl' => $verifyDoc !== null && $verifyCode !== null ? self::verifyUrl($verifyDoc, $verifyCode) : null,
            'format' => $format,
            'paper' => $paper,
            'roll' => $roll,
        ])->render();
        self::$lastHtml = $html;
        $footer = view('pdf.footer', ['brand' => $brand, 'printedAt' => now('Asia/Karachi'),
            'printedBy' => $this->actor->account()?->username, 'small' => $small, 'roll' => $roll])->render();

        if ($data['bare'] ?? false) {
            $footer = '';   // e.g. a token slip: nothing but its own content
        }
        if (! $roll) {
            return $this->write($html, $footer, PaperSize::mpdfFormat($paper, $format, $entity?->id) ?? PrintSetup::portrait($format), $small, false, $data, $brand, wide: $wide,
                watermark: $this->reportWatermark($view, $entity));
        }
        // A roll has no page length: lay the document out on a very long strip, measure where it ends,
        // then print it once more on a strip cut just below the content. The footer goes at the end.
        $html = str_contains($html, '</body>') ? str_replace('</body>', '<div style="margin-top: 3mm;">'.$footer.'</div></body>', $html) : $html.$footer;
        self::$lastHtml = $html;
        $probe = $this->mpdf([$rollWidth, 3000], true, true, $rollWidth);
        $probe->WriteHTML($html);
        $height = (int) ceil(($probe->page - 1) * 3000 + $probe->y) + 18;

        return $this->write($html, $footer, [$rollWidth, max(40, $height)], true, true, $data, $brand, $rollWidth);
    }

    /**
     * Reports (lists of the mill's data, not single documents) carry a faint diagonal watermark with who
     * printed them and when, while the mill's screen protection is on: a leaked page shows its source.
     */
    private function reportWatermark(string $view, ?Entity $entity): ?string
    {
        $me = $this->actor->account();
        if (! str_starts_with($view, 'reports.') || $me === null || ! app(SettingsService::class)->screenGuard($entity->id ?? $me->entity_id)['on']) {
            return null;
        }

        return $me->username.' · '.now('Asia/Karachi')->format('d M Y H:i');
    }

    /** The paper of the last document rendered in this process (tests check it). */
    public static ?string $lastPaper = null;

    /**
     * @param  string|array{0: int, 1: int}  $format
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $brand
     */
    private function write(string $html, string $footer, string|array $format, bool $small, bool $roll, array $data, array $brand, int $rollWidth = 80, bool $wide = false, ?string $watermark = null): string
    {
        $mpdf = PrintSetup::actualSize($this->mpdf($format, $small, $roll, $rollWidth, $wide));
        if ($watermark !== null) {
            $mpdf->SetWatermarkText($watermark, 0.06);
            $mpdf->showWatermarkText = true;
        }
        $mpdf->SetTitle(strip_tags((string) ($data['title'] ?? 'Document')).' — '.$brand['name']);
        $mpdf->SetAuthor($brand['name']);
        $mpdf->SetCreator($brand['name']);
        if (! $roll && $footer !== '') {
            $mpdf->SetHTMLFooter($footer);
        }
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /** @param  string|array{0: int, 1: int}  $format */
    private function mpdf(string|array $format, bool $small, bool $roll, int $rollWidth = 80, bool $wide = false): Mpdf
    {
        $tmp = storage_path('framework/cache/mpdf');
        if (! is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }

        return new Mpdf([
            'mode' => 'utf-8',
            'format' => $format,
            'margin_left' => $roll ? ($rollWidth < 60 ? 2 : 3) : ($small ? 7 : 12),
            'margin_right' => $roll ? ($rollWidth < 60 ? 2 : 3) : ($small ? 7 : 12),
            'margin_top' => $roll ? 3 : ($small ? 6 : 10),
            'margin_bottom' => $roll ? 3 : ($small ? 12 : 16),
            'margin_footer' => $small ? 4 : 6,
            'default_font' => 'dejavusans',
            'default_font_size' => $roll ? ($rollWidth < 60 ? 6.5 : 7.5) : ($small ? 8 : 9),
            // Urdu is always marked up explicitly (class "ur", the Lateef font), so no script guessing.
            'tempDir' => $tmp,
            // Designed for A4 portrait: on small paper, and for reports wider than the page, tables shrink to fit the width.
            'shrink_tables_to_fit' => $small || $wide ? PrintSetup::WIDE_SHRINK : 1,
        ]);
    }

    /** @var array<string, bool> entity id => whether it falls back to the configured brand */
    private static array $configBrand = [];

    /**
     * Letterhead details: the mill's own (Print & branding). What a mill leaves empty comes from the
     * configured brand only for the first installation's mills; another customer's mill never prints
     * someone else's name or logo (no logo then, and neutral colours).
     *
     * @return array{name: string, name_ur: string, tagline: string, address: string, phone: string, email: string, website: string,
     *               ntn: string|null, strn: string|null, logo: string|null, emblem: string|null, green: string, gold: string,
     *               invoice_terms: string, slip_note: string, footer_note: string, bank: array<string, string>}
     */
    public static function brand(?Entity $entity): array
    {
        $b = config('ricemill.brand');
        $own = self::usesConfigBrand($entity);
        $fallback = fn (string $key) => $own ? (string) ($b[$key] ?? '') : '';
        $address = $entity?->address !== null && $entity->address !== ''
            ? trim($entity->address.($entity->city ? ', '.$entity->city : '').($entity->province ? ', '.$entity->province : ''), ', ')
            : ($own ? (string) $b['address'] : trim((string) $entity?->city));
        $file = fn (?string $path) => $path !== null && $path !== '' && Storage::disk('local')->exists($path) ? Storage::disk('local')->path($path) : null;
        $logo = $file($entity?->logo_path) ?? ($own ? public_path('images/brand/logo-wide-print.png') : null);

        return [
            'name' => (string) ($entity?->legal_name ?: ($own ? $b['name'] : $entity->name)),
            'name_ur' => (string) ($entity?->name_ur ?: $fallback('name_ur')),
            'tagline' => (string) $entity?->tagline,
            'address' => $address,
            'phone' => (string) ($entity?->phone ?: $fallback('phone')),
            'email' => (string) $entity?->email,
            'website' => (string) $entity?->website,
            'ntn' => PakistanIds::formatNtn($entity?->ntn),
            'strn' => $entity?->strn,
            'logo' => $logo,
            'emblem' => $file($entity?->emblem_path) ?? ($own ? public_path('images/brand/emblem-print.png') : $logo),
            'green' => (string) ($entity?->color_primary ?: ($own ? $b['green'] : '#1f4e3d')),
            'gold' => (string) ($entity?->color_accent ?: ($own ? $b['gold'] : '#b08a2e')),
            'invoice_terms' => (string) $entity?->invoice_terms,
            'slip_note' => (string) $entity?->slip_note,
            'footer_note' => (string) $entity?->footer_note,
            'bank' => array_filter([
                'Account title' => (string) $entity?->bank_title, 'Bank' => (string) $entity?->bank_name,
                'Account number' => (string) $entity?->bank_account, 'IBAN' => (string) $entity?->bank_iban,
            ], fn (string $v) => $v !== ''),
        ];
    }

    /** The first installation's mills (and a document with no mill) fall back to the configured brand. */
    private static function usesConfigBrand(?Entity $entity): bool
    {
        if ($entity === null || $entity->customer_id === null) {
            return true;
        }

        return self::$configBrand[$entity->id] ??= app(TenantContext::class)->runAsSystem(
            fn () => Customer::query()->whereKey($entity->customer_id)->whereNull('source')->exists());
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

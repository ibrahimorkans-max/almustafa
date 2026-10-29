<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Settings\SettingsService;

/**
 * The paper every slip and report is printed on: A4, A5, A6, Letter, a small roll of 80, 58 or 50 mm, or the
 * mill's custom size. Which one, in this order:
 *   1. `?paper=` on a print link, for that one print;
 *   2. the mill's preferred paper for that kind of document (Branding → Paper per document: tokens on the
 *      80 mm roll, invoices on A4 …);
 *   3. the paper chosen on this device (the selector at the top of every screen);
 *   4. the document's own size.
 */
final class PaperSize
{
    public const OPTIONS = [
        'a4' => 'A4',
        'a5' => 'A5',
        'a6' => 'A6',
        'letter' => 'Letter',
        'roll' => 'Small roll (80 mm)',
        'roll58' => 'Small roll (58 mm)',
        'roll50' => 'Small roll (50 mm)',
        'custom' => 'Custom size (Branding)',
    ];

    /** Kinds of document, each with its own preferred paper (settings print.paper.<kind>). */
    public const KINDS = [
        'token' => 'Gate tokens', 'slip' => 'Weighment slips and gate passes', 'invoice' => 'Invoices and bills',
        'voucher' => 'Vouchers and receipts', 'statement' => 'Party statements', 'report' => 'Reports and lists', 'record' => 'Other records',
    ];

    /** The kind of a document, from its view. */
    public static function kindOf(string $view): string
    {
        return match (true) {
            str_contains($view, 'token') => 'token',
            str_starts_with($view, 'slips.'), str_contains($view, 'gate-pass') => 'slip',
            str_contains($view, 'invoice'), str_contains($view, 'bill') => 'invoice',
            str_contains($view, 'voucher'), str_contains($view, 'receipt') => 'voucher',
            str_contains($view, 'party-statement') => 'statement',
            str_starts_with($view, 'reports.') => 'report',
            default => 'record',
        };
    }

    /** The paper for one print of a document (see the order above). */
    public static function resolve(string $view, ?string $entityId): ?string
    {
        $asked = app()->bound('request') ? request()->query('paper') : null;
        if (is_string($asked) && isset(self::OPTIONS[$asked])) {
            return $asked;
        }
        if ($entityId !== null) {
            $preferred = app(SettingsService::class)->get('print.paper.'.self::kindOf($view), $entityId);
            if (is_string($preferred) && isset(self::OPTIONS[$preferred])) {
                return $preferred;
            }
        }

        return self::current();
    }

    /**
     * The mill's custom paper in mm, [width, height] (Branding; 100 × 150 when not set).
     *
     * @return array{0: int, 1: int}
     */
    public static function custom(?string $entityId): array
    {
        $v = $entityId === null ? null : app(SettingsService::class)->get('print.custom_paper', $entityId);
        if (is_string($v) && preg_match('/^(\d{2,3})x(\d{2,4})$/', $v, $m) === 1) {
            return [max(40, min(420, (int) $m[1])), max(40, min(1200, (int) $m[2]))];
        }

        return [100, 150];
    }

    /** Width in mm of each roll paper. */
    public const ROLL_WIDTHS = ['roll' => 80, 'roll58' => 58, 'roll50' => 50];

    public const COOKIE = 'print_paper';

    /** The paper chosen for this print (null: the document's own size). */
    public static function current(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }
        $request = request();
        foreach ([$request->query('paper'), $request->cookie(self::COOKIE)] as $choice) {
            if (is_string($choice) && isset(self::OPTIONS[$choice])) {
                return $choice;
            }
        }

        return null;
    }

    /**
     * The mPDF format for a document: the chosen paper, always portrait (a wide report shrinks to fit, PrintSetup).
     * A roll is returned as null: its height is measured from the content (PdfRenderer).
     *
     * @param  string  $documentFormat  the document's own format: A4, A4-L, A5
     */
    /** @return string|array{0: int, 1: int}|null */
    public static function mpdfFormat(?string $paper, string $documentFormat, ?string $entityId = null): string|array|null
    {
        return match ($paper) {
            'custom' => self::custom($entityId),
            'a4' => 'A4',
            'a5' => 'A5',
            'letter' => 'Letter',
            'a6' => 'A6',
            'roll', 'roll58', 'roll50' => null,
            default => PrintSetup::portrait($documentFormat),
        };
    }

    /** Small papers get small margins and type. */
    public static function isSmall(?string $paper, string $documentFormat): bool
    {
        return in_array($paper, ['a5', 'a6', 'roll', 'roll58', 'roll50'], true) || ($paper === null && $documentFormat === 'A5');
    }

    public static function isRoll(?string $paper): bool
    {
        return $paper !== null && isset(self::ROLL_WIDTHS[$paper]);
    }
}

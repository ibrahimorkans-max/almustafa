<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use Mpdf\Mpdf;

/**
 * How every document prints: portrait, at actual size.
 *
 *  - Portrait: invoices, slips, vouchers and reports are all laid out upright. A report that used to be
 *    landscape ("A4-L", a wide table) prints on the portrait page with its tables shrunk to fit the width
 *    instead of being cut off.
 *  - Actual size: each PDF carries /PrintScaling /None, so the print dialog starts at 100 % ("Actual
 *    size") rather than "Fit to page"; the page is made at the exact size of the chosen paper (A4,
 *    Letter, A6, A5, the 80 mm or 50 mm roll), so nothing is scaled on the way to the printer.
 */
final class PrintSetup
{
    /** How far a wide table may shrink to fit a portrait page (mPDF factor). */
    public const WIDE_SHRINK = 4;

    /** "A4-L" → "A4", "Letter-L" → "Letter": every page is portrait. */
    public static function portrait(string $format): string
    {
        return str_ends_with($format, '-L') ? substr($format, 0, -2) : $format;
    }

    /** Whether the document was designed wider than a portrait page. */
    public static function isWide(string $format): bool
    {
        return str_ends_with($format, '-L');
    }

    /** Tells the PDF viewer to print at 100 % (no "fit to page"). */
    public static function actualSize(Mpdf $mpdf): Mpdf
    {
        $mpdf->SetDisplayPreferences('/NoPrintScaling');

        return $mpdf;
    }
}

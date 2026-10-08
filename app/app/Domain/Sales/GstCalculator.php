<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Accounts\PurchaseBillCalculator;

/**
 * GST on a sales invoice line. Pure; integer paise, half-up rounding.
 *
 * Intra-state supply (supplier state = place of supply): CGST + SGST at half the rate each,
 * each rounded on its own. Inter-state: IGST at the full rate. The invoice total is rounded to
 * the rupee; the difference is the round-off.
 */
final class GstCalculator
{
    /**
     * @return array{taxable_paise: int, cgst_paise: int, sgst_paise: int, igst_paise: int, round_off_paise: int, total_paise: int}
     */
    public function calculate(string $quantityKg, int $ratePaisePerQuintal, int $gstRateBp, string $supplierState, string $placeOfSupply, bool $roundToRupee = true): array
    {
        $taxable = PurchaseBillCalculator::perQuintal($quantityKg, $ratePaisePerQuintal);
        $cgst = $sgst = $igst = 0;
        if ($supplierState === $placeOfSupply) {
            $cgst = self::percentOf($taxable, $gstRateBp, 20000);
            $sgst = $cgst;
        } else {
            $igst = self::percentOf($taxable, $gstRateBp, 10000);
        }
        $raw = $taxable + $cgst + $sgst + $igst;
        $total = $roundToRupee ? intdiv($raw + 50, 100) * 100 : $raw;

        return [
            'taxable_paise' => $taxable, 'cgst_paise' => $cgst, 'sgst_paise' => $sgst, 'igst_paise' => $igst,
            'round_off_paise' => $total - $raw, 'total_paise' => $total,
        ];
    }

    /** amount × bp / divisor, rounded half-up (divisor 10000 = full rate, 20000 = half rate). */
    private static function percentOf(int $amount, int $bp, int $divisor): int
    {
        return intdiv($amount * $bp * 2 + $divisor, $divisor * 2);
    }

    /** State code (2 digits) from a GSTIN, or null. */
    public static function stateOfGstin(?string $gstin): ?string
    {
        return $gstin !== null && preg_match('/^(\d{2})/', $gstin, $m) ? $m[1] : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\Values\Money;
use App\Support\Values\Weight;

/**
 * Conversions between what people type/read (maund, Rs/maund, percent) and what is stored
 * (kg, paise, basis points). String/integer arithmetic only.
 */
final class Units
{
    public const DECIMAL_2 = '/^\d{1,13}(\.\d{1,2})?$/';

    public const DECIMAL_3 = '/^\d{1,12}(\.\d{1,3})?$/';

    public static function maundToKg(string $maund): string
    {
        return Weight::from($maund, 'maund')->kg;
    }

    public static function kgToMaund(?string $kg): string
    {
        return $kg === null ? '—' : Weight::kg($kg)->in('maund', 2);
    }

    public static function rupeesToPaise(string $rupees): int
    {
        return Money::rupees($rupees)->paise;
    }

    public static function paiseToRupees(?int $paise): string
    {
        return $paise === null ? '—' : Money::paise($paise)->format();
    }

    /** "17.5" (%) → 1750 basis points */
    public static function percentToBp(?string $percent): ?int
    {
        if ($percent === null || $percent === '') {
            return null;
        }

        return (int) bcmul($percent, '100', 0);
    }

    public static function bpToPercent(?int $bp): string
    {
        return $bp === null ? '—' : rtrim(rtrim(bcdiv((string) $bp, '100', 2), '0'), '.').'%';
    }
}

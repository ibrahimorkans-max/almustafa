<?php

declare(strict_types=1);

namespace App\Support\Values;

/**
 * Amounts in words as written on Pakistani invoices and vouchers, in the international system:
 * 1,250,500.50 → "Rupees One Million Two Hundred Fifty Thousand Five Hundred and Fifty Paisa Only".
 */
final class AmountInWords
{
    private const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
        'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    public static function rupees(int $paisa): string
    {
        $negative = $paisa < 0;
        $paisa = abs($paisa);
        $words = 'Rupees '.self::number(intdiv($paisa, 100));
        if ($paisa % 100 > 0) {
            $words .= ' and '.self::number($paisa % 100).' Paisa';
        }

        return ($negative ? 'Minus ' : '').$words.' Only';
    }

    public static function number(int $n): string
    {
        if ($n === 0) {
            return 'Zero';
        }
        $parts = [];
        foreach ([[1_000_000_000, 'Billion'], [1_000_000, 'Million'], [1000, 'Thousand'], [100, 'Hundred']] as [$unit, $name]) {
            if ($n >= $unit) {
                $parts[] = self::number(intdiv($n, $unit)).' '.$name;
                $n %= $unit;
            }
        }
        if ($n > 0) {
            $parts[] = $n < 20 ? self::ONES[$n] : self::TENS[intdiv($n, 10)].($n % 10 ? '-'.self::ONES[$n % 10] : '');
        }

        return implode(' ', $parts);
    }
}

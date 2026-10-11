<?php

declare(strict_types=1);

namespace App\Support\Values;

/**
 * Amounts in Urdu words, in the South Asian system (ہزار، لاکھ، کروڑ، ارب) as spoken in Pakistan:
 * 125,050.50 → "ایک لاکھ پچیس ہزار پچاس روپے اور پچاس پیسے صرف". Urdu numbers 1–99 are each a word
 * of their own, so they are listed in full.
 */
final class UrduAmountInWords
{
    private const UPTO_99 = ['', 'ایک', 'دو', 'تین', 'چار', 'پانچ', 'چھ', 'سات', 'آٹھ', 'نو', 'دس',
        'گیارہ', 'بارہ', 'تیرہ', 'چودہ', 'پندرہ', 'سولہ', 'سترہ', 'اٹھارہ', 'انیس', 'بیس',
        'اکیس', 'بائیس', 'تئیس', 'چوبیس', 'پچیس', 'چھبیس', 'ستائیس', 'اٹھائیس', 'انتیس', 'تیس',
        'اکتیس', 'بتیس', 'تینتیس', 'چونتیس', 'پینتیس', 'چھتیس', 'سینتیس', 'اڑتیس', 'انتالیس', 'چالیس',
        'اکتالیس', 'بیالیس', 'تینتالیس', 'چوالیس', 'پینتالیس', 'چھیالیس', 'سینتالیس', 'اڑتالیس', 'انچاس', 'پچاس',
        'اکاون', 'باون', 'ترپن', 'چون', 'پچپن', 'چھپن', 'ستاون', 'اٹھاون', 'انسٹھ', 'ساٹھ',
        'اکسٹھ', 'باسٹھ', 'ترسٹھ', 'چونسٹھ', 'پینسٹھ', 'چھیاسٹھ', 'سڑسٹھ', 'اڑسٹھ', 'انہتر', 'ستر',
        'اکہتر', 'بہتر', 'تہتر', 'چوہتر', 'پچھتر', 'چھہتر', 'ستتر', 'اٹھہتر', 'اناسی', 'اسی',
        'اکیاسی', 'بیاسی', 'تراسی', 'چوراسی', 'پچاسی', 'چھیاسی', 'ستاسی', 'اٹھاسی', 'نواسی', 'نوے',
        'اکانوے', 'بانوے', 'ترانوے', 'چورانوے', 'پچانوے', 'چھیانوے', 'ستانوے', 'اٹھانوے', 'ننانوے'];

    private const DIGITS = ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹'];

    public static function rupees(int $paisa): string
    {
        $negative = $paisa < 0;
        $paisa = abs($paisa);
        $words = self::number(intdiv($paisa, 100)).' روپے';
        if ($paisa % 100 > 0) {
            $words .= ' اور '.self::number($paisa % 100).' پیسے';
        }

        return ($negative ? 'منفی ' : '').$words.' صرف';
    }

    public static function number(int $n): string
    {
        if ($n === 0) {
            return 'صفر';
        }
        $parts = [];
        foreach ([[1_000_000_000, 'ارب'], [10_000_000, 'کروڑ'], [100_000, 'لاکھ'], [1000, 'ہزار'], [100, 'سو']] as [$unit, $name]) {
            if ($n >= $unit) {
                $parts[] = self::number(intdiv($n, $unit)).' '.$name;
                $n %= $unit;
            }
        }
        if ($n > 0) {
            $parts[] = self::UPTO_99[$n];
        }

        return implode(' ', $parts);
    }

    /** Western digits → Urdu (Extended Arabic-Indic) digits; everything else unchanged. */
    public static function digits(string $text): string
    {
        return strtr($text, self::DIGITS);
    }
}

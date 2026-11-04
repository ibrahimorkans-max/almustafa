<?php

declare(strict_types=1);

namespace App\Support\Values;

/**
 * Urdu (۰۱۲۳۴۵۶۷۸۹) and Arabic (٠١٢٣٤٥٦٧٨٩) digits, the Arabic thousands and decimal marks (٬ ٫) and the Urdu
 * comma (،) as the digits and marks the system reads: "۱۵,۰۰۰/-" → "15,000/-".
 */
final class UrduDigits
{
    private const MAP = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '٬' => ',', '٫' => '.', '،' => ',',
    ];

    public static function toLatin(string $value): string
    {
        return strtr($value, self::MAP);
    }

    /** Only the amount's digits and decimal point: "Rs ۱۵,۰۰۰/-" → "15000". */
    public static function amount(string $value): string
    {
        $value = (string) preg_replace('/\/-\s*$/u', '', trim(self::toLatin($value)));

        return (string) preg_replace('/[^0-9.]/', '', $value);
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Values;

/**
 * Normalisation and validation of Indian identifiers.
 */
final class IndianIds
{
    private const GSTIN_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public static function normalizeGstin(?string $value): ?string
    {
        $value = $value === null ? '' : strtoupper(preg_replace('/\s+/', '', $value) ?? '');

        return $value === '' ? null : $value;
    }

    /** Format, state code and the mod-36 check character. */
    public static function isValidGstin(string $gstin): bool
    {
        if (preg_match('/^(\d{2})([A-Z]{5}\d{4}[A-Z])[1-9A-Z]Z[0-9A-Z]$/', $gstin, $m) !== 1) {
            return false;
        }
        $state = (int) $m[1];
        if ($state < 1 || ($state > 38 && ! in_array($state, [97, 99], true))) {
            return false;
        }

        return self::gstinCheckChar(substr($gstin, 0, 14)) === $gstin[14];
    }

    public static function gstinCheckChar(string $first14): string
    {
        $sum = 0;
        for ($i = 0; $i < 14; $i++) {
            $product = strpos(self::GSTIN_CHARS, $first14[$i]) * ($i % 2 === 0 ? 1 : 2);
            $sum += intdiv($product, 36) + $product % 36;
        }

        return self::GSTIN_CHARS[(36 - $sum % 36) % 36];
    }

    /** The PAN embedded in a GSTIN (characters 3–12). */
    public static function panFromGstin(string $gstin): string
    {
        return substr($gstin, 2, 10);
    }

    public static function isValidPan(string $pan): bool
    {
        return preg_match('/^[A-Z]{3}[ABCFGHLJPTK][A-Z]\d{4}[A-Z]$/', $pan) === 1;
    }

    public static function isValidIfsc(string $ifsc): bool
    {
        return preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc) === 1;
    }

    /** "hr 05 ab 1234" → "HR05AB1234" */
    public static function normalizeVehicleNumber(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '');
    }

    /** Regular state series (HR05AB1234, DL1CAB1234) and Bharat series (22BH1234AA). */
    public static function isValidVehicleNumber(string $normalized): bool
    {
        return preg_match('/^[A-Z]{2}\d{1,2}[A-Z]{0,3}\d{1,4}$/', $normalized) === 1
            || preg_match('/^\d{2}BH\d{4}[A-Z]{1,2}$/', $normalized) === 1;
    }

    /** Indian mobile → "+91XXXXXXXXXX" or null if not a valid mobile number. */
    public static function normalizeMobile(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return preg_match('/^[6-9]\d{9}$/', $digits) === 1 ? '+91'.$digits : null;
    }
}

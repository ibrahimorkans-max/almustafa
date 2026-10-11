<?php

declare(strict_types=1);

namespace App\Support\Values;

/**
 * Normalisation and validation of Pakistani identifiers. Values are stored as digits / upper-case
 * letters only; the display helpers add the usual dashes.
 */
final class PakistanIds
{
    public const PROVINCES = [
        'Punjab', 'Sindh', 'Khyber Pakhtunkhwa', 'Balochistan', 'Islamabad Capital Territory', 'Gilgit-Baltistan', 'Azad Jammu and Kashmir',
    ];

    /** Keep digits only; null when empty. "35202-1234567-1" → "3520212345671" */
    public static function digits(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        return $digits === '' ? null : $digits;
    }

    /**
     * CNIC / NICOP / B-form: 13 digits; the first digit is the region (1 Khyber Pakhtunkhwa, 2 former FATA,
     * 3 Punjab, 4 Sindh, 5 Balochistan, 6 Islamabad, 7 Gilgit-Baltistan / AJK). NADRA publishes no check digit.
     */
    public static function isValidCnic(string $cnic): bool
    {
        return preg_match('/^[1-7]\d{12}$/', $cnic) === 1;
    }

    /** "3520212345671" → "35202-1234567-1" */
    public static function formatCnic(?string $cnic): ?string
    {
        return $cnic !== null && strlen($cnic) === 13 ? substr($cnic, 0, 5).'-'.substr($cnic, 5, 7).'-'.$cnic[12] : $cnic;
    }

    /**
     * NTN: the 7-digit number with its check digit ("1234567-8" → "12345678"), or, for individuals,
     * the 13-digit CNIC (FBR uses the CNIC as the NTN).
     */
    public static function isValidNtn(string $ntn): bool
    {
        return preg_match('/^\d{8}$/', $ntn) === 1 || self::isValidCnic($ntn);
    }

    /** "12345678" → "1234567-8"; a CNIC-based NTN is shown as a CNIC. */
    public static function formatNtn(?string $ntn): ?string
    {
        if ($ntn === null) {
            return null;
        }

        return strlen($ntn) === 8 ? substr($ntn, 0, 7).'-'.$ntn[7] : self::formatCnic($ntn);
    }

    /** Sales-tax registration number: 13 digits (older certificates show it as 12-34-5678-901-23). */
    public static function isValidStrn(string $strn): bool
    {
        return preg_match('/^\d{13}$/', $strn) === 1;
    }

    public static function normalizeIban(?string $value): ?string
    {
        $value = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value) ?? '');

        return $value === '' ? null : $value;
    }

    /** Pakistani IBAN: PK + 2 check digits + 4-letter bank code + 16 account digits, with the ISO 13616 mod-97 check. */
    public static function isValidIban(string $iban): bool
    {
        if (preg_match('/^PK\d{2}[A-Z]{4}[0-9]{16}$/', $iban) !== 1) {
            return false;
        }
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = preg_replace_callback('/[A-Z]/', fn (array $m) => (string) (ord($m[0]) - 55), $rearranged) ?? '';
        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder === 1;
    }

    /** The 4-letter bank code inside an IBAN (e.g. "MEZN" for Meezan Bank). */
    public static function bankCodeOfIban(string $iban): string
    {
        return substr($iban, 4, 4);
    }

    /** "PK36SCBL0000001123456702" → "PK36 SCBL 0000 0011 2345 6702" */
    public static function formatIban(?string $iban): ?string
    {
        return $iban === null ? null : trim(chunk_split($iban, 4, ' '));
    }

    /** Check digits for a Pakistani IBAN body (bank code + 16 digits), e.g. for test data. */
    public static function ibanFor(string $bankCode, string $account16): string
    {
        $numeric = preg_replace_callback('/[A-Z]/', fn (array $m) => (string) (ord($m[0]) - 55), $bankCode.$account16.'PK00') ?? '';
        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return 'PK'.str_pad((string) (98 - $remainder), 2, '0', STR_PAD_LEFT).$bankCode.$account16;
    }

    /** "les 21 1234" → "LES211234" */
    public static function normalizeVehicleNumber(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '');
    }

    /**
     * Pakistani registration marks: 1–4 letters (district / series, e.g. LES, MNA, BKX, ICT) followed by
     * an optional 2-digit year and 1–4 digits: LES-21-1234 → LES211234, MNA-1234, BKX-123.
     */
    public static function isValidVehicleNumber(string $normalized): bool
    {
        return preg_match('/^[A-Z]{1,4}\d{1,6}$/', $normalized) === 1;
    }

    /** "MNA211234" → "MNA-21-1234", "LES1234" → "LES-1234" (as painted on the plate). */
    public static function formatVehicleNumber(string $normalized): string
    {
        if (preg_match('/^([A-Z]{1,4})(\d{2})(\d{4})$/', $normalized, $m) === 1) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        return preg_match('/^([A-Z]{1,4})(\d{1,4})$/', $normalized, $m) === 1 ? "{$m[1]}-{$m[2]}" : $normalized;
    }

    /** Pakistani mobile → "+923XXXXXXXXX", or null if not a mobile number (0300-6834588, +92 300 6834588, 3006834588). */
    public static function normalizeMobile(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';
        if (strlen($digits) === 12 && str_starts_with($digits, '92')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 14 && str_starts_with($digits, '0092')) {
            $digits = substr($digits, 4);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return preg_match('/^3\d{9}$/', $digits) === 1 ? '+92'.$digits : null;
    }

    /** "+923006834588" → "0300-6834588" (as people write it). */
    public static function formatMobile(?string $e164): ?string
    {
        return $e164 !== null && preg_match('/^\+92(3\d{2})(\d{7})$/', $e164, $m) === 1 ? "0{$m[1]}-{$m[2]}" : $e164;
    }
}

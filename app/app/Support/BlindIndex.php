<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Deterministic keyed hash of a normalised value, stored next to an encrypted column so it can
 * be searched and kept unique without decrypting (NTN, CNIC, STRN, IBAN).
 * The key is separate from the encryption key (RICEMILL_BLIND_INDEX_KEY, falls back to a key
 * derived from APP_KEY).
 */
final class BlindIndex
{
    public static function of(?string $value, string $purpose): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return hash_hmac('sha256', $purpose.'|'.strtoupper(trim($value)), self::key());
    }

    private static function key(): string
    {
        $configured = (string) config('ricemill.security.blind_index_key');

        return $configured !== '' ? $configured : hash_hmac('sha256', 'ricemill-blind-index', (string) config('app.key'));
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use DateTimeInterface;
use JsonException;

/**
 * Deterministic JSON: object keys sorted recursively, no escaped slashes/unicode.
 * The exact string produced here is what gets stored and hashed.
 */
final class CanonicalJson
{
    /**
     * @param  array<array-key, mixed>|null  $value
     *
     * @throws JsonException
     */
    public static function encode(?array $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode(
            self::normalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if (is_object($value)) {
            $value = method_exists($value, 'toArray') ? $value->toArray() : get_object_vars($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::normalize(...), $value);
    }
}

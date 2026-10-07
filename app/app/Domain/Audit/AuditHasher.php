<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use Carbon\CarbonImmutable;

/**
 * hash = sha256(prev_hash "\n" canonical-json(fields))
 *
 * Fields are normalised so the same row hashes identically when written and when read
 * back from PostgreSQL or MariaDB (bool/int representation, timestamp precision).
 * JSON columns are hashed as the exact stored text.
 */
final class AuditHasher
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public const FIELDS = [
        'id', 'chain', 'seq', 'entity_id', 'occurred_at', 'actor_login_account_id', 'actor_person_id',
        'acting_as_super_admin', 'device_id', 'ip', 'user_agent', 'action', 'subject_type', 'subject_id',
        'old_values', 'new_values', 'meta',
    ];

    /**
     * @param  array<string, mixed>|object  $row
     */
    public static function hash(string $prevHash, array|object $row): string
    {
        $row = (array) $row;
        $fields = [];
        foreach (self::FIELDS as $field) {
            $fields[$field] = self::normalizeField($field, $row[$field] ?? null);
        }

        $payload = json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $prevHash."\n".$payload);
    }

    public static function formatTimestamp(\DateTimeInterface|string $value): string
    {
        return CarbonImmutable::parse($value, 'UTC')->utc()->format('Y-m-d H:i:s.u');
    }

    private static function normalizeField(string $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($field) {
            'seq' => (int) $value,
            'acting_as_super_admin' => (bool) $value,
            'occurred_at' => self::formatTimestamp($value),
            default => (string) $value,
        };
    }
}

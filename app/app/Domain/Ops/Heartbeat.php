<?php

declare(strict_types=1);

namespace App\Domain\Ops;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** "I am alive" marks from the scheduler and the queue worker, read by the health check and preflight. */
final class Heartbeat
{
    public const SCHEDULER = 'scheduler';

    public const QUEUE = 'queue';

    /** @param  array<string, mixed>  $data */
    public static function beat(string $name, array $data = []): void
    {
        DB::table('system_heartbeats')->upsert(
            [['name' => $name, 'beat_at' => now()->utc()->format('Y-m-d H:i:s.v'), 'data' => json_encode($data, JSON_THROW_ON_ERROR)]],
            ['name'],
            ['beat_at', 'data'],
        );
    }

    public static function last(string $name): ?CarbonImmutable
    {
        $at = DB::table('system_heartbeats')->where('name', $name)->value('beat_at');

        return $at === null ? null : CarbonImmutable::parse((string) $at, 'UTC');
    }

    /** Minutes since the last beat (null = never). */
    public static function ageMinutes(string $name): ?float
    {
        $last = self::last($name);

        return $last === null ? null : round($last->diffInSeconds(now(), true) / 60, 1);
    }
}

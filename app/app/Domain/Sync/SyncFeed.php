<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Appends to the per-entity change feed that devices pull from.
 *
 * The sequence number comes from a head row locked FOR UPDATE inside the writing
 * transaction. The lock is held until commit, so sequence numbers become visible in
 * order and without gaps — a cursor can never skip a change that committed late
 * (the usual flaw of auto-increment cursors).
 */
final class SyncFeed
{
    public function record(string $entityId, string $type, string $recordId, int $version, string $op): int
    {
        return DB::transaction(function () use ($entityId, $type, $recordId, $version, $op): int {
            DB::table('sync_change_heads')->insertOrIgnore(['entity_id' => $entityId, 'seq' => 0]);
            $seq = (int) DB::table('sync_change_heads')->where('entity_id', $entityId)->lockForUpdate()->value('seq') + 1;

            DB::table('sync_changes')->insert([
                'id' => (string) Str::uuid7(),
                'entity_id' => $entityId,
                'seq' => $seq,
                'type' => $type,
                'record_id' => $recordId,
                'version' => $version,
                'op' => $op,
                'changed_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.v'),
            ]);
            DB::table('sync_change_heads')->where('entity_id', $entityId)->update(['seq' => $seq]);

            return $seq;
        });
    }

    public function head(string $entityId): int
    {
        return (int) DB::table('sync_change_heads')->where('entity_id', $entityId)->value('seq');
    }
}

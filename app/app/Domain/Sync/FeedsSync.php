<?php

declare(strict_types=1);

namespace App\Domain\Sync;

use App\Models\LoginAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Puts every save of the model on the per-entity change feed devices pull from, inside the
 * same transaction. The model declares SYNC_TYPE and serialises itself per viewer.
 *
 * @mixin Model
 */
trait FeedsSync
{
    public static function bootFeedsSync(): void
    {
        static::saved(function (Model $model): void {
            app(SyncFeed::class)->record(
                (string) $model->getAttribute('entity_id'),
                static::SYNC_TYPE,
                (string) $model->getKey(),
                (int) $model->getAttribute('version'),
                $model->getAttribute('archived_at') === null ? 'upsert' : 'archive',
            );
        });
    }

    /** Record + audit row + feed entry commit together. */
    public function save(array $options = []): bool
    {
        return DB::transaction(fn () => parent::save($options));
    }

    /**
     * Representation sent to devices / API clients, filtered for the viewer.
     *
     * @return array<string, mixed>
     */
    abstract public function toSyncArray(LoginAccount $viewer): array;
}

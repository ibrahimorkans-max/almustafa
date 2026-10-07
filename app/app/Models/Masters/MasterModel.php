<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Domain\Audit\Auditable;
use App\Domain\Sync\SyncFeed;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\LoginAccount;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Base for master data:
 *  - entity-scoped (scope + RLS), audited, optimistic version, created_by;
 *  - archived rows are hidden from normal queries (`withArchived()` to include them);
 *  - every save writes the change feed devices pull from, in the same transaction.
 *
 * Subclasses define SYNC_TYPE, validation rules for API/sync writes, the fields a device may
 * write, and how the record is serialised for a given viewer (confidential fields).
 *
 * @property string $id
 * @property string $entity_id
 * @property int $version
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
abstract class MasterModel extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    /** Name used in sync events ("vehicle.upsert") and the change feed. */
    public const SYNC_TYPE = '';

    protected static function booted(): void
    {
        static::addGlobalScope('not_archived', fn (Builder $q) => $q->whereNull($q->getModel()->qualifyColumn('archived_at')));

        static::saved(function (MasterModel $model): void {
            app(SyncFeed::class)->record(
                $model->entity_id,
                static::SYNC_TYPE,
                $model->id,
                (int) $model->version,
                $model->archived_at === null ? 'upsert' : 'archive',
            );
        });
    }

    /** Record + audit row + feed entry commit together. */
    public function save(array $options = []): bool
    {
        return DB::transaction(fn () => parent::save($options));
    }

    /** @param  Builder<static>  $query */
    public function scopeWithArchived(Builder $query): void
    {
        $query->withoutGlobalScope('not_archived');
    }

    /**
     * Validation rules for a write coming from the API / a device.
     *
     * @return array<string, mixed>
     */
    abstract public static function writeRules(?self $existing): array;

    /**
     * Fields a device may set through sync.
     *
     * @return list<string>
     */
    abstract public static function writableFields(): array;

    /**
     * Representation sent to devices / API clients.
     *
     * @return array<string, mixed>
     */
    abstract public function toSyncArray(LoginAccount $viewer): array;

    /** @return array<string, mixed> */
    protected function syncEnvelope(): array
    {
        return [
            'id' => $this->id,
            'version' => (int) $this->version,
            'updated_at' => $this->updated_at->toIso8601ZuluString('millisecond'),
        ];
    }

    protected static function masked(?string $value): ?string
    {
        return $value === null ? null : str_repeat('*', max(0, strlen($value) - 4)).substr($value, -4);
    }
}

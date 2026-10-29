<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Domain\Audit\Auditable;
use App\Domain\Masters\MasterCodes;
use App\Domain\Sync\FeedsSync;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Base for master data:
 *  - entity-scoped (scope + RLS), audited, optimistic version, created_by;
 *  - archived rows are hidden from normal queries (`withArchived()` to include them);
 *  - every save writes the change feed devices pull from, in the same transaction (FeedsSync).
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
    use FeedsSync;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    /** Name used in sync events ("vehicle.upsert") and the change feed. */
    public const SYNC_TYPE = '';

    /**
     * Mirrors the column default, so a model created in this request reads the same as a loaded one.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => 'active'];

    protected static function booted(): void
    {
        static::addGlobalScope('not_archived', fn (Builder $q) => $q->whereNull($q->getModel()->qualifyColumn('archived_at')));
        // A party, product or vehicle added without a code gets a permanent one (PTY-ALI-0001 …).
        static::creating(fn (MasterModel $m) => app(MasterCodes::class)->assign($m));
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

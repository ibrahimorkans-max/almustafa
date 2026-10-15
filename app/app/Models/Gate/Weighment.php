<?php

declare(strict_types=1);

namespace App\Models\Gate;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\LoginAccount;
use App\Support\Eloquent\AppendOnly;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A weight taken from the weighbridge indicator (append-only). A manual value exists only as
 * an Owner override approved by a second person (source = manual_override, with reason).
 *
 * @property string $id
 * @property string $entity_id
 * @property string $gate_entry_id
 * @property string $kind
 * @property string $weight_kg
 * @property string|null $scale_id
 * @property string $source
 * @property array<string, mixed>|null $readings
 * @property string|null $photo_sha256
 * @property string|null $supersedes_id
 * @property string|null $reason
 * @property string|null $approval_request_id
 * @property list<string>|null $flags
 * @property string|null $device_id
 * @property string|null $created_by
 * @property Carbon $captured_at
 * @property Carbon $created_at
 * @property-read Scale|null $scale
 * @property-read GateEntry|null $gateEntry
 * @property-read LoginAccount|null $creator
 */
class Weighment extends Model
{
    use AppendOnly;
    use Auditable;
    use BelongsToEntity;
    use HasUuids;
    use TracksCreator;

    public const GROSS = 'gross';

    public const TARE = 'tare';

    public const SOURCE_INDICATOR = 'indicator';

    public const SOURCE_MANUAL = 'manual_override';

    protected $fillable = [
        'gate_entry_id', 'kind', 'weight_kg', 'scale_id', 'source', 'readings', 'photo_sha256', 'supersedes_id',
        'reason', 'approval_request_id', 'flags', 'device_id', 'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'weight_kg' => 'decimal:3',
            'readings' => 'array',
            'flags' => 'array',
            'captured_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Scale, $this> */
    public function scale(): BelongsTo
    {
        return $this->belongsTo(Scale::class);
    }

    /** @return BelongsTo<GateEntry, $this> */
    public function gateEntry(): BelongsTo
    {
        return $this->belongsTo(GateEntry::class);
    }

    /** @return BelongsTo<LoginAccount, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(LoginAccount::class, 'created_by');
    }

    /** @return HasMany<WeighmentCheck, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(WeighmentCheck::class);
    }

    /** The check that counts now: the latest one (uses the loaded checks when they are loaded). */
    public function lastCheck(): ?WeighmentCheck
    {
        $checks = $this->relationLoaded('checks') ? $this->checks : $this->checks()->get();

        return $checks->sortBy(fn (WeighmentCheck $c) => $c->checked_at->format('Uu'))->last();
    }
}

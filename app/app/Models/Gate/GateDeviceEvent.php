<?php

declare(strict_types=1);

namespace App\Models\Gate;

use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Gate hardware log (append-only): number-plate reads and every barrier decision, including
 * manual opens by a guard (always with a reason).
 *
 * @property string $id
 * @property string $entity_id
 * @property string|null $gate_entry_id
 * @property string|null $device_id
 * @property string $kind
 * @property string|null $side
 * @property string|null $plate
 * @property int|null $confidence_bp
 * @property string|null $photo_sha256
 * @property string|null $reason
 * @property array<string, mixed>|null $data
 * @property string|null $actor_login_account_id
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 * @property-read GateEntry|null $gateEntry
 */
class GateDeviceEvent extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;

    public const PLATE_READ = 'plate_read';

    public const BARRIER_OPENED = 'barrier_opened';

    public const BARRIER_REFUSED = 'barrier_refused';

    public const BARRIER_MANUAL = 'barrier_manual';

    protected $fillable = [
        'entity_id', 'gate_entry_id', 'device_id', 'kind', 'side', 'plate', 'confidence_bp', 'photo_sha256', 'reason', 'data',
        'actor_login_account_id', 'occurred_at', 'created_at',
    ];

    protected function casts(): array
    {
        return ['confidence_bp' => 'integer', 'data' => 'array', 'occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<GateEntry, $this> */
    public function gateEntry(): BelongsTo
    {
        return $this->belongsTo(GateEntry::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Models\Gate;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\LoginAccount;
use App\Support\Eloquent\AppendOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One step of a vehicle visit (append-only). `anomaly` records steps that arrived out of
 * the expected order (typical after offline devices sync) — they are kept, not rejected.
 *
 * @property string $id
 * @property string $gate_entry_id
 * @property string $event
 * @property array<string, mixed>|null $data
 * @property string|null $anomaly
 * @property string|null $actor_login_account_id
 * @property string|null $device_id
 * @property Carbon $occurred_at
 * @property Carbon $recorded_at
 * @property int|null $seq running number per vehicle (null on events from before it existed)
 * @property-read LoginAccount|null $actor
 */
class GateEvent extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;

    public $timestamps = false;

    public const EVENTS = [
        'arrived', 'gross_weighed', 'tare_weighed', 'lab_accepted', 'lab_rejected', 'unloaded', 'loaded',
        'slip_issued', 'exited', 'cancelled',
    ];

    protected $fillable = ['entity_id', 'gate_entry_id', 'event', 'data', 'anomaly', 'actor_login_account_id', 'device_id', 'occurred_at', 'recorded_at', 'seq'];

    protected function casts(): array
    {
        return ['data' => 'array', 'occurred_at' => 'datetime', 'recorded_at' => 'datetime'];
    }

    /**
     * Oldest first: when it happened, when it was recorded, then the running number (two steps in the
     * same second keep their order).
     *
     * @param  Builder<GateEvent>  $query
     */
    public function scopeChronological(Builder $query): void
    {
        $query->orderBy('occurred_at')->orderBy('recorded_at')->orderByRaw('COALESCE(seq, 0)');
    }

    /** @param  Builder<GateEvent>  $query */
    public function scopeNewestFirst(Builder $query): void
    {
        $query->orderByDesc('occurred_at')->orderByDesc('recorded_at')->orderByRaw('COALESCE(seq, 0) DESC');
    }

    /** @return BelongsTo<LoginAccount, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(LoginAccount::class, 'actor_login_account_id');
    }
}

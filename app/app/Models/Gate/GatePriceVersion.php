<?php

declare(strict_types=1);

namespace App\Models\Gate;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\LoginAccount;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One price offered or negotiated for a vehicle. Versions are never changed after a decision: a new
 * price is a new version (App\Domain\Gate\Negotiation).
 *
 * @property string $id
 * @property string $entity_id
 * @property string $gate_entry_id
 * @property int $version
 * @property int $price_paise_per_maund
 * @property string $kind offered | revised
 * @property string|null $reason
 * @property string $status proposed | accepted | rejected | superseded
 * @property string|null $proposed_by
 * @property Carbon $proposed_at
 * @property string|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property-read LoginAccount|null $proposer
 * @property-read LoginAccount|null $decider
 */
class GatePriceVersion extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasUuids;

    public const PROPOSED = 'proposed';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const SUPERSEDED = 'superseded';

    protected $fillable = ['gate_entry_id', 'version', 'price_paise_per_maund', 'kind', 'reason', 'status', 'proposed_by', 'proposed_at',
        'decided_by', 'decided_at', 'decision_note'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'price_paise_per_maund' => 'integer', 'proposed_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    /** @return BelongsTo<GateEntry, $this> */
    public function gateEntry(): BelongsTo
    {
        return $this->belongsTo(GateEntry::class);
    }

    /** @return BelongsTo<LoginAccount, $this> */
    public function proposer(): BelongsTo
    {
        return $this->belongsTo(LoginAccount::class, 'proposed_by');
    }

    /** @return BelongsTo<LoginAccount, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(LoginAccount::class, 'decided_by');
    }
}

<?php

declare(strict_types=1);

namespace App\Models\Gate;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Masters\Party;
use App\Support\Eloquent\AppendOnly;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A gate pass (Gate & weighbridge → Gate passes): what leaves the mill on one vehicle — party, vehicle, bags and
 * the weight of each bag — with its serial number and a verification code (App\Domain\Gate\GatePasses).
 * Append-only: a wrong pass is cancelled with a reason, never changed.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $serial
 * @property string|null $party_id
 * @property string $party_name
 * @property string $vehicle_no
 * @property string|null $gate_entry_id
 * @property int $bag_count
 * @property string $kg_per_bag
 * @property string $total_kg
 * @property string|null $goods
 * @property string $purpose sale | other
 * @property string|null $remarks
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property-read Party|null $party
 * @property-read GatePassCancellation|null $cancellation
 */
class GatePass extends Model
{
    use AppendOnly;
    use BelongsToEntity;
    use HasUuids;

    public const PURPOSES = ['sale' => 'Sale (logo for selling)', 'other' => 'Other (the mill\'s logo)'];

    public $timestamps = false;

    protected $fillable = ['serial', 'party_id', 'party_name', 'vehicle_no', 'gate_entry_id', 'bag_count', 'kg_per_bag', 'total_kg',
        'goods', 'purpose', 'remarks', 'created_by', 'created_at'];

    protected function casts(): array
    {
        return ['bag_count' => 'integer', 'kg_per_bag' => 'decimal:3', 'total_kg' => 'decimal:3', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return HasOne<GatePassCancellation, $this> */
    public function cancellation(): HasOne
    {
        return $this->hasOne(GatePassCancellation::class);
    }

    /**
     * The figures the verification code is made over (they never change).
     *
     * @return array<string, string|int>
     */
    public function snapshot(): array
    {
        return [
            'serial' => $this->serial, 'party' => $this->party_name, 'vehicle' => $this->vehicle_no, 'bags' => (int) $this->bag_count,
            'kg_per_bag' => (string) $this->kg_per_bag, 'total_kg' => (string) $this->total_kg, 'issued' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}

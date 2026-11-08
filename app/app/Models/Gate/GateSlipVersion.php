<?php

declare(strict_types=1);

namespace App\Models\Gate;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\LoginAccount;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One slip given for a vehicle, as it was given: version 1, 2…; "initial" is the weight-only slip of a lot weighed
 * before its lab test (mode 3), "final" the slip with the weights and (when tested) the lab result. Never changed or
 * removed: a later slip is a new version beside the earlier one.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $gate_entry_id
 * @property int $version
 * @property string $kind initial | final
 * @property string|null $weigh_mode
 * @property array<string, mixed> $snapshot
 * @property string $verification_code
 * @property string|null $gate_event_id
 * @property string|null $issued_by
 * @property Carbon $issued_at
 */
class GateSlipVersion extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public const INITIAL = 'initial';

    public const FINAL = 'final';

    public $timestamps = false;

    protected $fillable = ['gate_entry_id', 'version', 'kind', 'weigh_mode', 'snapshot', 'verification_code', 'gate_event_id', 'issued_by', 'issued_at'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'version' => 'integer', 'issued_at' => 'datetime'];
    }

    /** @return BelongsTo<GateEntry, $this> */
    public function gateEntry(): BelongsTo
    {
        return $this->belongsTo(GateEntry::class);
    }

    /** @return BelongsTo<LoginAccount, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(LoginAccount::class, 'issued_by');
    }
}

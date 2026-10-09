<?php

declare(strict_types=1);

namespace App\Models\Gate;

use App\Domain\Audit\Auditable;
use App\Domain\Sync\FeedsSync;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\LoginAccount;
use App\Models\Masters\Driver;
use App\Models\Masters\Godown;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Masters\Vehicle;
use App\Models\Procurement\Sauda;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A vehicle visit. Status, weights and deductions are a PROJECTION of the append-only
 * gate events, weighments and lab decisions (see GateService::project) — never typed in.
 *
 * @property string $id
 * @property string $entity_id
 * @property string|null $number
 * @property string $direction
 * @property string $purpose
 * @property string|null $vehicle_id
 * @property string $registration_no
 * @property string|null $driver_id
 * @property string|null $driver_name
 * @property string|null $party_id
 * @property string|null $sauda_id
 * @property string|null $product_id
 * @property int|null $bag_count
 * @property string $status
 * @property list<string>|null $flags
 * @property string|null $gross_kg
 * @property string|null $tare_kg
 * @property string|null $net_kg
 * @property string|null $deduction_kg
 * @property string|null $payable_kg
 * @property int|null $rate_cut_paise_per_maund
 * @property Carbon $arrived_at
 * @property Carbon|null $exited_at
 * @property int $version
 * @property Carbon|null $archived_at
 * @property-read Vehicle|null $vehicle
 * @property-read Party|null $party
 * @property-read Sauda|null $sauda
 * @property string|null $godown_id
 * @property string|null $dispatch_order_id
 * @property-read Product|null $product
 * @property-read Godown|null $godown
 */
class GateEntry extends Model
{
    use Auditable;
    use BelongsToEntity;
    use FeedsSync;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const SYNC_TYPE = 'gate_entry';

    /** Inbound paddy happy path, in order. */
    public const INBOUND_FLOW = ['arrived', 'gross_weighed', 'lab_accepted', 'unloaded', 'tare_weighed', 'slip_issued', 'exited'];

    public const OUTBOUND_FLOW = ['arrived', 'tare_weighed', 'loaded', 'gross_weighed', 'slip_issued', 'gate_pass_issued', 'exited'];

    protected $fillable = [
        'direction', 'purpose', 'vehicle_id', 'registration_no', 'driver_id', 'driver_name', 'party_id',
        'sauda_id', 'product_id', 'bag_count', 'arrived_at', 'dispatch_order_id',
    ];

    /** @var list<string> */
    protected array $auditIgnored = ['status', 'flags', 'gross_kg', 'tare_kg', 'net_kg', 'deduction_kg', 'payable_kg', 'rate_cut_paise_per_maund', 'exited_at'];

    protected function casts(): array
    {
        return [
            'bag_count' => 'integer',
            'flags' => 'array',
            'gross_kg' => 'decimal:3',
            'tare_kg' => 'decimal:3',
            'net_kg' => 'decimal:3',
            'deduction_kg' => 'decimal:3',
            'payable_kg' => 'decimal:3',
            'rate_cut_paise_per_maund' => 'integer',
            'arrived_at' => 'datetime',
            'exited_at' => 'datetime',
            'archived_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /** @return list<string> */
    public function flow(): array
    {
        return $this->direction === 'outbound' ? self::OUTBOUND_FLOW : self::INBOUND_FLOW;
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, ['exited', 'cancelled'], true);
    }

    /** @param  Builder<static>  $query */
    public function scopeWithArchived(Builder $query): void {}

    /** @return HasMany<GateEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(GateEvent::class)->orderBy('occurred_at')->orderBy('recorded_at');
    }

    /** @return HasMany<Weighment, $this> */
    public function weighments(): HasMany
    {
        return $this->hasMany(Weighment::class)->orderBy('captured_at');
    }

    /** @return HasMany<LabTest, $this> */
    public function labTests(): HasMany
    {
        return $this->hasMany(LabTest::class)->orderBy('tested_at');
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<Driver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return BelongsTo<Sauda, $this> */
    public function sauda(): BelongsTo
    {
        return $this->belongsTo(Sauda::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<GateDeviceEvent, $this> */
    public function deviceEvents(): HasMany
    {
        return $this->hasMany(GateDeviceEvent::class)->orderBy('occurred_at');
    }

    /** @return BelongsTo<Godown, $this> */
    public function godown(): BelongsTo
    {
        return $this->belongsTo(Godown::class);
    }

    public function toSyncArray(LoginAccount $viewer): array
    {
        return [
            'id' => $this->id, 'version' => $this->version, 'number' => $this->number,
            'direction' => $this->direction, 'purpose' => $this->purpose, 'status' => $this->status,
            'vehicle_id' => $this->vehicle_id, 'registration_no' => $this->registration_no,
            'driver_id' => $this->driver_id, 'driver_name' => $this->driver_name,
            'party_id' => $this->party_id, 'sauda_id' => $this->sauda_id, 'product_id' => $this->product_id,
            'godown_id' => $this->godown_id, 'bag_count' => $this->bag_count, 'flags' => $this->flags ?? [],
            'gross_kg' => $this->gross_kg, 'tare_kg' => $this->tare_kg, 'net_kg' => $this->net_kg,
            'deduction_kg' => $this->deduction_kg, 'payable_kg' => $this->payable_kg,
            'arrived_at' => $this->arrived_at->toIso8601ZuluString('millisecond'),
            'exited_at' => $this->exited_at?->toIso8601ZuluString('millisecond'),
        ];
    }
}

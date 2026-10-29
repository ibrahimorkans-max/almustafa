<?php

declare(strict_types=1);

namespace App\Models\Gate;

use App\Domain\Audit\Auditable;
use App\Domain\Security\RecordsEntryLocation;
use App\Domain\Sync\FeedsSync;
use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Hr\ContractorBill;
use App\Models\LoginAccount;
use App\Models\Masters\Driver;
use App\Models\Masters\Godown;
use App\Models\Masters\PaddyVariety;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Masters\Vehicle;
use App\Models\Procurement\Sauda;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use App\Support\Values\PakistanIds;
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
 * @property string|null $vehicle_type
 * @property bool $no_plate
 * @property string|null $vehicle_description
 * @property string|null $goods_description what it carries when not a listed product
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
 * @property int|null $token_no the day's place in the queue (first come, first served); the printed token before the series
 * @property int|null $token_serial the token: the mill's one continuous number, shown as 000125
 * @property string|null $jack_type with_jack | without_jack
 * @property string|null $party_name the party as typed at the gate, when no registered party was chosen
 * @property string|null $paddy_variety_id
 * @property string|null $contractor_party_id the labour contractor who unloaded (or loaded) it
 * @property string|null $contractor_work unloading | loading
 * @property string|null $contractor_bill_id the contractor bill that paid for it (never billed twice)
 * @property int|null $rejected_bags bags rejected while unloading, carried back on the vehicle
 * @property string|null $rejection_reason
 * @property string|null $rejected_kg paddy rejected at unloading, by weight
 * @property bool $rejected_off_vehicle the rejected paddy was taken off before the empty weight (its kg are subtracted)
 * @property Carbon|null $token_date
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
 * @property array{lat: float, lng: float, accuracy_m: int|null, at: string}|null $captured_location where it was entered (EntryLocation)
 */
class GateEntry extends Model
{
    use Auditable;
    use BelongsToEntity;
    use FeedsSync;
    use HasOptimisticLock;
    use HasUuids;
    use RecordsEntryLocation;
    use TracksCreator;

    public const SYNC_TYPE = 'gate_entry';

    /**
     * Inbound paddy happy path, in order: the lab decides before any weighing; after the empty weight
     * the Caat and price settlement is approved, and only then the slip books stock and the bill.
     */
    public const INBOUND_FLOW = ['arrived', 'lab_accepted', 'gross_weighed', 'unloaded', 'tare_weighed', 'settlement_approved', 'slip_issued', 'exited'];

    public const OUTBOUND_FLOW = ['arrived', 'tare_weighed', 'loaded', 'gross_weighed', 'slip_issued', 'gate_pass_issued', 'exited'];

    /**
     * Simple weighing (purpose "other": goods that are not a paddy purchase or a dispatch order): weighed
     * straight away, no lab, no unloading or loading step; the slip books no stock and drafts no bill.
     */
    public const OTHER_INBOUND_FLOW = ['arrived', 'gross_weighed', 'tare_weighed', 'slip_issued', 'exited'];

    public const OTHER_OUTBOUND_FLOW = ['arrived', 'tare_weighed', 'gross_weighed', 'slip_issued', 'exited'];

    protected $fillable = [
        'direction', 'purpose', 'vehicle_id', 'registration_no', 'driver_id', 'driver_name', 'party_id',
        'sauda_id', 'product_id', 'bag_count', 'arrived_at', 'dispatch_order_id', 'vehicle_type', 'no_plate', 'vehicle_description', 'goods_description',
        'jack_type', 'party_name', 'paddy_variety_id',
    ];

    /** With Jack / Without Jack: printed on the token beside its number, never inside it. */
    public const JACK_TYPES = ['with_jack' => 'With Jack', 'without_jack' => 'Without Jack'];

    /**
     * The token as printed: six digits of the mill's one continuous series (000125). A token issued
     * before the series started keeps the day's number that was printed on it.
     */
    public function tokenLabel(): string
    {
        return match (true) {
            $this->token_serial !== null => self::formatToken($this->token_serial),
            $this->token_no !== null => (string) $this->token_no,
            default => '—',
        };
    }

    public static function formatToken(int $serial): string
    {
        return str_pad((string) $serial, 6, '0', STR_PAD_LEFT);
    }

    /** The registered party, or the name typed at the gate. */
    public function partyLabel(): ?string
    {
        return $this->party->name ?? $this->party_name;
    }

    /** The bags actually unloaded: those brought, less those rejected at unloading. */
    public function acceptedBags(): ?int
    {
        return $this->bag_count === null ? null : max(0, $this->bag_count - (int) $this->rejected_bags);
    }

    /** Rejected paddy still on the vehicle at the empty weight: that weight is not the vehicle's own. */
    public function rejectionOnVehicle(): bool
    {
        return ($this->rejected_bags > 0 || $this->rejected_kg !== null) && ! $this->rejected_off_vehicle;
    }

    public function jackLabel(): ?string
    {
        return $this->jack_type === null ? null : (self::JACK_TYPES[$this->jack_type] ?? $this->jack_type);
    }

    /** Prefix of the reference given to a vehicle with no number plate (never a valid plate: too many letters). */
    public const NO_PLATE_PREFIX = 'NOPLATE';

    /** What the vehicle carries: the product, or the goods typed at the weighbridge. */
    public function itemLabel(): string
    {
        return $this->product->name ?? $this->goods_description ?? '—';
    }

    /** How the vehicle is shown: the plate, or for one with no plate its kind and description. */
    public function vehicleLabel(): string
    {
        if ($this->no_plate) {
            return 'No plate · '.Vehicle::typeLabel($this->vehicle_type).($this->vehicle_description ? ' · '.$this->vehicle_description : '');
        }

        return PakistanIds::formatVehicleNumber($this->registration_no);
    }

    /** @var list<string> */
    protected array $auditIgnored = ['status', 'flags', 'gross_kg', 'tare_kg', 'net_kg', 'deduction_kg', 'payable_kg', 'rate_cut_paise_per_maund', 'exited_at'];

    protected function casts(): array
    {
        return [
            'bag_count' => 'integer',
            'no_plate' => 'boolean',
            'flags' => 'array',
            'gross_kg' => 'decimal:3',
            'tare_kg' => 'decimal:3',
            'net_kg' => 'decimal:3',
            'deduction_kg' => 'decimal:3',
            'payable_kg' => 'decimal:3',
            'rate_cut_paise_per_maund' => 'integer',
            'arrived_at' => 'datetime',
            'token_no' => 'integer',
            'token_serial' => 'integer',
            'rejected_bags' => 'integer',
            'rejected_kg' => 'decimal:3',
            'rejected_off_vehicle' => 'boolean',
            'token_date' => 'date',
            'exited_at' => 'datetime',
            'archived_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /** @return list<string> */
    public function flow(): array
    {
        if ($this->purpose === 'other') {
            return $this->direction === 'outbound' ? self::OTHER_OUTBOUND_FLOW : self::OTHER_INBOUND_FLOW;
        }

        return $this->direction === 'outbound' ? self::OUTBOUND_FLOW : self::INBOUND_FLOW;
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, ['exited', 'cancelled'], true);
    }

    /** @param  Builder<static>  $query */
    public function scopeWithArchived(Builder $query): void {}

    /** @return HasMany<GatePriceVersion, $this> every price offered or negotiated, oldest first */
    public function priceVersions(): HasMany
    {
        return $this->hasMany(GatePriceVersion::class)->orderBy('version');
    }

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
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'contractor_party_id');
    }

    /** @return BelongsTo<ContractorBill, $this> */
    public function contractorBill(): BelongsTo
    {
        return $this->belongsTo(ContractorBill::class);
    }

    /** @return BelongsTo<PaddyVariety, $this> */
    public function paddyVariety(): BelongsTo
    {
        return $this->belongsTo(PaddyVariety::class);
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

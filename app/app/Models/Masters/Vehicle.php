<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use App\Support\Values\PakistanIds;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;

/**
 * A blocked vehicle keeps the boom barrier closed (phase 6).
 *
 * @property string $registration_no
 * @property string $vehicle_type
 * @property string|null $owner_party_id
 * @property string|null $default_driver_id
 * @property string|null $capacity_kg
 * @property bool $is_blocked
 * @property string|null $blocked_reason
 * @property string $status
 * @property-read Party|null $owner
 * @property-read Driver|null $defaultDriver
 */
class Vehicle extends MasterModel
{
    public const SYNC_TYPE = 'vehicle';

    /** Kinds of vehicle (English and the name used at the gate). */
    public const LABELS = [
        'truck' => 'Truck (ٹرک)',
        'mini_truck' => 'Mini truck / Mazda / Shehzore (مزدا)',
        'tractor' => 'Tractor (ٹریکٹر)',
        'trolley' => 'Tractor trolley (ٹرالی)',
        'tempo' => 'Tempo / pickup (پک اپ)',
        'loader_rickshaw' => 'Loader rickshaw / Qingqi (لوڈر رکشہ)',
        'rickshaw' => 'Rickshaw (رکشہ)',
        'motorbike' => 'Motorbike (موٹر سائیکل)',
        'bicycle' => 'Bicycle (سائیکل)',
        'bull_cart' => 'Bullock cart / bail gari (بیل گاڑی)',
        'donkey_cart' => 'Donkey cart / gadha gari (گدھا گاڑی)',
        'horse_cart' => 'Horse cart / tanga (گھوڑا گاڑی)',
        'hand_cart' => 'Hand cart / rehri (ریڑھی)',
        'other' => 'Other (دیگر)',
    ];

    public const TYPES = ['truck', 'mini_truck', 'tractor', 'trolley', 'tempo', 'loader_rickshaw', 'rickshaw', 'motorbike', 'bicycle',
        'bull_cart', 'donkey_cart', 'horse_cart', 'hand_cart', 'other'];

    /** Kinds that usually carry no number plate. */
    public const USUALLY_UNREGISTERED = ['bicycle', 'bull_cart', 'donkey_cart', 'horse_cart', 'hand_cart'];

    public static function typeLabel(?string $type): string
    {
        return $type === null ? '—' : (self::LABELS[$type] ?? ucfirst(str_replace('_', ' ', $type)));
    }

    protected $fillable = [
        'registration_no', 'vehicle_type', 'owner_party_id', 'default_driver_id', 'capacity_kg', 'is_blocked', 'blocked_reason', 'status',
    ];

    protected function casts(): array
    {
        return ['capacity_kg' => 'decimal:3', 'is_blocked' => 'boolean', 'version' => 'integer', 'archived_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        parent::booted();

        static::saving(function (Vehicle $vehicle): void {
            $vehicle->registration_no = PakistanIds::normalizeVehicleNumber($vehicle->registration_no);
        });
    }

    /** @return BelongsTo<Party, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'owner_party_id');
    }

    /** @return BelongsTo<Driver, $this> */
    public function defaultDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'default_driver_id');
    }

    public static function writeRules(?MasterModel $existing): array
    {
        $entity = app(TenantContext::class)->entityId();

        return [
            'registration_no' => [
                'required', 'string', 'max:20',
                fn (string $attr, mixed $value, \Closure $fail) => PakistanIds::isValidVehicleNumber(PakistanIds::normalizeVehicleNumber((string) $value))
                    ? null : $fail('Not a valid Pakistani registration number (e.g. MNA-21-1234, LES-1234).'),
            ],
            'vehicle_type' => ['required', Rule::in(self::TYPES)],
            'owner_party_id' => ['nullable', 'uuid', Rule::exists('parties', 'id')->where('entity_id', $entity)],
            'default_driver_id' => ['nullable', 'uuid', Rule::exists('drivers', 'id')->where('entity_id', $entity)],
            'capacity_kg' => ['nullable', 'regex:/^\d{1,9}(\.\d{1,3})?$/'],
            'is_blocked' => ['sometimes', 'boolean'],
            'blocked_reason' => ['nullable', 'required_if:is_blocked,true', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    public static function writableFields(): array
    {
        return ['registration_no', 'vehicle_type', 'owner_party_id', 'default_driver_id', 'capacity_kg', 'is_blocked', 'blocked_reason', 'status'];
    }

    public function toSyncArray(LoginAccount $viewer): array
    {
        return $this->syncEnvelope() + [
            'registration_no' => $this->registration_no, 'vehicle_type' => $this->vehicle_type,
            'owner_party_id' => $this->owner_party_id, 'default_driver_id' => $this->default_driver_id,
            'capacity_kg' => $this->capacity_kg, 'is_blocked' => $this->is_blocked,
            'blocked_reason' => $this->blocked_reason, 'status' => $this->status,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use App\Support\Values\IndianIds;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * @property string $name
 * @property string|null $phone
 * @property string|null $licence_no
 * @property Carbon|null $licence_valid_until
 * @property string|null $transporter_party_id
 * @property string $status
 * @property-read Party|null $transporter
 */
class Driver extends MasterModel
{
    public const SYNC_TYPE = 'driver';

    protected $fillable = ['name', 'phone', 'licence_no', 'licence_valid_until', 'transporter_party_id', 'status'];

    protected function casts(): array
    {
        return ['licence_valid_until' => 'date', 'version' => 'integer', 'archived_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        parent::booted();

        static::saving(function (Driver $driver): void {
            $driver->phone = IndianIds::normalizeMobile($driver->phone) ?? $driver->phone;
            $driver->licence_no = $driver->licence_no !== null ? strtoupper(preg_replace('/[\s-]/', '', $driver->licence_no) ?? '') : null;
        });
    }

    /** @return BelongsTo<Party, $this> */
    public function transporter(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'transporter_party_id');
    }

    public static function writeRules(?MasterModel $existing): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:16'],
            'licence_no' => ['nullable', 'string', 'max:30'],
            'licence_valid_until' => ['nullable', 'date'],
            'transporter_party_id' => ['nullable', 'uuid', Rule::exists('parties', 'id')->where('entity_id', app(TenantContext::class)->entityId())],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    public static function writableFields(): array
    {
        return ['name', 'phone', 'licence_no', 'licence_valid_until', 'transporter_party_id', 'status'];
    }

    public function toSyncArray(LoginAccount $viewer): array
    {
        return $this->syncEnvelope() + [
            'name' => $this->name, 'phone' => $this->phone, 'licence_no' => $this->licence_no,
            'licence_valid_until' => $this->licence_valid_until?->toDateString(),
            'transporter_party_id' => $this->transporter_party_id, 'status' => $this->status,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Models\LoginAccount;
use Illuminate\Validation\Rule;

/**
 * @property string $code
 * @property string $name
 * @property string $type
 * @property string|null $capacity_kg
 * @property string|null $address
 * @property string $status
 */
class Godown extends MasterModel
{
    public const SYNC_TYPE = 'godown';

    public const TYPES = ['godown', 'silo', 'yard', 'bin'];

    protected $fillable = ['code', 'name', 'type', 'capacity_kg', 'address', 'status'];

    protected function casts(): array
    {
        return ['capacity_kg' => 'decimal:3', 'version' => 'integer', 'archived_at' => 'datetime'];
    }

    public static function writeRules(?MasterModel $existing): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(self::TYPES)],
            'capacity_kg' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,3})?$/'],
            'address' => ['nullable', 'string', 'max:500'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    public static function writableFields(): array
    {
        return ['code', 'name', 'type', 'capacity_kg', 'address', 'status'];
    }

    public function toSyncArray(LoginAccount $viewer): array
    {
        return $this->syncEnvelope() + [
            'code' => $this->code, 'name' => $this->name, 'type' => $this->type,
            'capacity_kg' => $this->capacity_kg, 'status' => $this->status,
        ];
    }
}

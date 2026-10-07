<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Models\LoginAccount;
use Illuminate\Validation\Rule;

/**
 * @property string $code
 * @property string $name
 * @property string $status
 */
class Brand extends MasterModel
{
    public const SYNC_TYPE = 'brand';

    protected $fillable = ['code', 'name', 'status'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'archived_at' => 'datetime'];
    }

    public static function writeRules(?MasterModel $existing): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }

    public static function writableFields(): array
    {
        return ['code', 'name', 'status'];
    }

    public function toSyncArray(LoginAccount $viewer): array
    {
        return $this->syncEnvelope() + ['code' => $this->code, 'name' => $this->name, 'status' => $this->status];
    }
}

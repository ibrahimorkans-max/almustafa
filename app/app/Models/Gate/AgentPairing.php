<?php

declare(strict_types=1);

namespace App\Models\Gate;

use App\Domain\Tenancy\BelongsToEntity;
use App\Models\Device;
use App\Models\LoginAccount;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-time code that hands a weighbridge PC its settings (App\Domain\Gate\AgentPairing).
 *
 * @property string $id
 * @property string $entity_id
 * @property string $device_id
 * @property string $login_account_id
 * @property string|null $scale_id
 * @property string $code_hash
 * @property array<string, mixed>|null $payload
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
class AgentPairing extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['entity_id', 'device_id', 'login_account_id', 'scale_id', 'code_hash', 'payload', 'expires_at', 'created_by'];

    protected $hidden = ['payload', 'code_hash'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function loginAccount(): BelongsTo
    {
        return $this->belongsTo(LoginAccount::class);
    }

    public function isOpen(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}

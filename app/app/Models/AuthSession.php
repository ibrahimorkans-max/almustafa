<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToEntity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One login (web or API) on one device. Revoking it ends the web session
 * and invalidates every API token issued under it.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $login_account_id
 * @property string|null $device_id
 * @property string $channel
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon|null $mfa_passed_at
 * @property Carbon $expires_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_by
 * @property string|null $revoked_reason
 * @property Carbon $created_at
 * @property-read LoginAccount $loginAccount
 * @property-read Device|null $device
 */
class AuthSession extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public const CHANNEL_WEB = 'web';

    public const CHANNEL_API = 'api';

    protected $fillable = [
        'entity_id', 'login_account_id', 'device_id', 'channel', 'ip', 'user_agent', 'mfa_passed_at', 'expires_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'mfa_passed_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LoginAccount, $this> */
    public function loginAccount(): BelongsTo
    {
        return $this->belongsTo(LoginAccount::class);
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $entity_id
 * @property string $login_account_id
 * @property string $device_uid
 * @property string|null $name
 * @property string $platform
 * @property string $status
 * @property Carbon|null $trusted_at
 * @property string|null $trusted_by
 * @property Carbon|null $last_seen_at
 * @property string|null $last_ip
 * @property Carbon|null $wipe_requested_at
 * @property string|null $wipe_requested_by
 * @property Carbon|null $wiped_at
 * @property Carbon|null $reported_lost_at
 * @property string|null $signing_secret
 * @property-read LoginAccount $loginAccount
 */
class Device extends Model
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use TracksCreator;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_WIPE_PENDING = 'wipe_pending';

    public const STATUS_WIPED = 'wiped';

    public const PLATFORMS = ['web', 'pwa', 'android', 'ios', 'agent'];

    protected $fillable = ['entity_id', 'login_account_id', 'device_uid', 'name', 'platform', 'status', 'last_seen_at', 'last_ip'];

    protected $hidden = ['signing_secret'];

    /** @var list<string> */
    protected array $auditSecret = ['signing_secret'];

    /** @var list<string> */
    protected array $auditIgnored = ['last_seen_at', 'last_ip'];

    protected function casts(): array
    {
        return [
            'trusted_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'wipe_requested_at' => 'datetime',
            'wiped_at' => 'datetime',
            'reported_lost_at' => 'datetime',
            'signing_secret' => 'encrypted',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<LoginAccount, $this> */
    public function loginAccount(): BelongsTo
    {
        return $this->belongsTo(LoginAccount::class);
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** A wipe-pending device may still authenticate, but only to receive the wipe instruction. */
    public function canReceiveInstructions(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_WIPE_PENDING], true);
    }

    public function isTrusted(): bool
    {
        return $this->trusted_at !== null;
    }
}

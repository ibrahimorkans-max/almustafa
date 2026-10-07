<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToEntity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Opaque bearer token; only its SHA-256 hash is stored.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $auth_session_id
 * @property string $device_id
 * @property string $type
 * @property string $token_hash
 * @property string|null $parent_id
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $revoked_at
 * @property-read AuthSession $authSession
 */
class ApiToken extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public const UPDATED_AT = null;

    public const TYPE_ACCESS = 'access';

    public const TYPE_REFRESH = 'refresh';

    protected $fillable = ['entity_id', 'auth_session_id', 'device_id', 'type', 'token_hash', 'parent_id', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AuthSession, $this> */
    public function authSession(): BelongsTo
    {
        return $this->belongsTo(AuthSession::class);
    }
}

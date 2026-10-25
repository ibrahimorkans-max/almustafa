<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToEntity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A phone or browser a party gets notifications on (Firebase Cloud Messaging registration token).
 *
 * @property string $id
 * @property string $entity_id
 * @property string $party_id
 * @property string|null $login_account_id
 * @property string $token
 * @property string $token_hash
 * @property string|null $device
 * @property Carbon|null $last_used_at
 */
class PartyPushToken extends Model
{
    use BelongsToEntity;
    use HasUuids;

    protected $fillable = ['party_id', 'login_account_id', 'token', 'token_hash', 'device', 'last_used_at'];

    /** @var list<string> */
    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'last_used_at' => 'datetime'];
    }
}

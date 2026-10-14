<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToEntity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Carbon;
use Spatie\LaravelPasskeys\Models\Passkey as BasePasskey;

/**
 * A passkey: the public half of a key kept on one device (phone, laptop, security key) and unlocked
 * there with a fingerprint, face or the device PIN. The private key never leaves the device, so a
 * stolen database or a fake sign-in page cannot use it. Sign-in by passkey counts as two factors.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $authenticatable_id
 * @property string $name
 * @property string $credential_id
 * @property Carbon|null $last_used_at
 * @property Carbon $created_at
 * @property-read LoginAccount $authenticatable
 */
class Passkey extends BasePasskey
{
    use BelongsToEntity;
    use HasUuids;

    protected $table = 'passkeys';
}

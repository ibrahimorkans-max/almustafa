<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToEntity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A new user's first sign-in waiting for somebody already signed in to approve it: they scan its QR code (or open
 * Approve sign-ins) and pick the number shown on the new screen (App\Domain\Auth\SignInApprovals).
 *
 * @property string $id
 * @property string $entity_id
 * @property string $login_account_id
 * @property string|null $auth_session_id
 * @property string $purpose first_sign_in | new_network
 * @property string $token_hash
 * @property int $number
 * @property list<int> $choices
 * @property string $status
 * @property string|null $reason
 * @property string|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon $expires_at
 * @property Carbon $created_at
 * @property-read LoginAccount $loginAccount
 */
class SignInApproval extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DENIED = 'denied';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'choices' => 'array',
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LoginAccount, $this> */
    public function loginAccount(): BelongsTo
    {
        return $this->belongsTo(LoginAccount::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::PENDING && $this->expires_at->isFuture();
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A company using the system (SaaS): its mills (entities), its plan and its billing. Only the platform
 * console lists customers; a customer's own people only ever see their own.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $status trial | active | past_due | suspended | cancelled
 * @property string|null $plan_id
 * @property Carbon|null $trial_ends_at
 * @property bool $lifetime
 * @property Carbon|null $lifetime_since
 * @property string|null $source signup | platform (null: the first installation)
 * @property string|null $contact_name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $notes
 * @property Carbon $created_at
 */
class Customer extends Model
{
    use Auditable;
    use HasUuids;

    public const TRIAL = 'trial';

    public const ACTIVE = 'active';

    public const PAST_DUE = 'past_due';

    public const SUSPENDED = 'suspended';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['code', 'name', 'status', 'plan_id', 'trial_ends_at', 'lifetime', 'lifetime_since', 'source', 'contact_name', 'phone', 'email', 'address', 'notes'];

    protected function casts(): array
    {
        return ['trial_ends_at' => 'datetime', 'lifetime' => 'boolean', 'lifetime_since' => 'datetime', 'bank_email_enabled' => 'boolean'];
    }

    /** @return HasMany<Entity, $this> */
    public function entities(): HasMany
    {
        return $this->hasMany(Entity::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function trialEnded(): bool
    {
        return $this->status === self::TRIAL && $this->trial_ends_at !== null && $this->trial_ends_at->isPast();
    }

    /** Whole days of trial left (0 on the last day); null when not on a trial. */
    public function trialDaysLeft(): ?int
    {
        if ($this->status !== self::TRIAL || $this->trial_ends_at === null) {
            return null;
        }

        return max(0, (int) floor(now()->diffInDays($this->trial_ends_at, false)));
    }

    /** Its people may not sign in at all: the account is closed. */
    public function isShutOut(): bool
    {
        return $this->status === self::CANCELLED;
    }

    /** Its people see only the payment page: the free trial is over, or access is paused. */
    public function mustPay(): bool
    {
        return $this->status === self::SUSPENDED || $this->trialEnded();
    }

    /** Given lifetime access by the product owner: never billed, never read-only or paused automatically. */
    public function isLifetime(): bool
    {
        return (bool) $this->lifetime;   // a customer just created in code has no value yet: not lifetime
    }
}

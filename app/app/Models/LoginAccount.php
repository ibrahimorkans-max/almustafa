<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToEntity;
use App\Support\Eloquent\HasOptimisticLock;
use App\Support\Eloquent\TracksCreator;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys;
use Spatie\LaravelPasskeys\Models\Concerns\InteractsWithPasskeys;

/**
 * The authenticatable user. Bound to exactly one entity and one role,
 * with its own password, 2FA secret, lockout state, devices and sessions.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $person_id
 * @property string $role_id
 * @property string $username
 * @property string $password
 * @property string $status
 * @property string|null $mfa_secret
 * @property Carbon|null $mfa_confirmed_at
 * @property int|null $mfa_last_timestep
 * @property int $failed_attempts
 * @property Carbon|null $locked_until
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property Carbon|null $password_changed_at
 * @property bool $must_change_password
 * @property bool $require_trusted_device
 * @property string|null $duress_pin_hash
 * @property Carbon|null $duress_pin_set_at
 * @property bool $duress_suspends
 * @property bool $duress_wipes_device
 * @property Carbon|null $duress_flagged_at
 * @property int $lockouts_24h
 * @property Carbon|null $last_lockout_at
 * @property bool $needs_admin_unlock
 * @property-read Person $person
 * @property-read Role $role
 * @property-read Entity $entity
 */
class LoginAccount extends Authenticatable implements FilamentUser, HasName, HasPasskeys
{
    use Auditable;
    use BelongsToEntity;
    use HasOptimisticLock;
    use HasUuids;
    use InteractsWithPasskeys;
    use Notifiable;
    use TracksCreator;

    public const STATUS_ACTIVE = 'active';

    /** Access withdrawn for now; an administrator can reinstate it. */
    public const STATUS_SUSPENDED = 'suspended';

    /** Access withdrawn for good (e.g. the person left); never reinstated — create a new account. */
    public const STATUS_REVOKED = 'revoked';

    /** @deprecated earlier name of {@see self::STATUS_SUSPENDED} */
    public const STATUS_DISABLED = self::STATUS_SUSPENDED;

    protected $fillable = [
        'entity_id', 'person_id', 'role_id', 'username', 'password', 'status', 'must_change_password',
    ];

    protected $hidden = ['password', 'mfa_secret', 'duress_pin_hash'];

    /** @var list<string> */
    protected array $auditSecret = ['password', 'mfa_secret', 'duress_pin_hash'];

    /** @var list<string> */
    protected array $auditIgnored = ['last_login_at', 'last_login_ip', 'failed_attempts', 'mfa_last_timestep'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'mfa_secret' => 'encrypted',
            'mfa_confirmed_at' => 'datetime',
            'mfa_last_timestep' => 'integer',
            'failed_attempts' => 'integer',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'must_change_password' => 'boolean',
            'require_trusted_device' => 'boolean',
            'duress_pin_set_at' => 'datetime',
            'duress_suspends' => 'boolean',
            'duress_wipes_device' => 'boolean',
            'duress_flagged_at' => 'datetime',
            'lockouts_24h' => 'integer',
            'last_lockout_at' => 'datetime',
            'needs_admin_unlock' => 'boolean',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (LoginAccount $account): void {
            $account->username = mb_strtolower(trim($account->username));
            if ($account->isDirty('password')) {
                $account->password_changed_at = now();
            }
        });
    }

    /** "Remember me" is disabled: sessions are bounded by auth_sessions. */
    public function getRememberTokenName(): string
    {
        return '';
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** @return HasMany<AuthSession, $this> */
    public function authSessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
    }

    /** @return HasMany<IpAllowlistEntry, $this> */
    public function ipAllowlist(): HasMany
    {
        return $this->hasMany(IpAllowlistEntry::class);
    }

    public function isAccessWithdrawn(): bool
    {
        return in_array($this->status, [self::STATUS_SUSPENDED, self::STATUS_REVOKED], true);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isLocked(): bool
    {
        return $this->needs_admin_unlock || ($this->locked_until !== null && $this->locked_until->isFuture());
    }

    public function mayUseDuressPin(): bool
    {
        return in_array($this->role?->code, (array) config('ricemill.security.duress_roles'), true);
    }

    public function isOwner(): bool
    {
        return (bool) $this->role?->is_super_admin;
    }

    public function hasMfa(): bool
    {
        return $this->mfa_confirmed_at !== null && $this->mfa_secret !== null;
    }

    public function mfaRequired(): bool
    {
        return (bool) $this->role?->mfa_required;
    }

    /** @return list<string> */
    public function permissionCodes(): array
    {
        return $this->role?->permissionCodes() ?? [];
    }

    /** Pending 2FA enrollment or a forced password change: the account holds no permissions yet. */
    public function hasPendingSecuritySetup(): bool
    {
        return ($this->mfaRequired() && ! $this->hasMfa()) || $this->must_change_password;
    }

    public function hasPermission(string $code): bool
    {
        return $this->isActive()
            && ! $this->hasPendingSecuritySetup()
            && in_array($code, $this->permissionCodes(), true);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // Brokers/agents use the portal/PWA, not the back office. Inactive accounts are signed out
        // by EnsureWebAuthSession with an explanation instead of a bare 403 here.
        return $this->role?->code !== 'BROKER';
    }

    public function getFilamentName(): string
    {
        return $this->person->full_name ?? $this->username;
    }

    /** Shown on the device when choosing a passkey. */
    public function getPasskeyName(): string
    {
        return $this->username;
    }

    public function getPasskeyId(): string
    {
        return $this->id;
    }

    public function getPasskeyDisplayName(): string
    {
        return ($this->person->full_name ?? $this->username).' ('.(config('ricemill.brand.name')).')';
    }
}

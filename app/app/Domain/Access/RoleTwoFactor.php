<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\LoginAccount;
use App\Models\Role;

/**
 * Whether everyone in a role must set up their own authenticator app (two-factor). With it, a person proves
 * a new network, a new phone and a forgotten password themselves, without asking anyone. The Owner or Super
 * Admin of the mill decides per role:
 *  - switching it on applies at once (people in the role set it up at their next screen);
 *  - switching it off makes sign-in weaker, so it needs a reason.
 * The Owner and Super Admin roles always require it. Every change is in the audit log.
 */
final class RoleTwoFactor
{
    public function __construct(private readonly AuditLogger $audit) {}

    public static function canChange(LoginAccount $me, Role $role): bool
    {
        return $me->isOwner() && ! $role->is_super_admin && ($role->entity_id !== null || $me->isPlatformAdmin());
    }

    public function set(LoginAccount $me, Role $role, bool $required, ?string $reason = null): void
    {
        if (! self::canChange($me, $role)) {
            throw new BusinessRuleViolation('Only the Owner or Super Admin can change this, and the Owner and Super Admin roles always need two-factor.');
        }
        if ($role->mfa_required === $required) {
            return;
        }
        $reason = trim((string) $reason);
        if (! $required && mb_strlen($reason) < 5) {
            throw new BusinessRuleViolation('Say why two-factor is no longer needed for this role.');
        }
        $role->forceFill(['mfa_required' => $required])->save();
        $this->audit->record('role.mfa_required', $role, ['mfa_required' => ! $required], ['mfa_required' => $required],
            ['role' => $role->code, 'reason' => $reason !== '' ? $reason : null]);
    }

    /** People in the role who have not set up an authenticator yet. */
    public static function notSetUp(Role $role): int
    {
        return LoginAccount::query()->where('role_id', $role->id)->where('status', LoginAccount::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('mfa_confirmed_at')->orWhereNull('mfa_secret'))->count();
    }
}

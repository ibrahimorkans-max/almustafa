<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Access\RoleTemplates;
use App\Domain\Audit\AuditLogger;
use App\Domain\Security\SecurityNotifier;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Forgot your password?" on the sign-in page — only for the people at the top: the SaaS owner, a company's Owners and
 * a mill's administrator (Super Admin); everybody else asks them. A 6-digit code goes to the login's own e-mail
 * (EmailCode, for this browser only); with it a new password is set, every session ends and the Owners are told.
 * Two-step verification is not touched: it is still asked at the next sign-in. Whether a login exists, may reset or
 * has an e-mail is never shown: the answers are the same for every username.
 */
final class PasswordReset
{
    public const ROLES = [RoleTemplates::OWNER, RoleTemplates::SUPER_ADMIN];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('ricemill.auth.password_reset', true);
    }

    public function eligible(LoginAccount $account): bool
    {
        return $account->isActive() && ! $account->is_machine && ! $account->needsFirstSignIn()
            && ($account->isPlatformAdmin() || $account->isOwner() || in_array($account->role?->code, self::ROLES, true))
            && filled($account->email);
    }

    /** Sends the code when the login may reset its password; says nothing either way. */
    public function request(string $username, string $browserToken, ?string $ip): void
    {
        $byAddress = 'password-reset:'.($ip ?? '-');
        if (RateLimiter::tooManyAttempts($byAddress, 5)) {
            throw new BusinessRuleViolation('Too many requests from here. Try again later.');
        }
        RateLimiter::hit($byAddress, 3600);

        $account = $this->find($username);
        if ($account === null || ! $this->enabled() || ! $this->eligible($account)) {
            $this->tenant->runAsSystem(fn () => $this->audit->record('auth.password_reset.not_sent', $account, meta: [
                'username' => mb_substr(mb_strtolower(trim($username)), 0, 64), 'ip' => $ip,
                'reason' => $account === null ? 'unknown_user' : 'not_eligible',
            ]));

            return;
        }
        try {
            $this->tenant->runAsSystem(fn () => app(EmailCode::class)->send($account, null, $browserToken, EmailCode::PASSWORD_RESET, signInInfo: ['ip' => $ip]));
        } catch (BusinessRuleViolation) {
            return;   // too soon / too many for this login: the same answer
        }
    }

    /** The code and the new password: set, every session ends, the Owners are told. */
    public function reset(string $username, string $code, string $password, string $browserToken, ?string $ip): void
    {
        $account = $this->find($username);
        if ($account === null || ! $this->eligible($account)) {
            throw new BusinessRuleViolation('The code is wrong or too old.');
        }
        try {
            $this->tenant->runAsSystem(fn () => app(EmailCode::class)->check($account, $code, $browserToken));
        } catch (BusinessRuleViolation $e) {
            throw new BusinessRuleViolation(str_contains($e->getMessage(), 'too many') ? $e->getMessage() : 'The code is wrong or too old.');
        }
        if ($account->duress_pin_hash !== null && Hash::check($password, $account->duress_pin_hash)) {
            throw new BusinessRuleViolation('The new password must be different from your duress PIN.');
        }
        $this->tenant->runAsSystem(function () use ($account, $password): void {
            DB::transaction(function () use ($account, $password): void {
                $account->password = $password;
                $account->must_change_password = false;
                $account->failed_attempts = 0;
                $account->locked_until = null;
                $account->save();
                LoginService::clearAddressLocks($account);
                app(SessionService::class)->revokeAllFor($account, 'password_reset');
            });
        });
        $this->tenant->runAsSystem(fn () => $this->audit->record('auth.password_reset.done', $account, meta: ['ip' => $ip]));
        app(SecurityNotifier::class)->owners($account->entity_id, "Password reset: {$account->username}",
            "The password of {$account->username} was reset with a code sent to its e-mail, from {$ip}. Every session of the login was ended. "
            .'If this was not them, suspend the login at once.', includeOwners: true);
    }

    private function find(string $username): ?LoginAccount
    {
        $username = mb_strtolower(trim($username));

        return $username === '' ? null : $this->tenant->runAsSystem(fn () => LoginAccount::query()->withoutGlobalScopes()
            ->with(['role', 'entity'])->where('username', $username)->first());
    }
}

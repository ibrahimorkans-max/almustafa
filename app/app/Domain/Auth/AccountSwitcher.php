<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\Exceptions\AuthenticationFailed;
use App\Domain\Settings\SettingsService;
use App\Domain\Tenancy\TenantContext;
use App\Models\AuthSession;
use App\Models\LoginAccount;
use Illuminate\Support\Collection;

/**
 * "Single login with entity switcher" mode: a person signed in with one of their
 * login accounts may switch to another of their accounts without a password.
 * The target account's own rules still apply: status, entity status, IP allowlist,
 * device checks, and its 2FA code if it has 2FA enabled (or must enroll).
 */
final class AccountSwitcher
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly TenantContext $tenant,
        private readonly LoginService $login,
        private readonly MfaService $mfa,
        private readonly SessionService $sessions,
        private readonly AuditLogger $audit,
        private readonly AuthContextBinder $binder,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->loginMode() === SettingsService::LOGIN_MODE_SWITCHER;
    }

    /** @return Collection<int, LoginAccount> other active accounts of the same person */
    public function switchableAccounts(LoginAccount $current): Collection
    {
        if (! $this->enabled()) {
            return new Collection;
        }

        return $this->tenant->runAsSystem(fn () => LoginAccount::query()
            ->with(['entity', 'role'])
            ->where('person_id', $current->person_id)
            ->where('id', '!=', $current->id)
            ->where('status', LoginAccount::STATUS_ACTIVE)
            ->get());
    }

    /** @throws AuthenticationFailed */
    public function switch(
        LoginAccount $current,
        AuthSession $currentSession,
        string $targetAccountId,
        ?string $mfaCode,
        DeviceInfo $device,
        ?string $ip,
        ?string $userAgent,
    ): AuthSession {
        if (! $this->enabled()) {
            throw new AuthenticationFailed(AuthenticationFailed::SESSION_INVALID, 'switcher_disabled', 403);
        }

        $target = $this->switchableAccounts($current)->firstWhere('id', $targetAccountId)
            ?? throw new AuthenticationFailed(AuthenticationFailed::INVALID_CREDENTIALS, 'switch_target_not_allowed', 403);
        $target->loadMissing('person');

        if (! $target->isSuperAdmin() && ! $target->entity->isActive()) {
            throw AuthenticationFailed::credentials('switch_target_entity_inactive');
        }

        $this->tenant->runInEntity($target->entity_id, function () use ($target, $ip): void {
            if (! app(IpAllowlistPolicy::class)->allows($target, $ip)) {
                throw new AuthenticationFailed(AuthenticationFailed::NETWORK_NOT_ALLOWED, 'ip_not_allowed', 403);
            }
        });

        $step = $this->login->mfaStep($target);
        $mfaPassed = false;
        if ($step === LoginService::MFA_CHALLENGE) {
            $this->tenant->runInEntity($target->entity_id, function () use ($target, $mfaCode): void {
                if ($mfaCode === null || ! $this->mfa->verifyAny($target, $mfaCode)) {
                    throw new AuthenticationFailed(AuthenticationFailed::INVALID_MFA_CODE, 'switch_bad_mfa_code');
                }
            });
            $mfaPassed = true;
        }

        $this->audit->record('auth.account.switched_out', $current, meta: ['to_login_account_id' => $target->id]);
        $this->sessions->revoke($currentSession, 'switched_account');

        // From here on everything (device, session, audit) belongs to the target's entity.
        $this->binder->bind($target);
        $session = $this->login->completeLogin($target, $currentSession->channel, $device, $ip, $userAgent, $mfaPassed);
        $this->audit->record('auth.account.switched_in', $target, meta: ['from_login_account_id' => $current->id]);

        return $session;
    }
}

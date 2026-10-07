<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\Exceptions\AuthenticationFailed;
use App\Domain\Tenancy\TenantContext;
use App\Models\AuthSession;
use App\Models\LoginAccount;
use Illuminate\Support\Facades\Hash;

/**
 * Password + second-factor login, shared by the Filament panel and the API.
 *
 *   $account = verifyPassword(...)      // lockout, status, IP allowlist
 *   mfaStep($account)                   // 'none' | 'challenge' | 'enroll'
 *   verifySecondFactor($account, $code) // when 'challenge'
 *   completeLogin(...)                  // device checks, session, audit
 *
 * Failures are counted and audited before the exception is thrown; these writes are
 * deliberately not wrapped in a transaction so they survive the failed request.
 */
final class LoginService
{
    public const MFA_NONE = 'none';

    public const MFA_CHALLENGE = 'challenge';

    public const MFA_ENROLL = 'enroll';

    /** Real Argon2id hash with current parameters; keeps timing similar for unknown usernames. */
    private static ?string $dummyHash = null;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly MfaService $mfa,
        private readonly DeviceService $devices,
        private readonly SessionService $sessions,
        private readonly IpAllowlistPolicy $ipAllowlist,
        private readonly AuthContextBinder $binder,
        private readonly ActorContext $actor,
    ) {}

    /** @throws AuthenticationFailed */
    public function verifyPassword(string $username, string $password, ?string $ip): LoginAccount
    {
        $username = mb_strtolower(trim($username));

        $account = $this->tenant->runAsSystem(fn () => LoginAccount::query()
            ->with(['role', 'person', 'entity'])
            ->where('username', $username)
            ->first());

        if ($account === null) {
            Hash::check($password, self::$dummyHash ??= Hash::make(bin2hex(random_bytes(16))));
            $this->tenant->runAsSystem(fn () => $this->audit->record('auth.login.failed', meta: [
                'reason' => 'unknown_user', 'username' => mb_substr($username, 0, 64),
            ]));

            throw AuthenticationFailed::credentials('unknown_user');
        }

        // From here on we act inside the account's entity (audit chain, RLS).
        $this->binder->bind($account);
        $this->actor->clear();

        if ($account->isLocked()) {
            $this->blocked($account, 'locked');
        }
        if (! $account->isActive()) {
            $this->blocked($account, 'account_'.$account->status);
        }
        if (! $account->isSuperAdmin() && ! $account->entity->isActive()) {
            $this->blocked($account, 'entity_'.$account->entity->status);
        }

        if (! Hash::check($password, $account->password)) {
            $this->registerFailure($account, 'bad_password');

            throw AuthenticationFailed::credentials('bad_password');
        }

        if (Hash::needsRehash($account->password)) {
            $account->password = $password;
            $account->password_changed_at = $account->getOriginal('password_changed_at');
            $account->save();
        }

        if (! $this->ipAllowlist->allows($account, $ip)) {
            $this->audit->record('auth.login.blocked', $account, meta: ['reason' => 'ip_not_allowed', 'ip' => $ip]);

            throw new AuthenticationFailed(AuthenticationFailed::NETWORK_NOT_ALLOWED, 'ip_not_allowed', 403);
        }

        return $account;
    }

    public function mfaStep(LoginAccount $account): string
    {
        if ($account->hasMfa()) {
            return self::MFA_CHALLENGE;
        }

        return $account->mfaRequired() ? self::MFA_ENROLL : self::MFA_NONE;
    }

    /** @throws AuthenticationFailed */
    public function verifySecondFactor(LoginAccount $account, string $code): void
    {
        if (! $this->mfa->verifyAny($account, $code)) {
            $this->registerFailure($account, 'bad_mfa_code');

            throw new AuthenticationFailed(AuthenticationFailed::INVALID_MFA_CODE, 'bad_mfa_code');
        }
    }

    /** @throws AuthenticationFailed */
    public function completeLogin(
        LoginAccount $account,
        string $channel,
        DeviceInfo $deviceInfo,
        ?string $ip,
        ?string $userAgent,
        bool $mfaPassed,
    ): AuthSession {
        $device = $this->devices->resolveForLogin($account, $deviceInfo, $ip);
        $session = $this->sessions->start($account, $channel, $device, $ip, $userAgent, $mfaPassed);

        $account->forceFill([
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->save();

        $this->binder->bind($account, $session, $device->id);
        $this->audit->record('auth.login.succeeded', $account, meta: [
            'channel' => $channel, 'session_id' => $session->id, 'mfa' => $mfaPassed,
        ]);

        return $session;
    }

    private function registerFailure(LoginAccount $account, string $reason): void
    {
        $attempts = $account->failed_attempts + 1;
        $max = (int) config('ricemill.auth.max_failed_attempts');
        $lock = $attempts >= $max;

        $account->forceFill([
            'failed_attempts' => $lock ? 0 : $attempts,
            'locked_until' => $lock ? now()->addMinutes((int) config('ricemill.auth.lockout_minutes')) : $account->locked_until,
        ])->save();

        $this->audit->record('auth.login.failed', $account, meta: ['reason' => $reason, 'attempt' => $attempts]);
        if ($lock) {
            $this->audit->record('auth.account.locked', $account, meta: ['minutes' => (int) config('ricemill.auth.lockout_minutes')]);
        }
    }

    /** @throws AuthenticationFailed */
    private function blocked(LoginAccount $account, string $reason): never
    {
        $this->audit->record('auth.login.blocked', $account, meta: ['reason' => $reason]);

        throw AuthenticationFailed::credentials($reason);
    }
}

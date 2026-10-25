<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\Exceptions\AuthenticationFailed;
use App\Domain\Saas\CustomerAccess;
use App\Domain\Security\DuressService;
use App\Domain\Security\Lockdown;
use App\Domain\Security\SecurityNotifier;
use App\Domain\Security\SignInAlerts;
use App\Domain\Security\SignInArea;
use App\Domain\Tenancy\TenantContext;
use App\Http\Middleware\VerifyAgentSignature;
use App\Models\AuthSession;
use App\Models\Device;
use App\Models\LoginAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
    public function verifyPassword(string $username, string $password, ?string $ip, ?string $deviceUid = null): LoginAccount
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

        // Duress PIN typed instead of the password: alert silently, end everything, neutral message.
        if ($account->duress_pin_hash !== null && app(DuressService::class)->matches($account, $password)) {
            app(DuressService::class)->trigger($account, $ip, $deviceUid, request()->userAgent());

            throw new AuthenticationFailed(AuthenticationFailed::SIGNED_OUT, 'duress', 401);
        }

        if ($account->isLocked()) {
            $this->blocked($account, 'locked');
        }
        // This address is paused or locked out for this account (wrong passwords from here).
        if ($this->addressLockedUntil($account, $ip)?->isFuture()) {
            $this->blocked($account, 'locked_here');
        }
        if (! $account->isActive() && ! $account->isAccessWithdrawn()) {
            $this->blocked($account, 'account_'.$account->status);
        }
        if (! $account->isOwner() && ! $account->entity->isActive()) {
            $this->blocked($account, 'entity_'.$account->entity->status);
        }

        if (! Hash::check($password, $account->password)) {
            $this->registerFailure($account, 'bad_password', $ip);

            throw AuthenticationFailed::credentials('bad_password');
        }

        $this->admit($account, $password, $ip, $deviceUid);

        return $account;
    }

    /**
     * Sign-in with a passkey the browser has already proven (PasskeyService): the same account checks as
     * a password, without the password. The passkey is something the person has and is unlocked with
     * their fingerprint, face or device PIN, so it stands for both factors.
     *
     * @throws AuthenticationFailed
     */
    public function verifyPasskey(LoginAccount $account, ?string $ip, ?string $deviceUid = null): LoginAccount
    {
        $this->binder->bind($account);
        $this->actor->clear();
        if ($account->isLocked()) {
            $this->blocked($account, 'locked');
        }
        if (! $account->isActive() && ! $account->isAccessWithdrawn()) {
            $this->blocked($account, 'account_'.$account->status);
        }
        if (! $account->isOwner() && ! $account->entity->isActive()) {
            $this->blocked($account, 'entity_'.$account->entity->status);
        }
        $this->admit($account, null, $ip, $deviceUid);

        return $account;
    }

    /**
     * Once the person has proven who they are: withdrawn access, lockdown and the IP allow-list.
     *
     * @throws AuthenticationFailed
     */
    private function admit(LoginAccount $account, ?string $password, ?string $ip, ?string $deviceUid): void
    {
        // Only after the right password: a suspended / revoked person is told plainly, and nothing else.
        if ($account->isAccessWithdrawn()) {
            $this->audit->record('auth.login.blocked', $account, meta: [
                'reason' => 'account_'.$account->status, 'ip' => $ip, 'device_uid' => $deviceUid, 'at' => now()->utc()->toIso8601String(),
            ]);

            throw new AuthenticationFailed(AuthenticationFailed::ACCESS_DISABLED, 'account_'.$account->status, 403);
        }

        if (app(Lockdown::class)->blocks($account)) {
            $this->audit->record('auth.login.blocked', $account, meta: ['reason' => 'lockdown', 'ip' => $ip, 'device_uid' => $deviceUid]);

            throw new AuthenticationFailed(AuthenticationFailed::SYSTEM_LOCKED, 'lockdown', 423);
        }

        // SaaS: the company's account is closed. (Trial over or access paused: it signs in to the payment page.)
        $shutOut = app(CustomerAccess::class)->signInBlocked($account) ? 'cancelled' : null;
        if ($shutOut !== null) {
            $this->audit->record('auth.login.blocked', $account, meta: ['reason' => 'customer_'.$shutOut, 'ip' => $ip]);

            throw new AuthenticationFailed(AuthenticationFailed::SUBSCRIPTION_ENDED, $shutOut, 402);
        }

        if ($password !== null && Hash::needsRehash($account->password)) {
            $account->password = $password;
            $account->password_changed_at = $account->getOriginal('password_changed_at');
            $account->save();
        }

        if (! $this->ipAllowlist->allows($account, $ip)) {
            $this->audit->record('auth.login.blocked', $account, meta: ['reason' => 'ip_not_allowed', 'ip' => $ip]);

            throw new AuthenticationFailed(AuthenticationFailed::NETWORK_NOT_ALLOWED, 'ip_not_allowed', 403);
        }
    }

    /**
     * "Only inside the mill" (SignInArea): the device's position must be inside one of the mill's sites. A
     * registered gate agent (a computer fixed at the weighbridge, no GPS) is not held to it, but only when the
     * sign-in request is signed with that agent's secret (`$signedRequest`): its device ID alone is not a secret.
     * The web sign-in never passes one, so a browser is never excused.
     *
     * @throws AuthenticationFailed
     */
    public function assertInsideArea(LoginAccount $account, mixed $latitude, mixed $longitude, mixed $accuracy, ?string $ip, ?string $deviceUid = null, ?Request $signedRequest = null): void
    {
        if ($deviceUid !== null && $signedRequest !== null) {
            $agent = $this->tenant->runAsSystem(fn () => Device::query()->where('login_account_id', $account->id)
                ->where('device_uid', $deviceUid)->where('platform', 'agent')->where('status', Device::STATUS_ACTIVE)->first());
            if ($agent !== null && VerifyAgentSignature::check($signedRequest, $agent) === null) {
                return;
            }
        }
        $refused = app(SignInArea::class)->check($account, $latitude, $longitude, $accuracy);
        if ($refused !== null) {
            $this->audit->record('auth.login.blocked', $account, meta: ['reason' => $refused, 'ip' => $ip,
                'latitude' => is_numeric($latitude) ? round((float) $latitude, 5) : null, 'longitude' => is_numeric($longitude) ? round((float) $longitude, 5) : null]);

            throw new AuthenticationFailed(AuthenticationFailed::OUTSIDE_AREA, $refused, 403);
        }
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
            $this->registerFailure($account, 'bad_mfa_code', request()->ip());

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
        string $method = 'password',
    ): AuthSession {
        $device = $this->devices->resolveForLogin($account, $deviceInfo, $ip);
        $session = $this->sessions->start($account, $channel, $device, $ip, $userAgent, $mfaPassed, $method);
        app(SignInAlerts::class)->afterSignIn($account, $session, $device);

        $account->forceFill([
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->save();
        self::clearAddressLocks($account);

        $this->binder->bind($account, $session, $device->id);
        $this->audit->record('auth.login.succeeded', $account, meta: [
            'channel' => $channel, 'session_id' => $session->id, 'mfa' => $mfaPassed, 'method' => $method,
            'ip' => $ip, 'place' => $session->placeLabel(),
        ]);

        return $session;
    }

    /**
     * A wrong password or code. Counted twice:
     *  - for this account FROM THIS ADDRESS: a growing pause (2 s, 4 s, 8 s) after the 3rd, and after the
     *    5th the address is locked out for 15 minutes. Only the address: the real person signing in from
     *    the mill is not locked out by a stranger who knows the username.
     *  - for the whole account, from any address: after `account_lock_after` (30) failures since the last
     *    good sign-in (a spread-out attack) the account is locked for 15 minutes; after 3 such locks in
     *    24 hours only an administrator can unlock it.
     */
    private function registerFailure(LoginAccount $account, string $reason, ?string $ip = null): void
    {
        $ip ??= request()->ip();
        $max = (int) config('ricemill.auth.max_failed_attempts');
        $minutes = (int) config('ricemill.auth.lockout_minutes');

        // This address.
        $here = $this->addressRow($account, $ip);
        $attempts = (int) ($here->failures ?? 0) + 1;
        $lockHere = $attempts >= $max;
        $delayFrom = (int) config('ricemill.security.progressive_delay_after');
        $delay = ! $lockHere && $attempts >= $delayFrom
            ? min(2 ** ($attempts - $delayFrom + 1), (int) config('ricemill.security.max_progressive_delay_seconds')) : 0;
        DB::table('login_address_locks')->updateOrInsert(
            ['login_account_id' => $account->id, 'ip_hash' => self::ipHash($ip)],
            ['failures' => $lockHere ? 0 : $attempts, 'updated_at' => now(), 'locked_until' => match (true) {
                $lockHere => now()->addMinutes($minutes),
                $delay > 0 => now()->addSeconds($delay),
                default => $here?->locked_until,
            }],
        );

        // The whole account.
        $total = $account->failed_attempts + 1;
        $lockAll = $total >= (int) config('ricemill.security.account_lock_after', 30);
        $lockouts = $account->lockouts_24h;
        $needsAdmin = $account->needs_admin_unlock;
        if ($lockAll) {
            $recent = $account->last_lockout_at !== null && $account->last_lockout_at->gt(now()->subDay());
            $lockouts = $recent ? $lockouts + 1 : 1;
            $needsAdmin = $lockouts >= (int) config('ricemill.security.lockouts_before_admin_unlock');
        }
        $account->forceFill([
            'failed_attempts' => $lockAll ? 0 : $total,
            'locked_until' => $lockAll ? now()->addMinutes($minutes) : $account->locked_until,
            'lockouts_24h' => $lockouts,
            'last_lockout_at' => $lockAll ? now() : $account->last_lockout_at,
            'needs_admin_unlock' => $needsAdmin,
        ])->save();

        $this->audit->record('auth.login.failed', $account, meta: ['reason' => $reason, 'attempt' => $attempts, 'attempts_all_addresses' => $total,
            'delay_seconds' => $delay, 'ip' => $ip]);
        if ($lockHere && ! $lockAll) {
            $this->audit->record('auth.address.locked', $account, meta: ['minutes' => $minutes, 'ip' => $ip]);
            app(SecurityNotifier::class)->owners($account->entity_id,
                "Sign-ins blocked from one address: {$account->username}",
                "{$max} wrong attempts for {$account->username} from {$ip}: that address is locked out for {$minutes} minutes. The account still works from other places.",
                includeOwners: $account->isOwner());
        }
        if ($lockAll) {
            $this->audit->record('auth.account.locked', $account, meta: [
                'minutes' => $minutes, 'lockouts_24h' => $lockouts, 'needs_admin_unlock' => $needsAdmin, 'failures' => $total,
            ]);
            app(SecurityNotifier::class)->owners($account->entity_id,
                "Account locked after failed sign-ins: {$account->username}",
                $needsAdmin
                    ? "{$account->username} was locked {$lockouts} times within 24 hours. It now stays locked until an administrator unlocks it (Users → Unlock)."
                    : "{$account->username} is locked for {$minutes} minutes after {$total} wrong attempts from different places.",
                includeOwners: $account->isOwner());
        }
    }

    /** Until when this address is paused or locked out for this account (null: not at all). */
    public function addressLockedUntil(LoginAccount $account, ?string $ip): ?Carbon
    {
        $until = $this->addressRow($account, $ip)?->locked_until;

        return $until === null ? null : Carbon::parse($until);
    }

    /** Whether some address is locked out for this account right now (an administrator may lift it). */
    public static function hasAddressLocks(LoginAccount $account): bool
    {
        return DB::table('login_address_locks')->where('login_account_id', $account->id)->where('locked_until', '>', now())->exists();
    }

    /** Forgets every address count of the account: after a good sign-in, or an administrator's unlock. */
    public static function clearAddressLocks(LoginAccount $account): void
    {
        DB::table('login_address_locks')->where('login_account_id', $account->id)->delete();
    }

    private function addressRow(LoginAccount $account, ?string $ip): ?object
    {
        return DB::table('login_address_locks')->where('login_account_id', $account->id)->where('ip_hash', self::ipHash($ip))->first();
    }

    private static function ipHash(?string $ip): string
    {
        return hash('sha256', (string) config('app.key').'|'.($ip ?? 'unknown'));
    }

    /** @throws AuthenticationFailed */
    private function blocked(LoginAccount $account, string $reason): never
    {
        $this->audit->record('auth.login.blocked', $account, meta: ['reason' => $reason]);

        throw AuthenticationFailed::credentials($reason);
    }
}

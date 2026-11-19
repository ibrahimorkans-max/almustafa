<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use App\Models\MfaRecoveryCode;
use App\Models\Passkey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The last check of a sign-in check by e-mail (first sign-in, new network or device, password reset), after the 6-digit
 * code and the 2-digit number: the person proves it with something only they hold — nobody else is asked:
 *  - passkey: the fingerprint, face or PIN of their own phone or computer (cannot be phished);
 *  - authenticator: a fresh 6-digit code from their authenticator app (a code already used is refused);
 *  - recovery code: one of their one-time recovery codes (when the phone is lost);
 *  - mobile: a 6-digit code sent to the mobile number on the login (WhatsApp or SMS).
 * A login with none of them set up has only the e-mail codes (the screen recommends adding one).
 * The check is kept for 30 minutes for that sign-in (or that password-reset browser); 5 wrong tries per check.
 */
final class SecondFactor
{
    public const METHODS = ['passkey', 'totp', 'recovery', 'mobile'];

    private const TRIES = 5;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('ricemill.auth.second_factor', true);
    }

    /** @return list<string> the methods this login can use, strongest first */
    public function methods(LoginAccount $account): array
    {
        if ($account->is_machine) {
            return [];
        }
        $passkey = $this->tenant->runAsSystem(fn () => Passkey::query()->where('authenticatable_id', $account->id)->exists());
        $recovery = $account->hasMfa() && $this->tenant->runAsSystem(fn () => MfaRecoveryCode::query()->withoutGlobalScopes()
            ->where('login_account_id', $account->id)->whereNull('used_at')->exists());

        return array_values(array_filter([
            $passkey ? 'passkey' : null,
            $account->hasMfa() ? 'totp' : null,
            $recovery ? 'recovery' : null,
            app(MobileCode::class)->available($account) ? 'mobile' : null,
        ]));
    }

    /** Whether this check asks for it: switched on, and the login has at least one method. */
    public function required(LoginAccount $account): bool
    {
        return $this->enabled() && $this->methods($account) !== [];
    }

    /** Whether this check (an auth session id, or a password-reset browser token) is done — or not asked. */
    public function proven(LoginAccount $account, ?string $checkKey): bool
    {
        return ! $this->required($account) || ($checkKey !== null && Cache::has($this->key($account, $checkKey)));
    }

    /** @throws BusinessRuleViolation */
    public function proveCode(LoginAccount $account, string $checkKey, string $method, string $code): void
    {
        $this->allow($account, $checkKey, $method);
        $mfa = app(MfaService::class);
        $ok = $this->tenant->runAsSystem(fn () => match ($method) {
            'totp' => preg_match('/^\d{6}$/', preg_replace('/\D/', '', $code) ?? '') === 1 && $mfa->verifyCode($account, $code),
            'recovery' => trim($code) !== '' && $mfa->verifyRecoveryCode($account, $code),
            default => throw new BusinessRuleViolation('Choose how to prove it is you.'),
        });
        if (! $ok) {
            $this->wrong($account, $checkKey, $method);
        }
        $this->mark($account, $checkKey, $method);
    }

    /** @throws BusinessRuleViolation */
    public function sendMobile(LoginAccount $account, string $checkKey): void
    {
        $this->allow($account, $checkKey, 'mobile');
        app(MobileCode::class)->send($account, $checkKey);
    }

    /** @throws BusinessRuleViolation */
    public function proveMobile(LoginAccount $account, string $checkKey, string $code): void
    {
        $this->allow($account, $checkKey, 'mobile');
        app(MobileCode::class)->check($account, $checkKey, $code);   // throws on a wrong / old code, with its own tries
        $this->mark($account, $checkKey, 'mobile');
    }

    /**
     * The passkey's signed challenge (PasskeyService::authenticationOptions first): it must be one of THIS login's.
     *
     * @throws BusinessRuleViolation
     */
    public function provePasskey(LoginAccount $account, string $checkKey, string $credentialJson): void
    {
        $this->allow($account, $checkKey, 'passkey');
        $signer = app(PasskeyService::class)->authenticate(mb_substr($credentialJson, 0, 20_000));
        if ($signer === null || $signer->id !== $account->id) {
            $this->wrong($account, $checkKey, 'passkey');
        }
        $this->mark($account, $checkKey, 'passkey');
    }

    private function allow(LoginAccount $account, string $checkKey, string $method): void
    {
        if (! in_array($method, $this->methods($account), true)) {
            throw new BusinessRuleViolation('This way is not set up for your login. Choose another one.');
        }
        if (RateLimiter::tooManyAttempts($this->limitKey($account, $checkKey), self::TRIES)) {
            throw new BusinessRuleViolation('Too many wrong tries. Start the sign-in again later.');
        }
    }

    /** @return never */
    private function wrong(LoginAccount $account, string $checkKey, string $method): void
    {
        RateLimiter::hit($this->limitKey($account, $checkKey), 3600);
        $left = max(0, self::TRIES - RateLimiter::attempts($this->limitKey($account, $checkKey)));
        $this->tenant->runAsSystem(fn () => $this->audit->record('auth.second_factor.wrong', $account, meta: ['method' => $method, 'left' => $left]));

        throw new BusinessRuleViolation(match ($method) {
            'passkey' => 'That passkey is not one of this login\'s. Use your own device, or choose another way.',
            default => $left > 0 ? sprintf('Wrong code. %d tries left.', $left) : 'Too many wrong tries. Start the sign-in again later.',
        });
    }

    private function mark(LoginAccount $account, string $checkKey, string $method): void
    {
        Cache::put($this->key($account, $checkKey), $method, now()->addMinutes(30));
        RateLimiter::clear($this->limitKey($account, $checkKey));
        $this->tenant->runAsSystem(fn () => $this->audit->record('auth.second_factor.proven', $account, meta: ['method' => $method]));
    }

    private function key(LoginAccount $account, string $checkKey): string
    {
        return 'second-factor-ok:'.$account->id.':'.hash('sha256', $checkKey);
    }

    private function limitKey(LoginAccount $account, string $checkKey): string
    {
        return 'second-factor-try:'.$account->id.':'.hash('sha256', $checkKey);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;
use App\Models\Passkey;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;
use Throwable;

/**
 * Passkeys around the WebAuthn checks of spatie/laravel-passkeys:
 * * registration: signed in, the person asks for options, the browser creates a key on the device
 *   (fingerprint, face or PIN), the public half is checked and stored;
 * * sign-in: the browser signs the server's one-time challenge with a stored key; the key decides
 *   which account it is, then LoginService applies the usual account checks.
 * Challenges live in the server-side session and are used once. Both steps require user verification
 * (fingerprint, face or PIN), so a passkey stands for both factors.
 */
final class PasskeyService
{
    private const REGISTER_OPTIONS_KEY = 'passkey.register_options';

    private const AUTH_OPTIONS_KEY = 'passkey-authentication-options';

    /** One account keeps at most this many passkeys (phone, laptop, a spare security key …). */
    public const MAX_PER_ACCOUNT = 10;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function registrationOptions(LoginAccount $account): string
    {
        $options = app(PasskeyRegistrationOptions::class)->execute($account);
        session()->put(self::REGISTER_OPTIONS_KEY, $options);

        return $options;
    }

    public function register(LoginAccount $account, string $credentialJson, string $name): Passkey
    {
        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            throw new BusinessRuleViolation('Name the passkey after the device, e.g. "Office PC" or "My phone".');
        }
        $options = session()->pull(self::REGISTER_OPTIONS_KEY);
        if (! is_string($options)) {
            throw new BusinessRuleViolation('The passkey request expired. Start again.');
        }
        $count = $this->tenant->runAsSystem(fn () => Passkey::query()->where('authenticatable_id', $account->id)->count());
        if ($count >= self::MAX_PER_ACCOUNT) {
            throw new BusinessRuleViolation('This account already has '.self::MAX_PER_ACCOUNT.' passkeys; remove one first.');
        }
        try {
            /** @var Passkey $passkey */
            $passkey = $this->tenant->runAsSystem(fn () => app(StorePasskeyAction::class)->execute(
                $account, $credentialJson, $options, (string) config('passkeys.relying_party.id'),
                ['name' => $name, 'entity_id' => $account->entity_id],
            ));
        } catch (Throwable) {
            throw new BusinessRuleViolation('The device did not create a valid passkey. Try again, or use another device.');
        }
        $this->audit->record('auth.passkey.registered', $account, meta: ['passkey_id' => $passkey->id, 'name' => $name]);

        return $passkey;
    }

    /** Options for the browser to sign; any of the person's passkeys may answer. */
    public function authenticationOptions(): string
    {
        return app(PasskeyOptions::class)->execute();
    }

    /** The account whose passkey signed this session's challenge, or null. The challenge is spent either way. */
    public function authenticate(string $credentialJson): ?LoginAccount
    {
        $options = session()->pull(self::AUTH_OPTIONS_KEY);
        if (! is_string($options)) {
            return null;
        }

        return $this->tenant->runAsSystem(function () use ($credentialJson, $options): ?LoginAccount {
            try {
                $passkey = app(FindPasskeyToAuthenticateAction::class)->execute($credentialJson, $options);
            } catch (Throwable) {
                $passkey = null;
            }
            if (! $passkey instanceof Passkey) {
                $this->audit->record('auth.login.failed', meta: ['reason' => 'passkey_not_recognised']);

                return null;
            }

            return LoginAccount::query()->with(['role', 'person', 'entity'])->find($passkey->authenticatable_id);
        });
    }

    /** The person removes their own; with user.manage someone else's (for example a lost phone). */
    public function remove(Passkey $passkey, LoginAccount $by): void
    {
        if ($passkey->authenticatable_id !== $by->id && ! $by->hasPermission('user.manage')) {
            throw new BusinessRuleViolation('You can remove only your own passkeys.');
        }
        $owner = $passkey->authenticatable_id;
        $this->tenant->runAsSystem(fn () => $passkey->delete());
        $this->audit->record('auth.passkey.removed', null, meta: ['passkey_id' => $passkey->id, 'name' => $passkey->name, 'account_id' => $owner]);
    }
}

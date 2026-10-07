<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Access\AccessCatalogSync;
use App\Domain\Access\EntityProvisioner;
use App\Domain\Auth\ActorContext;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Auth\DeviceInfo;
use App\Domain\Auth\LoginService;
use App\Domain\Auth\TokenPair;
use App\Domain\Auth\TokenService;
use App\Domain\Tenancy\TenantContext;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Models\AuthSession;
use App\Models\Device;
use App\Models\Entity;
use App\Models\LoginAccount;
use App\Models\Person;
use App\Models\Role;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** Test data builders. Everything is created in system mode, like real provisioning. */
final class Make
{
    public const PASSWORD = 'Correct-Horse-42!';

    public static function entity(string $code = 'E1'): Entity
    {
        return app(EntityProvisioner::class)->create([
            'code' => $code, 'name' => "Mill {$code}", 'status' => Entity::STATUS_ACTIVE,
        ]);
    }

    /**
     * @param  array{mfa?: bool, person?: Person, username?: string, status?: string, must_change_password?: bool}  $options
     */
    public static function account(Entity $entity, string $roleCode, array $options = []): LoginAccount
    {
        return self::system(function () use ($entity, $roleCode, $options): LoginAccount {
            $role = $roleCode === 'SUPER_ADMIN'
                ? app(AccessCatalogSync::class)->superAdminRole()
                : Role::query()->where('entity_id', $entity->id)->where('code', $roleCode)->firstOrFail();

            $person = $options['person'] ?? null;
            if ($person === null) {
                $person = new Person(['full_name' => ucfirst(strtolower($roleCode)).' '.Str::random(4)]);
                $person->created_in_entity_id = $entity->id;
                $person->save();
            }

            $account = LoginAccount::query()->create([
                'entity_id' => $entity->id,
                'person_id' => $person->id,
                'role_id' => $role->id,
                'username' => $options['username'] ?? strtolower($roleCode).'.'.Str::lower(Str::random(6)),
                'password' => self::PASSWORD,
                'status' => $options['status'] ?? LoginAccount::STATUS_ACTIVE,
                'must_change_password' => $options['must_change_password'] ?? false,
            ]);

            if ($options['mfa'] ?? $role->mfa_required) {
                $account->forceFill([
                    'mfa_secret' => app(Google2FA::class)->generateSecretKey(32),
                    'mfa_confirmed_at' => now(),
                ])->save();
            }

            return $account->fresh(['role', 'person', 'entity']);
        });
    }

    public static function superAdmin(Entity $home, bool $trustedDeviceUid = true): LoginAccount
    {
        return self::account($home, 'SUPER_ADMIN');
    }

    public static function totp(LoginAccount $account): string
    {
        return app(Google2FA::class)->getCurrentOtp((string) $account->mfa_secret);
    }

    /** Pre-approves a device for a Super Admin (as if another Super Admin had approved it). */
    public static function trustedDevice(LoginAccount $account, string $uid, string $platform = 'web'): Device
    {
        return self::system(function () use ($account, $uid, $platform): Device {
            $device = Device::query()->create([
                'entity_id' => $account->entity_id, 'login_account_id' => $account->id,
                'device_uid' => $uid, 'platform' => $platform, 'status' => Device::STATUS_ACTIVE,
            ]);
            $device->forceFill(['trusted_at' => now()])->save();

            return $device;
        });
    }

    /** Full API login through the real services; returns the token pair. */
    public static function apiTokens(LoginAccount $account, string $deviceUid = 'test-device-0001-abcdef'): TokenPair
    {
        if ($account->isSuperAdmin()) {
            self::trustedDevice($account, $deviceUid, 'android');
        }

        app(AuthContextBinder::class)->bind($account);
        $session = app(LoginService::class)->completeLogin($account, AuthSession::CHANNEL_API, new DeviceInfo($deviceUid, 'android', 'Test phone'), '127.0.0.1', 'pest', true);
        $pair = app(TokenService::class)->issue($session, $session->device, $account);
        self::reset();

        return $pair;
    }

    /** @return array<string, string> */
    public static function apiHeaders(TokenPair $pair, string $deviceUid = 'test-device-0001-abcdef'): array
    {
        return ['Authorization' => 'Bearer '.$pair->accessToken, 'X-Device-Id' => $deviceUid, 'Accept' => 'application/json'];
    }

    /**
     * Creates a real web auth session for the account and returns the session data
     * to use with ->actingAs($account, 'web')->withSession(...).
     *
     * @return array<string, string>
     */
    public static function webSession(LoginAccount $account): array
    {
        $uid = (string) Str::uuid();
        if ($account->isSuperAdmin()) {
            self::trustedDevice($account, $uid);
        }

        app(AuthContextBinder::class)->bind($account);
        $session = app(LoginService::class)->completeLogin($account, AuthSession::CHANNEL_WEB, new DeviceInfo($uid, 'web', 'Test browser'), '127.0.0.1', 'pest', true);
        self::reset();

        return [EnsureWebAuthSession::SESSION_KEY => $session->id];
    }

    /** @template T @param callable(): T $callback @return T */
    public static function system(callable $callback): mixed
    {
        return app(TenantContext::class)->runAsSystem($callback);
    }

    /** Back to "no context", as at the start of a request. */
    public static function reset(): void
    {
        app(TenantContext::class)->clear();
        app(ActorContext::class)->clear();
    }
}

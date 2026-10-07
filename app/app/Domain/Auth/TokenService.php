<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\Exceptions\AuthenticationFailed;
use App\Domain\Tenancy\TenantContext;
use App\Models\ApiToken;
use App\Models\AuthSession;
use App\Models\Device;
use App\Models\LoginAccount;
use Illuminate\Support\Facades\DB;

/**
 * Opaque API tokens bound to an auth session and a device.
 *
 *  - access token: short-lived (15 min; 5 min for Super Admin)
 *  - refresh token: single use. Each refresh returns a new pair. Presenting an
 *    already-used refresh token means it was stolen or replayed, so the whole
 *    session is revoked (refresh token rotation with reuse detection).
 */
final class TokenService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SessionService $sessions,
        private readonly AuditLogger $audit,
        private readonly AuthContextBinder $binder,
    ) {}

    public function issue(AuthSession $session, Device $device, LoginAccount $account, ?string $parentId = null): TokenPair
    {
        $accessTtl = $account->isSuperAdmin()
            ? (int) config('ricemill.auth.super_admin_access_token_ttl')
            : (int) config('ricemill.auth.access_token_ttl');

        $accessExpires = now()->addMinutes($accessTtl);
        $refreshExpires = now()->addMinutes((int) config('ricemill.auth.refresh_token_ttl'));
        if ($refreshExpires->gt($session->expires_at)) {
            $refreshExpires = $session->expires_at->copy();
        }
        if ($accessExpires->gt($session->expires_at)) {
            $accessExpires = $session->expires_at->copy();
        }

        $access = 'rma_'.self::random();
        $refresh = 'rmr_'.self::random();

        foreach ([[ApiToken::TYPE_ACCESS, $access, $accessExpires], [ApiToken::TYPE_REFRESH, $refresh, $refreshExpires]] as [$type, $plain, $expires]) {
            ApiToken::query()->create([
                'entity_id' => $session->entity_id,
                'auth_session_id' => $session->id,
                'device_id' => $device->id,
                'type' => $type,
                'token_hash' => self::hash($plain),
                'parent_id' => $parentId,
                'expires_at' => $expires,
            ]);
        }

        return new TokenPair($access, $accessExpires, $refresh, $refreshExpires, $session->id);
    }

    /** @throws AuthenticationFailed */
    public function refresh(string $plainRefresh, string $deviceUid): TokenPair
    {
        $token = $this->findToken($plainRefresh, ApiToken::TYPE_REFRESH);
        if ($token === null) {
            throw AuthenticationFailed::credentials('refresh_unknown');
        }

        [$session, $device, $account] = $this->loadContext($token);
        $this->binder->bind($account, $session, $device->id);

        if ($token->used_at !== null || $token->revoked_at !== null) {
            if ($token->used_at !== null) {
                $this->audit->record('auth.refresh.reuse_detected', $session, meta: ['token_id' => $token->id]);
                $this->sessions->revoke($session, 'refresh_token_reuse');
            }

            throw new AuthenticationFailed(AuthenticationFailed::SESSION_INVALID, 'refresh_reused_or_revoked');
        }

        if ($device->device_uid !== $deviceUid) {
            $this->audit->record('auth.refresh.device_mismatch', $session, meta: ['token_id' => $token->id]);
            $this->sessions->revoke($session, 'refresh_device_mismatch');

            throw new AuthenticationFailed(AuthenticationFailed::SESSION_INVALID, 'device_mismatch');
        }

        if ($token->expires_at->isPast() || ! $session->isActive() || ! $device->isUsable() || ! $account->isActive()) {
            throw new AuthenticationFailed(AuthenticationFailed::SESSION_INVALID, 'refresh_expired_or_session_ended');
        }

        return DB::transaction(function () use ($token, $session, $device, $account): TokenPair {
            // Conditional update: only one concurrent refresh can win.
            $won = ApiToken::query()->whereKey($token->id)->whereNull('used_at')->update(['used_at' => now()]);
            if ($won === 0) {
                throw new AuthenticationFailed(AuthenticationFailed::SESSION_INVALID, 'refresh_race');
            }

            // Old access tokens of this session die with the rotation.
            ApiToken::query()->where('auth_session_id', $session->id)->where('type', ApiToken::TYPE_ACCESS)
                ->whereNull('revoked_at')->update(['revoked_at' => now()]);

            $this->sessions->touch($session);

            return $this->issue($session, $device, $account, $token->id);
        });
    }

    /**
     * Resolves a bearer access token. Returns null for anything not usable.
     *
     * @return array{0: LoginAccount, 1: AuthSession, 2: Device}|null
     */
    public function authenticate(string $plainAccess, ?string $deviceUid): ?array
    {
        $token = $this->findToken($plainAccess, ApiToken::TYPE_ACCESS);
        if ($token === null || $token->revoked_at !== null || $token->expires_at->isPast()) {
            return null;
        }

        [$session, $device, $account] = $this->loadContext($token);

        if ($deviceUid === null || ! hash_equals($device->device_uid, $deviceUid)) {
            return null;
        }

        if (! $session->isActive() || ! $device->isUsable() || ! $account->isActive()) {
            return null;
        }

        return [$account, $session, $device];
    }

    private function findToken(string $plain, string $type): ?ApiToken
    {
        return $this->tenant->runAsSystem(fn () => ApiToken::query()
            ->where('token_hash', self::hash($plain))
            ->where('type', $type)
            ->first());
    }

    /** @return array{0: AuthSession, 1: Device, 2: LoginAccount} */
    private function loadContext(ApiToken $token): array
    {
        return $this->tenant->runAsSystem(function () use ($token): array {
            $session = AuthSession::query()->findOrFail($token->auth_session_id);
            $device = Device::query()->findOrFail($token->device_id);
            $account = LoginAccount::query()->with(['role', 'person', 'entity'])->findOrFail($session->login_account_id);

            return [$session, $device, $account];
        });
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    private static function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}

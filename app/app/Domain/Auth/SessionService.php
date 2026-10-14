<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Security\GeoIp;
use App\Models\ApiToken;
use App\Models\AuthSession;
use App\Models\Device;
use App\Models\LoginAccount;
use App\Support\Values\UserAgentInfo;
use Illuminate\Support\Facades\DB;

final class SessionService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ActorContext $actor,
    ) {}

    /** @param  string  $method  how the person proved who they are: password | passkey */
    public function start(LoginAccount $account, string $channel, ?Device $device, ?string $ip, ?string $userAgent, bool $mfaPassed, string $method = 'password'): AuthSession
    {
        $ua = UserAgentInfo::parse($userAgent);
        $geo = app(GeoIp::class)->lookup($ip);

        return AuthSession::query()->create([
            'browser' => $ua->browser,
            'os' => $ua->os,
            'country' => $geo['country'] ?? null,
            'region' => $geo['region'] ?? null,
            'city' => $geo['city'] ?? null,
            'latitude' => $geo['latitude'] ?? null,
            'longitude' => $geo['longitude'] ?? null,
            'location_source' => $geo['source'] ?? null,
            'sign_in_method' => $method,
            'entity_id' => $account->entity_id,
            'login_account_id' => $account->id,
            'device_id' => $device?->id,
            'channel' => $channel,
            'ip' => $ip,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 512) : null,
            'mfa_passed_at' => $mfaPassed ? now() : null,
            'expires_at' => now()->addMinutes($account->role->session_max_minutes),
            'last_seen_at' => now(),
        ]);
    }

    /** Validity check used on every request. */
    public function isUsable(AuthSession $session, ?int $idleMinutes = null): bool
    {
        if (! $session->isActive()) {
            return false;
        }

        return $idleMinutes === null
            || $session->last_seen_at === null
            || $session->last_seen_at->gt(now()->subMinutes($idleMinutes));
    }

    /** Updates last_seen_at at most once a minute to avoid a write per request. */
    public function touch(AuthSession $session): void
    {
        if ($session->last_seen_at === null || $session->last_seen_at->lt(now()->subMinute())) {
            $session->forceFill(['last_seen_at' => now()])->save();
        }
    }

    public function markMfaPassed(AuthSession $session): void
    {
        $session->forceFill(['mfa_passed_at' => now()])->save();
    }

    public function revoke(AuthSession $session, string $reason): void
    {
        if ($session->revoked_at !== null) {
            return;
        }

        DB::transaction(function () use ($session, $reason): void {
            $session->forceFill([
                'revoked_at' => now(),
                'revoked_by' => $this->actor->accountId(),
                'revoked_reason' => mb_substr($reason, 0, 64),
            ])->save();

            ApiToken::query()->where('auth_session_id', $session->id)->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $this->audit->record('auth.session.revoked', $session, meta: ['reason' => $reason, 'login_account_id' => $session->login_account_id]);
        });
    }

    public function revokeForDevice(Device $device, string $reason): void
    {
        AuthSession::query()->where('device_id', $device->id)->whereNull('revoked_at')->get()
            ->each(fn (AuthSession $s) => $this->revoke($s, $reason));
    }

    public function revokeAllFor(LoginAccount $account, string $reason, ?string $exceptSessionId = null): int
    {
        $sessions = AuthSession::query()
            ->where('login_account_id', $account->id)
            ->whereNull('revoked_at')
            ->when($exceptSessionId, fn ($q) => $q->where('id', '!=', $exceptSessionId))
            ->get();

        $sessions->each(fn (AuthSession $s) => $this->revoke($s, $reason));

        return $sessions->count();
    }
}

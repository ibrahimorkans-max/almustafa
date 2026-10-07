<?php

declare(strict_types=1);

use App\Domain\Approvals\Actions\TrustDevice;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Auth\DeviceService;
use App\Domain\Auth\MfaService;
use App\Models\ApiToken;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\AuthSession;
use App\Models\Device;
use App\Models\IpAllowlistEntry;
use App\Models\LoginAccount;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Support\Make;

const DEVICE = 'phone-0001-abcdefghij';

beforeEach(function () {
    $this->entity = Make::entity('API');
    Make::reset();
});

function loginBody(LoginAccount $account, array $extra = []): array
{
    return [
        'username' => $account->username, 'password' => Make::PASSWORD,
        'device_uid' => DEVICE, 'device_name' => 'Gate phone', 'platform' => 'android',
    ] + $extra;
}

function auditActions(): array
{
    return Make::system(fn () => AuditLog::query()->orderBy('occurred_at')->orderBy('seq')->pluck('action')->all());
}

describe('login', function () {
    it('issues device-bound tokens for a valid password', function () {
        $gate = Make::account($this->entity, 'GATE_OPERATOR');

        $response = $this->postJson('/api/v1/auth/login', loginBody($gate))->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('account.role.code', 'GATE_OPERATOR')
            ->assertJsonPath('account.entity.id', $this->entity->id);
        expect($response->json('access_token'))->toStartWith('rma_')
            ->and($response->json('refresh_token'))->toStartWith('rmr_')
            ->and($response->json('account.permissions'))->toContain('gate.entry')->not->toContain('ledger.post');

        // Only hashes are stored.
        $stored = Make::system(fn () => ApiToken::query()->pluck('token_hash')->all());
        expect($stored)->not->toContain($response->json('access_token'))
            ->and(auditActions())->toContain('auth.login.succeeded');

        $this->withHeaders(['Authorization' => 'Bearer '.$response->json('access_token'), 'X-Device-Id' => DEVICE])
            ->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.username', $gate->username);
    });

    it('gives the same answer for an unknown user and a wrong password', function () {
        $gate = Make::account($this->entity, 'GATE_OPERATOR');

        $unknown = $this->postJson('/api/v1/auth/login', ['username' => 'nobody.here'] + loginBody($gate))->assertStatus(401);
        $wrong = $this->postJson('/api/v1/auth/login', ['password' => 'wrong-password'] + loginBody($gate))->assertStatus(401);

        expect($unknown->json())->toBe($wrong->json())
            ->and($wrong->json('error'))->toBe('invalid_credentials');
    });

    it('locks the account after 5 failures, even for the right password', function () {
        $this->withoutMiddleware(ThrottleRequests::class);
        $gate = Make::account($this->entity, 'GATE_OPERATOR');

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/auth/login', ['password' => "wrong-{$i}"] + loginBody($gate))->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', loginBody($gate))->assertStatus(401);

        expect(Make::system(fn () => $gate->fresh()->isLocked()))->toBeTrue()
            ->and(auditActions())->toContain('auth.account.locked')
            ->and(array_count_values(auditActions())['auth.login.failed'])->toBe(5);

        $this->travel(16)->minutes();
        $this->postJson('/api/v1/auth/login', loginBody($gate))->assertOk();
    });

    it('rate-limits login attempts per username and IP', function () {
        $gate = Make::account($this->entity, 'GATE_OPERATOR');
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/auth/login', ['password' => 'x'] + loginBody($gate));
        }

        $this->postJson('/api/v1/auth/login', loginBody($gate))->assertStatus(429);
    });

    it('refuses disabled accounts and suspended entities', function () {
        $disabled = Make::account($this->entity, 'GATE_OPERATOR', ['status' => 'disabled']);
        $this->postJson('/api/v1/auth/login', loginBody($disabled))->assertStatus(401);

        $other = Make::entity('SUS');
        $gate = Make::account($other, 'GATE_OPERATOR');
        Make::system(fn () => $other->forceFill(['status' => 'suspended'])->save());
        $this->postJson('/api/v1/auth/login', loginBody($gate))->assertStatus(401);
    });
});

describe('two-factor authentication', function () {
    it('asks for a code, rejects a wrong one and accepts a valid one', function () {
        $accountant = Make::account($this->entity, 'ACCOUNTANT'); // 2FA mandatory + enrolled

        $this->postJson('/api/v1/auth/login', loginBody($accountant))->assertOk()->assertJsonPath('status', 'mfa_required');
        $this->postJson('/api/v1/auth/login', loginBody($accountant, ['otp' => '000000']))->assertStatus(401)
            ->assertJsonPath('error', 'invalid_mfa_code');
        $this->postJson('/api/v1/auth/login', loginBody($accountant, ['otp' => Make::totp($accountant)]))->assertOk()
            ->assertJsonPath('status', 'ok');

        expect(Make::system(fn () => AuthSession::query()->first()->mfa_passed_at))->not->toBeNull();
    });

    it('does not accept the same code twice (replay)', function () {
        $accountant = Make::account($this->entity, 'ACCOUNTANT');
        $code = Make::totp($accountant);

        $this->postJson('/api/v1/auth/login', loginBody($accountant, ['otp' => $code]))->assertOk();
        $this->postJson('/api/v1/auth/login', loginBody($accountant, ['otp' => $code]))->assertStatus(401);
    });

    it('accepts a recovery code exactly once', function () {
        $accountant = Make::account($this->entity, 'ACCOUNTANT');
        $codes = Make::system(fn () => app(MfaService::class)->regenerateRecoveryCodes($accountant));

        $this->postJson('/api/v1/auth/login', loginBody($accountant, ['otp' => $codes[0]]))->assertOk();
        $this->postJson('/api/v1/auth/login', loginBody($accountant, ['otp' => $codes[0]]))->assertStatus(401);
    });

    it('blocks app login for a role that requires 2FA until it is enrolled', function () {
        $owner = Make::account($this->entity, 'OWNER', ['mfa' => false]);

        $this->postJson('/api/v1/auth/login', loginBody($owner))->assertStatus(403)
            ->assertJsonPath('error', 'mfa_enrollment_required');
    });

    it('counts wrong codes towards the lockout', function () {
        $this->withoutMiddleware(ThrottleRequests::class);
        $accountant = Make::account($this->entity, 'ACCOUNTANT');
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/auth/login', loginBody($accountant, ['otp' => '00000'.$i]));
        }

        expect(Make::system(fn () => $accountant->fresh()->isLocked()))->toBeTrue();
    });
});

describe('tokens and sessions', function () {
    beforeEach(function () {
        $this->gate = Make::account($this->entity, 'GATE_OPERATOR');
        $this->pair = $this->postJson('/api/v1/auth/login', loginBody($this->gate))->json();
        $this->headers = fn (?string $access = null) => [
            'Authorization' => 'Bearer '.($access ?? $this->pair['access_token']), 'X-Device-Id' => DEVICE,
        ];
    });

    it('rotates the refresh token and retires the old access token', function () {
        $next = $this->withHeaders(['X-Device-Id' => DEVICE])
            ->postJson('/api/v1/auth/refresh', ['refresh_token' => $this->pair['refresh_token']])->assertOk()->json();

        expect($next['refresh_token'])->not->toBe($this->pair['refresh_token']);
        $this->withHeaders(($this->headers)($next['access_token']))->getJson('/api/v1/auth/me')->assertOk();
        $this->withHeaders(($this->headers)())->getJson('/api/v1/auth/me')->assertStatus(401);
    });

    it('revokes the whole session when a used refresh token is replayed', function () {
        $next = $this->withHeaders(['X-Device-Id' => DEVICE])
            ->postJson('/api/v1/auth/refresh', ['refresh_token' => $this->pair['refresh_token']])->json();

        // Attacker replays the stolen original refresh token.
        $this->withHeaders(['X-Device-Id' => DEVICE])
            ->postJson('/api/v1/auth/refresh', ['refresh_token' => $this->pair['refresh_token']])->assertStatus(401);

        // The legitimate client's new tokens are dead too.
        $this->withHeaders(($this->headers)($next['access_token']))->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->withHeaders(['X-Device-Id' => DEVICE])
            ->postJson('/api/v1/auth/refresh', ['refresh_token' => $next['refresh_token']])->assertStatus(401);
        expect(auditActions())->toContain('auth.refresh.reuse_detected');
    });

    it('binds tokens to the device', function () {
        $this->withHeaders(['Authorization' => 'Bearer '.$this->pair['access_token'], 'X-Device-Id' => 'another-device-12345'])
            ->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Bearer '.$this->pair['access_token']])
            ->getJson('/api/v1/auth/me')->assertStatus(401);

        // A refresh from another device kills the session.
        $this->withHeaders(['X-Device-Id' => 'another-device-12345'])
            ->postJson('/api/v1/auth/refresh', ['refresh_token' => $this->pair['refresh_token']])->assertStatus(401);
        $this->withHeaders(($this->headers)())->getJson('/api/v1/auth/me')->assertStatus(401);
    });

    it('expires access tokens after 15 minutes', function () {
        $this->travel(16)->minutes();
        $this->withHeaders(($this->headers)())->getJson('/api/v1/auth/me')->assertStatus(401);
    });

    it('lists sessions and revokes them', function () {
        $second = $this->postJson('/api/v1/auth/login', ['device_uid' => 'tablet-0002-abcdefgh'] + loginBody($this->gate))->json();

        $list = $this->withHeaders(($this->headers)())->getJson('/api/v1/sessions')->assertOk()->json('data');
        expect($list)->toHaveCount(2);

        $this->withHeaders(($this->headers)())->deleteJson('/api/v1/sessions/'.$second['session_id'])->assertOk();
        $this->withHeaders(['Authorization' => 'Bearer '.$second['access_token'], 'X-Device-Id' => 'tablet-0002-abcdefgh'])
            ->getJson('/api/v1/auth/me')->assertStatus(401);
    });

    it('cannot revoke another user\'s session without session.revoke_any', function () {
        $other = Make::account($this->entity, 'LAB_TECHNICIAN');
        $theirs = $this->postJson('/api/v1/auth/login', ['device_uid' => 'lab-0003-abcdefghij'] + loginBody($other))->json();

        $this->withHeaders(($this->headers)())->deleteJson('/api/v1/sessions/'.$theirs['session_id'])->assertForbidden();
    });

    it('logs out', function () {
        $this->withHeaders(($this->headers)())->postJson('/api/v1/auth/logout')->assertOk();
        $this->withHeaders(($this->headers)())->getJson('/api/v1/auth/me')->assertStatus(401);
    });

    it('stops working immediately when the device is revoked or the account disabled', function () {
        Make::system(fn () => app(DeviceService::class)->revoke(Device::query()->firstOrFail(), 'lost'));
        $this->withHeaders(($this->headers)())->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->postJson('/api/v1/auth/login', loginBody($this->gate))->assertStatus(403)->assertJsonPath('error', 'device_not_allowed');
    });

    it('rejects a working-entity header for an entity that is not the user\'s', function () {
        $other = Make::entity('OTH');
        $this->withHeaders(($this->headers)() + ['X-Entity-Id' => $other->id])->getJson('/api/v1/auth/me')->assertStatus(401);
    });
});

describe('Super Admin controls', function () {
    beforeEach(function () {
        $this->sa = Make::superAdmin($this->entity);
    });

    it('requires a device approved by another Super Admin', function () {
        $body = loginBody($this->sa, ['otp' => Make::totp($this->sa)]);

        $this->postJson('/api/v1/auth/login', $body)->assertStatus(403)->assertJsonPath('error', 'device_pending_approval');

        $request = Make::system(fn () => ApprovalRequest::query()->where('action', TrustDevice::KEY)->sole());
        expect($request->maker_login_account_id)->toBe($this->sa->id);

        // The same Super Admin cannot approve their own device.
        expect(Make::system(fn () => app(ApprovalService::class)->canDecide($this->sa, $request)))->toBeFalse();

        // A second Super Admin approves; login then works.
        $sa2 = Make::superAdmin($this->entity);
        Make::system(function () use ($request, $sa2) {
            app(AuthContextBinder::class)->bind($sa2);
            app(ApprovalService::class)->approve($request, $sa2, 'Known laptop');
        });
        Make::reset();

        // The TOTP code above was already consumed (replay protection), so use a recovery code.
        $codes = Make::system(fn () => app(MfaService::class)->regenerateRecoveryCodes($this->sa));
        $this->postJson('/api/v1/auth/login', loginBody($this->sa, ['otp' => $codes[0]]))->assertOk();
    });

    it('enforces the IP allowlist', function () {
        Make::trustedDevice($this->sa, DEVICE, 'android');
        Make::system(fn () => IpAllowlistEntry::query()->create([
            'entity_id' => $this->sa->entity_id, 'login_account_id' => $this->sa->id, 'cidr' => '203.0.113.0/24',
        ]));

        $this->postJson('/api/v1/auth/login', loginBody($this->sa, ['otp' => Make::totp($this->sa)]))
            ->assertStatus(403)->assertJsonPath('error', 'network_not_allowed');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson('/api/v1/auth/login', loginBody($this->sa, ['otp' => Make::totp($this->sa)]))->assertOk();
    });

    it('requires an allowlist entry when the platform demands one', function () {
        config(['ricemill.security.super_admin_require_ip_allowlist' => true]);
        Make::trustedDevice($this->sa, DEVICE, 'android');

        $this->postJson('/api/v1/auth/login', loginBody($this->sa, ['otp' => Make::totp($this->sa)]))->assertStatus(403);
    });

    it('gets 5-minute access tokens and a 60-minute session', function () {
        Make::trustedDevice($this->sa, DEVICE, 'android');
        $pair = $this->postJson('/api/v1/auth/login', loginBody($this->sa, ['otp' => Make::totp($this->sa)]))->assertOk()->json();

        expect(now()->diffInMinutes($pair['access_token_expires_at']))->toBeLessThanOrEqual(5.0)
            ->and(now()->diffInMinutes($pair['refresh_token_expires_at']))->toBeLessThanOrEqual(60.0);
    });

    it('can pick a working entity with X-Entity-Id', function () {
        $other = Make::entity('OTH');
        Make::trustedDevice($this->sa, DEVICE, 'android');
        $pair = $this->postJson('/api/v1/auth/login', loginBody($this->sa, ['otp' => Make::totp($this->sa)]))->json();

        $this->withHeaders(['Authorization' => 'Bearer '.$pair['access_token'], 'X-Device-Id' => DEVICE, 'X-Entity-Id' => $other->id])
            ->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.working_entity_id', $other->id);
    });
});

<?php

declare(strict_types=1);

use App\Domain\Settings\SettingsService;
use App\Models\AuditLog;
use Tests\Support\Make;

beforeEach(function () {
    $this->millA = Make::entity('SWA');
    $this->millB = Make::entity('SWB');
    $this->millC = Make::entity('SWC');
    // One person, one account per entity (enforced by the schema).
    $this->supA = Make::account($this->millA, 'SUPERVISOR');
    $this->supB = Make::account($this->millB, 'SUPERVISOR', ['person' => $this->supA->person]);
    $this->accB = Make::account($this->millC, 'ACCOUNTANT', ['person' => $this->supA->person]);
    $this->stranger = Make::account($this->millB, 'SUPERVISOR');
});

function enableSwitcher(): void
{
    Make::system(fn () => app(SettingsService::class)->put(SettingsService::LOGIN_MODE, SettingsService::LOGIN_MODE_SWITCHER));
}

it('is off in the default multi-account mode', function () {
    $pair = Make::apiTokens($this->supA);

    $this->withHeaders(Make::apiHeaders($pair))
        ->postJson('/api/v1/auth/switch', ['login_account_id' => $this->supB->id])
        ->assertForbidden()->assertJsonPath('error', 'session_invalid');
});

it('switches to another account of the same person without a password', function () {
    enableSwitcher();
    $pair = Make::apiTokens($this->supA);

    $switched = $this->withHeaders(Make::apiHeaders($pair))
        ->postJson('/api/v1/auth/switch', ['login_account_id' => $this->supB->id])->assertOk()->json();

    expect($switched['account']['entity']['id'])->toBe($this->millB->id);
    // The old session is closed.
    $this->withHeaders(Make::apiHeaders($pair))->getJson('/api/v1/auth/me')->assertStatus(401);
    $this->withHeaders(['Authorization' => 'Bearer '.$switched['access_token'], 'X-Device-Id' => 'test-device-0001-abcdef'])
        ->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $this->supB->id);

    expect(Make::system(fn () => AuditLog::query()->whereIn('action', ['auth.account.switched_out', 'auth.account.switched_in'])->count()))->toBe(2);
});

it('still requires the target account\'s 2FA code', function () {
    enableSwitcher();
    $pair = Make::apiTokens($this->supA);

    $this->withHeaders(Make::apiHeaders($pair))
        ->postJson('/api/v1/auth/switch', ['login_account_id' => $this->accB->id])->assertStatus(401)
        ->assertJsonPath('error', 'invalid_mfa_code');

    $this->withHeaders(Make::apiHeaders($pair))
        ->postJson('/api/v1/auth/switch', ['login_account_id' => $this->accB->id, 'otp' => Make::totp($this->accB)])->assertOk();
});

it('never switches into someone else\'s account', function () {
    enableSwitcher();
    $pair = Make::apiTokens($this->supA);

    $this->withHeaders(Make::apiHeaders($pair))
        ->postJson('/api/v1/auth/switch', ['login_account_id' => $this->stranger->id])->assertStatus(403);
});

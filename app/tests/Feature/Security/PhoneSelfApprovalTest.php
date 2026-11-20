<?php

declare(strict_types=1);

use App\Domain\Approvals\Actions\TrustDevice;
use App\Domain\Settings\SettingsService;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\LoginAccount;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\Make;

beforeEach(function () {
    $this->entity = Make::entity('PHN');
    Make::system(fn () => app(SettingsService::class)->put(SettingsService::TRUSTED_DEVICES, 'everyone', $this->entity->id));
    Make::reset();
    $this->phone = (string) Str::uuid();
});

function phoneLogin(object $t, LoginAccount $account, string $uid, array $extra = []): TestResponse
{
    return $t->postJson('/api/v1/auth/login', [
        'username' => $account->username, 'password' => Make::PASSWORD,
        'device_uid' => $uid, 'device_name' => 'Gate phone', 'platform' => 'pwa',
    ] + $extra);
}

function phoneTrusted(string $uid): bool
{
    return Make::system(fn () => (bool) Device::query()->where('device_uid', $uid)->first()?->isTrusted());
}

it('lets a person approve their own new phone with their authenticator code, with nobody else involved', function () {
    $clerk = Make::account($this->entity, 'GATE_OPERATOR', ['mfa' => true]);

    // The app is asked for the authenticator code (it shows its code box for "mfa_required").
    phoneLogin($this, $clerk, $this->phone)->assertOk()->assertJson(['status' => 'mfa_required']);
    expect(phoneTrusted($this->phone))->toBeFalse();

    // A wrong code: refused, still not approved.
    phoneLogin($this, $clerk, $this->phone, ['otp' => '000000'])->assertStatus(401);
    expect(phoneTrusted($this->phone))->toBeFalse();
    Make::reset();

    // The right code: signed in, the phone approved, no approval request for anyone.
    phoneLogin($this, $clerk, $this->phone, ['otp' => Make::totp($clerk)])->assertOk()->assertJson(['status' => 'ok'])->assertJsonStructure(['access_token']);
    expect(phoneTrusted($this->phone))->toBeTrue()
        ->and(Make::system(fn () => ApprovalRequest::query()->where('action', TrustDevice::KEY)->count()))->toBe(0)
        ->and(Make::system(fn () => AuditLog::query()->where('action', 'device.trusted')->latest('occurred_at')->value('meta')))->toMatchArray(['how' => 'proved_by_authenticator']);
});

it('still waits for an approval when the person has no authenticator, and withdraws it once they prove the phone', function () {
    $clerk = Make::account($this->entity, 'GATE_OPERATOR');

    phoneLogin($this, $clerk, $this->phone)->assertStatus(403)->assertJson(['error' => 'device_pending_approval']);
    expect(Make::system(fn () => ApprovalRequest::query()->where('action', TrustDevice::KEY)->sole()->status))->toBe(ApprovalRequest::STATUS_PENDING);
    Make::reset();

    // They set up an authenticator (on a computer already approved), then prove the phone themselves.
    Make::system(fn () => $clerk->forceFill(['mfa_secret' => app(Google2FA::class)->generateSecretKey(32), 'mfa_confirmed_at' => now()])->save());
    $clerk = Make::system(fn () => $clerk->fresh());
    phoneLogin($this, $clerk, $this->phone, ['otp' => Make::totp($clerk)])->assertOk()->assertJson(['status' => 'ok']);

    expect(phoneTrusted($this->phone))->toBeTrue()
        ->and(Make::system(fn () => ApprovalRequest::query()->where('action', TrustDevice::KEY)->sole()->status))->toBe(ApprovalRequest::STATUS_CANCELLED);
});

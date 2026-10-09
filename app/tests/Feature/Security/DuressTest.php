<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Auth\Exceptions\AuthenticationFailed;
use App\Domain\Auth\LoginService;
use App\Domain\Security\DuressService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\DuressPin;
use App\Models\AuditLog;
use App\Models\AuthSession;
use App\Models\Device;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Make;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    config(['ricemill.security.contact_emails' => 'security@mill.example']);
    $this->entity = Make::entity('DUR');
    $this->owner = Make::account($this->entity, 'OWNER');
    $this->accountant = Make::account($this->entity, 'ACCOUNTANT');
    $this->sa = Make::superAdmin($this->entity);
});

function setPin(LoginAccount $a, string $pin = '480917', bool $suspend = true, bool $wipe = false): void
{
    app(AuthContextBinder::class)->bind($a);
    app(DuressService::class)->setPin($a, Make::PASSWORD, $pin, $suspend, $wipe);
    Make::reset();
}

function duressSignIn(LoginAccount $a, string $secret, string $device = 'phone-0001-abcdef'): ?AuthenticationFailed
{
    try {
        app(LoginService::class)->verifyPassword($a->username, $secret, '198.51.100.23', $device);
    } catch (AuthenticationFailed $e) {
        return $e;
    }

    return null;
}

it('is for Owner, Accountant and Super Admin only, and the PIN must be a real secret', function () {
    $svc = app(DuressService::class);
    $gate = Make::account($this->entity, 'GATE_OPERATOR');
    expect(fn () => $svc->setPin($gate, Make::PASSWORD, '480917', true, false))->toThrow(BusinessRuleViolation::class, 'Owner, Accountant and Super Admin')
        ->and(fn () => $svc->setPin($this->owner, 'wrong', '480917', true, false))->toThrow(BusinessRuleViolation::class, 'current password')
        ->and(fn () => $svc->setPin($this->owner, Make::PASSWORD, '1234', true, false))->toThrow(BusinessRuleViolation::class, '6 to 8 digits')
        ->and(fn () => $svc->setPin($this->owner, Make::PASSWORD, '123456', true, false))->toThrow(BusinessRuleViolation::class, 'sequence')
        ->and(fn () => $svc->setPin($this->owner, Make::PASSWORD, '777777', true, false))->toThrow(BusinessRuleViolation::class, 'sequence');

    setPin($this->owner);
    $stored = Make::system(fn () => DB::table('login_accounts')->where('id', $this->owner->id)->value('duress_pin_hash'));
    expect($stored)->not->toBe('480917')->and(password_get_info($stored)['algoName'])->not->toBe('unknown');
    expect(Make::system(fn () => AuditLog::query()->where('action', 'security.duress_pin.set')->first()->new_values))->toBeNull();
});

it('signs out with a neutral message, alerts owners, Super Admins and contacts, flags and suspends', function () {
    $other = Make::webSession($this->accountant);
    setPin($this->accountant);

    $e = duressSignIn($this->accountant, '480917');
    expect($e?->publicCode)->toBe('signed_out')
        ->and($e?->publicMessage())->toBe('Signed out. Please try again later.')
        ->and($e?->publicMessage())->not->toContain('duress');

    $acc = Make::system(fn () => $this->accountant->fresh());
    expect($acc->duress_flagged_at)->not->toBeNull()
        ->and($acc->status)->toBe('suspended')
        ->and(Make::system(fn () => AuthSession::query()->where('login_account_id', $acc->id)->whereNull('revoked_at')->count()))->toBe(0);

    $alerts = DB::table('notifications')->whereIn('notifiable_id', [$this->owner->id, $this->sa->id])->pluck('data')->all();
    expect($alerts)->toHaveCount(2)->and($alerts[0])->toContain('198.51.100.23')->and($alerts[0])->toContain('Duress sign-in');
    $mails = app('mailer')->getSymfonyTransport()->messages();
    expect($mails->map(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress())->all())->toContain('security@mill.example');

    $log = Make::system(fn () => AuditLog::query()->where('action', 'security.duress.triggered')->sole());
    expect($log->meta)->toMatchArray(['ip' => '198.51.100.23', 'device_uid' => 'phone-0001-abcdef', 'suspended' => true]);
    // The ordinary password no longer works until an administrator reinstates the account.
    expect(duressSignIn($this->accountant, Make::PASSWORD)?->publicCode)->toBe('access_disabled');
});

it('only flags when the user chose not to suspend, and queues a wipe of the device in use when asked', function () {
    setPin($this->owner, '480917', suspend: false, wipe: true);
    $pair = Make::apiTokens($this->owner, 'owner-phone-0001-xyz');

    duressSignIn($this->owner, '480917', 'owner-phone-0001-xyz');

    $owner = Make::system(fn () => $this->owner->fresh());
    expect($owner->status)->toBe('active')->and($owner->duress_flagged_at)->not->toBeNull();
    $device = Make::system(fn () => Device::query()->where('device_uid', 'owner-phone-0001-xyz')->sole());
    expect($device->status)->toBe(Device::STATUS_WIPE_PENDING);
    $this->getJson('/api/v1/auth/me', Make::apiHeaders($pair, 'owner-phone-0001-xyz'))->assertUnauthorized();
});

it('does not trigger on a wrong password, and the API answers neutrally too', function () {
    setPin($this->owner);
    expect(duressSignIn($this->owner, 'Not-the-pin-1!')?->publicCode)->toBe('invalid_credentials')
        ->and(Make::system(fn () => $this->owner->fresh()->duress_flagged_at))->toBeNull();

    $this->postJson('/api/v1/auth/login', ['username' => $this->owner->username, 'password' => '480917',
        'device_uid' => 'device-api-000001', 'platform' => 'android'])
        ->assertStatus(401)->assertExactJson(['error' => 'signed_out', 'message' => 'Signed out. Please try again later.']);
});

it('sets the PIN on its page, and the administrator clears the alert after re-verifying the person', function () {
    $this->actingAs($this->owner, 'web')->withSession(Make::webSession($this->owner));
    app(AuthContextBinder::class)->bind($this->owner);
    Livewire::test(DuressPin::class)
        ->fillForm(['current_password' => Make::PASSWORD, 'pin' => '480917', 'pin_confirmation' => '480917', 'suspend' => false, 'wipe_device' => false])
        ->call('save')->assertNotified('Duress PIN saved.');
    expect(Make::system(fn () => $this->owner->fresh()->duress_pin_hash))->not->toBeNull();

    duressSignIn($this->owner, '480917');
    app(AuthContextBinder::class)->bind($this->sa);
    expect(fn () => app(DuressService::class)->clearFlag($this->owner, 'ok'))->toThrow(BusinessRuleViolation::class, 're-verified');
    app(DuressService::class)->clearFlag(Make::system(fn () => $this->owner->fresh()), 'Called on the registered mobile; confirmed by the security question.');
    expect(Make::system(fn () => $this->owner->fresh()->duress_flagged_at))->toBeNull();
});

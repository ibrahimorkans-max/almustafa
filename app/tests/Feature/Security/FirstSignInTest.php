<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Auth\DeviceInfo;
use App\Domain\Auth\FirstSignIn;
use App\Domain\Auth\IpAllowlistPolicy;
use App\Domain\Auth\LoginService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Mail\FirstSignInVerificationMail;
use App\Models\AuthSession;
use App\Models\Device;
use App\Models\IpAllowlistEntry;
use App\Models\LoginAccount;
use App\Models\Person;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\Make;

beforeEach(function () {
    config(['ricemill.auth.first_sign_in_check' => true]);
    Mail::fake();
});

function fsiAccount(LoginAccount $account): LoginAccount
{
    return Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->with(['role', 'person'])->findOrFail($account->id));
}

it('sends a new user to the first sign-in before any screen, with where the sign-in came from', function () {
    $entity = Make::entity('FSI');
    $user = Make::account($entity, 'SUPER_ADMIN', ['mfa' => true]);
    $session = Make::webSession($user);
    $auth = Make::system(fn () => AuthSession::query()->findOrFail($session['auth_session_id']));
    app(FirstSignIn::class)->capture(fsiAccount($user), $auth, 'uid-1', 'Chrome on Windows', 29.81, 71.63, 12);

    $user = fsiAccount($user);
    expect($user->needsFirstSignIn())->toBeTrue()
        ->and($user->hasPermission('settings.manage'))->toBeFalse()
        ->and($user->first_sign_in)->toMatchArray(['ip' => '127.0.0.1', 'device' => 'Chrome on Windows', 'latitude' => 29.81, 'longitude' => 71.63,
            'accuracy_m' => 12, 'location_source' => 'browser']);

    $this->actingAs($user, 'web')->withSession($session)->get('/admin')->assertRedirect('/admin/first-sign-in');
    $this->actingAs($user, 'web')->withSession($session)->get('/admin/first-sign-in')->assertOk()
        ->assertSee('127.0.0.1')->assertSee('Send verification e-mail');
});

it('confirms the e-mail with the one-time button in the e-mail, then the user gives their details and is in', function () {
    $entity = Make::entity('FSV');
    $user = Make::account($entity, 'SUPER_ADMIN', ['mfa' => true]);
    $session = Make::webSession($user);

    app(FirstSignIn::class)->sendVerification(fsiAccount($user), 'Ali.Raza@Example.com');
    $url = null;
    Mail::assertSent(FirstSignInVerificationMail::class, function (FirstSignInVerificationMail $mail) use (&$url) {
        $url = $mail->url;

        return $mail->hasTo('ali.raza@example.com');
    });
    expect(fn () => app(FirstSignIn::class)->complete(fsiAccount($user), ['full_name' => 'Ali Raza', 'phone' => '0300-1234567']))
        ->toThrow(BusinessRuleViolation::class, 'Confirm your e-mail');

    // Opened on another device (not signed in): it says so; the link works once.
    $this->get($url)->assertOk()->assertSee('E-mail confirmed');
    expect(fsiAccount($user)->email_verified_at)->not->toBeNull();
    $this->get($url)->assertStatus(410);
    // A changed link is refused by its signature.
    $this->get($url.'x')->assertForbidden();

    app(FirstSignIn::class)->complete(fsiAccount($user), ['full_name' => 'Ali Raza', 'phone' => '0300-1234567']);
    $user = fsiAccount($user);
    expect($user->needsFirstSignIn())->toBeFalse()
        ->and($user->email)->toBe('ali.raza@example.com')
        ->and(Make::system(fn () => Person::query()->withoutGlobalScopes()->find($user->person_id)))
        ->full_name->toBe('Ali Raza')->email->toBe('ali.raza@example.com');

    $this->actingAs($user, 'web')->withSession($session)->get('/admin')->assertOk();
});

it('refuses a bad address and a second e-mail within a minute', function () {
    $user = Make::account(Make::entity('FSR'), 'SUPER_ADMIN', ['mfa' => true]);

    expect(fn () => app(FirstSignIn::class)->sendVerification(fsiAccount($user), 'not-an-email'))->toThrow(BusinessRuleViolation::class);
    app(FirstSignIn::class)->sendVerification(fsiAccount($user), 'a@example.com');
    expect(fn () => app(FirstSignIn::class)->sendVerification(fsiAccount($user), 'a@example.com'))
        ->toThrow(BusinessRuleViolation::class, 'less than a minute ago');
});

it('leaves weighbridge PC logins, party logins and users who finished alone', function () {
    $entity = Make::entity('FSX');
    $machine = Make::account($entity, 'GATE_OPERATOR');
    Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($machine->id)->update(['is_machine' => true]));
    $done = Make::account($entity, 'SUPER_ADMIN', ['mfa' => true]);
    Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($done->id)->update(['first_sign_in_completed_at' => now()]));

    expect(fsiAccount($machine)->needsFirstSignIn())->toBeFalse()
        ->and(fsiAccount($done)->needsFirstSignIn())->toBeFalse();

    config(['ricemill.auth.first_sign_in_check' => false]);
    expect(fsiAccount(Make::account($entity, 'SUPER_ADMIN', ['mfa' => true]))->needsFirstSignIn())->toBeFalse();
});

it('lets a new Owner finish the first sign-in from an unlisted network and a new device, then keeps both as theirs', function () {
    config(['ricemill.security.super_admin_require_ip_allowlist' => true, 'ricemill.security.super_admin_require_trusted_device' => true]);
    $entity = Make::entity('FSO');
    $owner = Make::account($entity, 'OWNER', ['mfa' => true]);
    $policy = app(IpAllowlistPolicy::class);
    expect($policy->allows(fsiAccount($owner), '149.40.161.14'))->toBeTrue();

    // A device nobody approved yet: admitted for the first sign-in only.
    app(AuthContextBinder::class)->bind(fsiAccount($owner));
    $auth = app(LoginService::class)->completeLogin(fsiAccount($owner), AuthSession::CHANNEL_WEB,
        new DeviceInfo((string) Str::uuid(), 'web', 'Chrome on Windows'), '149.40.161.14', 'pest', true);
    Make::reset();

    app(FirstSignIn::class)->sendVerification(fsiAccount($owner), 'owner@example.com');
    Mail::assertSent(FirstSignInVerificationMail::class, function (FirstSignInVerificationMail $mail) {
        $this->get($mail->url)->assertOk();

        return true;
    });
    app(FirstSignIn::class)->complete(fsiAccount($owner), ['full_name' => 'Firdous Amr', 'phone' => '0300-1234567'], $auth);

    $owner = fsiAccount($owner);
    expect($owner->needsFirstSignIn())->toBeFalse()
        ->and(Make::system(fn () => Device::query()->withoutGlobalScopes()->find($auth->device_id))->isTrusted())->toBeTrue()
        ->and(Make::system(fn () => IpAllowlistEntry::query()->withoutGlobalScopes()->where('login_account_id', $owner->id)->pluck('cidr')->all()))
        ->toBe(['149.40.161.0/24'])
        ->and(Make::system(fn () => $policy->allows($owner, '149.40.161.200')))->toBeTrue()
        ->and(Make::system(fn () => $policy->allows($owner, '8.8.8.8')))->toBeFalse();
});

it('keeps a finished Owner without listed networks out when the allow-list is required', function () {
    config(['ricemill.security.super_admin_require_ip_allowlist' => true]);
    $owner = Make::account(Make::entity('FSL'), 'OWNER', ['mfa' => true]);
    Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($owner->id)->update(['first_sign_in_completed_at' => now()]));

    expect(app(IpAllowlistPolicy::class)->allows(fsiAccount($owner), '149.40.161.14'))->toBeFalse()
        ->and(FirstSignIn::network('2001:db8:1:2:3:4:5:6'))->toBe('2001:db8:1:2::/64');
});

it('lets the server admin see why an Owner is refused and allow the network (auth:networks)', function () {
    config(['ricemill.security.super_admin_require_ip_allowlist' => true]);
    $owner = Make::account(Make::entity('FSN'), 'OWNER', ['mfa' => true]);
    Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($owner->id)->update(['first_sign_in_completed_at' => now()]));

    $this->artisan('auth:networks', ['username' => $owner->username, '--test' => '149.40.161.14'])
        ->expectsOutputToContain('refused from everywhere')->expectsOutputToContain('149.40.161.14: refused.')->assertSuccessful();
    $this->artisan('auth:networks', ['username' => $owner->username, '--allow' => '149.40.161.14', '--test' => '149.40.161.99'])
        ->expectsOutputToContain('149.40.161.0/24 allowed')->expectsOutputToContain('149.40.161.99: allowed.')->assertSuccessful();
    $this->artisan('auth:networks', ['username' => $owner->username, '--allow' => 'nonsense'])->assertFailed();

    $this->artisan('auth:networks', ['username' => $owner->username, '--first-sign-in' => true])
        ->expectsOutputToContain('first sign-in again')->assertSuccessful();
    expect(fsiAccount($owner)->needsFirstSignIn())->toBeTrue();
});

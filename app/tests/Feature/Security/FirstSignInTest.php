<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Auth\DeviceInfo;
use App\Domain\Auth\FirstSignIn;
use App\Domain\Auth\IpAllowlistPolicy;
use App\Domain\Auth\LoginService;
use App\Domain\Auth\SignInApprovals;
use App\Domain\Shared\BusinessRuleViolation;
use App\Mail\FirstSignInVerificationMail;
use App\Models\AuthSession;
use App\Models\Device;
use App\Models\IpAllowlistEntry;
use App\Models\LoginAccount;
use App\Models\Person;
use App\Models\SignInApproval;
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
        ->assertSee('127.0.0.1')->assertSee('Send the code');
});

it('confirms the e-mail with the code typed on the signing-in screen, then the user gives their details and is in', function () {
    $entity = Make::entity('FSV');
    $user = Make::account($entity, 'SUPER_ADMIN', ['mfa' => true]);
    $session = Make::webSession($user);
    $sessionId = $session['auth_session_id'];

    app(FirstSignIn::class)->sendVerification(fsiAccount($user), 'Ali.Raza@Example.com', $sessionId);
    $code = fsiCode('ali.raza@example.com');
    expect($code)->toMatch('/^\d{6}$/');
    expect(fn () => app(FirstSignIn::class)->complete(fsiAccount($user), ['full_name' => 'Ali Raza', 'phone' => '0300-1234567']))
        ->toThrow(BusinessRuleViolation::class, 'Confirm your e-mail');

    // Only on the screen that asked for it; a wrong code counts.
    expect(fn () => app(FirstSignIn::class)->verifyCode(fsiAccount($user), $code, (string) Str::uuid()))
        ->toThrow(BusinessRuleViolation::class, 'another sign-in')
        ->and(fn () => app(FirstSignIn::class)->verifyCode(fsiAccount($user), $code === '000000' ? '111111' : '000000', $sessionId))
        ->toThrow(BusinessRuleViolation::class, '4 tries left');
    app(FirstSignIn::class)->verifyCode(fsiAccount($user), $code, $sessionId);
    expect(fsiAccount($user)->email_verified_at)->not->toBeNull();

    app(FirstSignIn::class)->complete(fsiAccount($user), ['full_name' => 'Ali Raza', 'phone' => '0300-1234567']);
    $user = fsiAccount($user);
    expect($user->needsFirstSignIn())->toBeFalse()
        ->and($user->email)->toBe('ali.raza@example.com')
        ->and(Make::system(fn () => Person::query()->withoutGlobalScopes()->find($user->person_id)))
        ->full_name->toBe('Ali Raza')->email->toBe('ali.raza@example.com');

    $this->actingAs($user, 'web')->withSession($session)->get('/admin')->assertOk();
});

it('takes a code only a few times, and not once it is too old', function () {
    $user = Make::account(Make::entity('FST'), 'SUPER_ADMIN', ['mfa' => true]);
    app(FirstSignIn::class)->sendVerification(fsiAccount($user), 'a@example.com');
    $code = fsiCode('a@example.com');
    $wrong = $code === '000000' ? '111111' : '000000';
    foreach (range(1, FirstSignIn::CODE_TRIES) as $i) {
        expect(fn () => app(FirstSignIn::class)->verifyCode(fsiAccount($user), $wrong, null))->toThrow(BusinessRuleViolation::class);
    }
    expect(fn () => app(FirstSignIn::class)->verifyCode(fsiAccount($user), $code, null))->toThrow(BusinessRuleViolation::class, 'Send a new e-mail');

    $this->travel(2)->minutes();
    app(FirstSignIn::class)->sendVerification(fsiAccount($user), 'a@example.com');
    $code = fsiCode('a@example.com', 2);
    $this->travel(16)->minutes();
    expect(fn () => app(FirstSignIn::class)->verifyCode(fsiAccount($user), $code, null))->toThrow(BusinessRuleViolation::class, 'too old');
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

    app(FirstSignIn::class)->sendVerification(fsiAccount($owner), 'owner@example.com', $auth->id);
    app(FirstSignIn::class)->verifyCode(fsiAccount($owner), fsiCode('owner@example.com'), $auth->id);
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

it('lets a new Owner whose listed network is another one finish the first sign-in, and adds this network', function () {
    config(['ricemill.security.super_admin_require_ip_allowlist' => true]);
    $entity = Make::entity('FSE');
    $owner = Make::account($entity, 'OWNER', ['mfa' => true]);
    Make::system(fn () => IpAllowlistEntry::query()->create(['entity_id' => $entity->id, 'login_account_id' => $owner->id, 'cidr' => '10.0.0.0/8']));
    $policy = app(IpAllowlistPolicy::class);
    expect(Make::system(fn () => $policy->allows(fsiAccount($owner), '149.40.161.14')))->toBeTrue();

    $session = Make::webSession($owner);
    $auth = Make::system(fn () => AuthSession::query()->findOrFail($session['auth_session_id']));
    Make::system(fn () => $auth->forceFill(['ip' => '149.40.161.14'])->save());
    Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($owner->id)->update(['email' => 'o@example.com', 'email_verified_at' => now()]));
    app(FirstSignIn::class)->complete(fsiAccount($owner), ['full_name' => 'Owner Two', 'phone' => '0300-1234567'], $auth);

    expect(Make::system(fn () => IpAllowlistEntry::query()->withoutGlobalScopes()->where('login_account_id', $owner->id)->orderBy('cidr')->pluck('cidr')->all()))
        ->toBe(['10.0.0.0/8', '149.40.161.0/24'])
        ->and(Make::system(fn () => $policy->allows(fsiAccount($owner), '149.40.161.14')))->toBeTrue()
        ->and(Make::system(fn () => $policy->allows(fsiAccount($owner), '8.8.8.8')))->toBeFalse();
});

/** The code in the n-th e-mail sent to an address (Mail::fake). */
function fsiCode(string $to, int $nth = 1): string
{
    $mails = Mail::sent(FirstSignInVerificationMail::class, fn (FirstSignInVerificationMail $m) => $m->hasTo($to))->values();

    return $mails[$nth - 1]->code;
}

it('waits for somebody signed in to approve the sign-in: QR code, then the matching number', function () {
    $entity = Make::entity('FSA');
    $approver = Make::account($entity, 'OWNER', ['mfa' => true]);
    Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($approver->id)->update(['first_sign_in_completed_at' => now()]));
    $newbie = Make::account($entity, 'SUPER_ADMIN', ['mfa' => true]);
    $session = Make::webSession($newbie);
    $authId = $session['auth_session_id'];
    Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($newbie->id)->update(['email' => 'n@example.com', 'email_verified_at' => now()]));
    $approvals = app(SignInApprovals::class);

    expect($approvals->needed(fsiAccount($newbie)))->toBeTrue()
        ->and($approvals->approvers(fsiAccount($newbie))->pluck('id')->all())->toBe([$approver->id])
        ->and(fn () => app(FirstSignIn::class)->complete(fsiAccount($newbie), ['full_name' => 'New Admin', 'phone' => '0300-1234567']))
        ->toThrow(BusinessRuleViolation::class, 'approved first');

    // The new screen shows the QR code and the number.
    $this->actingAs(fsiAccount($newbie), 'web')->withSession($session)->get('/admin/first-sign-in')->assertOk()
        ->assertSee('They pick this number')->assertSee('<svg', false);
    $open = Make::system(fn () => SignInApproval::query()->withoutGlobalScopes()->where('login_account_id', $newbie->id)->where('status', 'pending')->sole());
    expect($open->choices)->toHaveCount(3)->toContain($open->number);

    // A wrong number refuses it and ends that sign-in.
    $wrong = collect($open->choices)->first(fn ($n) => $n !== $open->number);
    expect($approvals->pick(fsiAccount($approver), $open->id, $wrong))->toBeFalse()
        ->and($approvals->status($open->id))->toBe('denied')
        ->and(Make::system(fn () => AuthSession::query()->withoutGlobalScopes()->find($authId))->revoked_at)->not->toBeNull();

    // A new request; the person signing in cannot approve it, the right number does.
    $again = $approvals->open(fsiAccount($newbie), null)['approval'];
    expect(fn () => $approvals->pick(fsiAccount($newbie), $again->id, $again->number))->toThrow(BusinessRuleViolation::class, 'may not approve')
        ->and($approvals->pendingFor(fsiAccount($approver))->pluck('id')->all())->toBe([$again->id])
        ->and($approvals->pick(fsiAccount($approver), $again->id, $again->number))->toBeTrue()
        ->and($approvals->needed(fsiAccount($newbie)))->toBeFalse()
        ->and(fn () => $approvals->pick(fsiAccount($approver), $again->id, $again->number))->toThrow(BusinessRuleViolation::class, 'no longer waiting');

    app(FirstSignIn::class)->complete(fsiAccount($newbie), ['full_name' => 'New Admin', 'phone' => '0300-1234567']);
    expect(fsiAccount($newbie)->needsFirstSignIn())->toBeFalse();
});

it('shows the approver the scanned request with three numbers, and lets them refuse it', function () {
    $entity = Make::entity('FSP');
    $approver = Make::account($entity, 'OWNER', ['mfa' => true]);
    Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($approver->id)->update(['first_sign_in_completed_at' => now()]));
    $newbie = Make::account($entity, 'SUPER_ADMIN', ['mfa' => true]);
    $opened = app(SignInApprovals::class)->open(fsiAccount($newbie), null);

    $this->actingAs(fsiAccount($approver), 'web')->withSession(Make::webSession($approver))
        ->get('/admin/approve-sign-ins?t='.$opened['token'])->assertOk()
        ->assertSee($newbie->username)->assertSee('Pick the number shown on their screen');

    app(SignInApprovals::class)->deny(fsiAccount($approver), $opened['approval']->id);
    expect(app(SignInApprovals::class)->status($opened['approval']->id))->toBe('denied');
});

it('skips the approval when nobody else could approve (a new company\'s first Owner)', function () {
    $owner = Make::account(Make::entity('FSK'), 'OWNER', ['mfa' => true]);
    Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($owner->id)->update(['email' => 'k@example.com', 'email_verified_at' => now()]));

    expect(app(SignInApprovals::class)->needed(fsiAccount($owner)))->toBeFalse();
    app(FirstSignIn::class)->complete(fsiAccount($owner), ['full_name' => 'First Owner', 'phone' => '0300-1234567']);
    expect(fsiAccount($owner)->needsFirstSignIn())->toBeFalse();
});

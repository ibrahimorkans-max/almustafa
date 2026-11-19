<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Auth\DeviceInfo;
use App\Domain\Auth\EmailCode;
use App\Domain\Auth\Exceptions\AuthenticationFailed;
use App\Domain\Auth\FirstSignIn;
use App\Domain\Auth\IpAllowlistPolicy;
use App\Domain\Auth\LoginService;
use App\Domain\Auth\MfaService;
use App\Domain\Auth\MobileCode;
use App\Domain\Auth\NewNetwork;
use App\Domain\Auth\PasskeyService;
use App\Domain\Auth\PasswordReset;
use App\Domain\Auth\SecondFactor;
use App\Domain\Auth\SignInApprovals;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\LoginAccountResource\Pages\CreateLoginAccount;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Mail\FirstSignInVerificationMail;
use App\Models\AuthSession;
use App\Models\Device;
use App\Models\IpAllowlistEntry;
use App\Models\LoginAccount;
use App\Models\Person;
use App\Models\Role;
use App\Support\Values\PakistanIds;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Make;
use Tests\Support\VirtualAuthenticator;

beforeEach(function () {
    config(['ricemill.auth.first_sign_in_check' => true]);
    Mail::fake();
});

const NEW_IP = '149.40.161.14';

/** A login that finished its first sign-in, optionally with a confirmed e-mail and listed networks. */
function sicAccount(string $entityCode, string $role, ?string $email, array $cidrs = []): LoginAccount
{
    $entity = Make::entity($entityCode);
    $account = Make::account($entity, $role, ['mfa' => true]);
    Make::system(function () use ($account, $email, $cidrs, $entity): void {
        LoginAccount::query()->withoutGlobalScopes()->whereKey($account->id)->toBase()->update(['first_sign_in_completed_at' => now(),
            'email' => $email, 'email_verified_at' => $email !== null ? now() : null]);
        foreach ($cidrs as $cidr) {
            IpAllowlistEntry::query()->create(['entity_id' => $entity->id, 'login_account_id' => $account->id, 'cidr' => $cidr]);
        }
    });

    return sicFresh($account);
}

function sicFresh(LoginAccount $account): LoginAccount
{
    return Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->with(['role', 'person', 'entity'])->findOrFail($account->id));
}

/** A web session from an address, as the sign-in page makes it (held when the network is not allowed). */
function sicSession(LoginAccount $account, string $ip): AuthSession
{
    if ($account->isOwner()) {
        Make::trustedDevice($account, $uid = (string) Str::uuid());
    }
    app(AuthContextBinder::class)->bind($account);
    $session = app(LoginService::class)->completeLogin($account, AuthSession::CHANNEL_WEB, new DeviceInfo($uid ?? (string) Str::uuid(), 'web', 'Chrome'), $ip, 'pest', true);
    if (! app(NewNetwork::class)->allowed($account, $ip)) {
        app(NewNetwork::class)->hold($session);
    }
    Make::reset();

    return Make::system(fn () => AuthSession::query()->withoutGlobalScopes()->findOrFail($session->id));
}

function sicCode(string $to): string
{
    return Mail::sent(FirstSignInVerificationMail::class, fn (FirstSignInVerificationMail $m) => $m->hasTo($to))->last()->code;
}

/** The 2-digit number of the last e-mail to that address (picked after the code). */
function sicNumber(string $to): int
{
    return (int) Mail::sent(FirstSignInVerificationMail::class, fn (FirstSignInVerificationMail $m) => $m->hasTo($to))->last()->match;
}

it('holds a web sign-in from a new network until the code from the confirmed e-mail is typed, then adds the network', function () {
    config(['ricemill.security.super_admin_require_ip_allowlist' => true]);
    $owner = sicAccount('NN1', 'OWNER', 'owner@example.com', ['10.0.0.0/8']);

    // The app (API) is still refused; the web sign-in goes on.
    expect(fn () => app(LoginService::class)->verifyPassword($owner->username, Make::PASSWORD, NEW_IP))->toThrow(AuthenticationFailed::class);
    Make::reset();
    app(LoginService::class)->verifyPassword($owner->username, Make::PASSWORD, NEW_IP, newNetworkCheck: true);
    Make::reset();

    $session = sicSession(sicFresh($owner), NEW_IP);
    expect($session->network_pending_at)->not->toBeNull();
    $web = [EnsureWebAuthSession::SESSION_KEY => $session->id];

    // Held: every screen leads to "Verify this new network", with no rights.
    $this->actingAs(sicFresh($owner), 'web')->withSession($web)->withServerVariables(['REMOTE_ADDR' => NEW_IP])
        ->get('/admin')->assertRedirect('/admin/new-network');
    $this->actingAs(sicFresh($owner), 'web')->withSession($web)->withServerVariables(['REMOTE_ADDR' => NEW_IP])
        ->get('/admin/new-network')->assertOk()->assertSee('You are signing in from a new network')->assertSee('Send the code');

    app(NewNetwork::class)->sendCode(sicFresh($owner), $session);
    expect(fn () => app(NewNetwork::class)->verifyCode(sicFresh($owner), $session, sicCode('owner@example.com') === '000000' ? '111111' : '000000'))
        ->toThrow(BusinessRuleViolation::class, 'tries left');
    app(NewNetwork::class)->verifyCode(sicFresh($owner), $session, sicCode('owner@example.com'));
    expect(Make::system(fn () => AuthSession::query()->withoutGlobalScopes()->find($session->id))->network_pending_at)->not->toBeNull();
    // An Owner: after the code and the number, nobody else has to approve it.
    $done = app(NewNetwork::class)->verifyNumber(sicFresh($owner), $session, sicNumber('owner@example.com'), NEW_IP);

    expect($done)->toBe(['stage' => 'done', 'network' => '149.40.161.0/24', 'device' => null])
        ->and(Make::system(fn () => AuthSession::query()->withoutGlobalScopes()->find($session->id))->network_pending_at)->toBeNull()
        ->and(Make::system(fn () => app(IpAllowlistPolicy::class)->allows(sicFresh($owner), NEW_IP)))->toBeTrue();
    $this->actingAs(sicFresh($owner), 'web')->withSession($web)->withServerVariables(['REMOTE_ADDR' => NEW_IP])->get('/admin')->assertOk();
});

it('lets somebody approve a new network with the QR code and number when there is no confirmed e-mail', function () {
    $admin = sicAccount('NN2', 'SUPER_ADMIN', null, ['10.0.0.0/8']);
    $approver = Make::account($admin->entity, 'OWNER', ['mfa' => true]);
    Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($approver->id)->update(['first_sign_in_completed_at' => now()]));

    expect(app(NewNetwork::class)->possible(sicFresh($admin)))->toBeTrue()
        ->and(app(NewNetwork::class)->byEmail(sicFresh($admin)))->toBeFalse();
    $session = sicSession(sicFresh($admin), NEW_IP);
    $opened = app(SignInApprovals::class)->open(sicFresh($admin), $session, EmailCode::NEW_NETWORK);

    expect(app(SignInApprovals::class)->pick(sicFresh($approver), $opened['approval']->id, $opened['approval']->number))->toBeTrue()
        ->and(Make::system(fn () => AuthSession::query()->withoutGlobalScopes()->find($session->id))->network_pending_at)->toBeNull()
        ->and(Make::system(fn () => IpAllowlistEntry::query()->withoutGlobalScopes()->where('login_account_id', $admin->id)->pluck('cidr')->sort()->values()->all()))
        ->toBe(['10.0.0.0/8', '149.40.161.0/24'])
        // A first sign-in approval is not given by it.
        ->and(sicFresh($admin)->first_sign_in_approved_at)->toBeNull();
});

it('still refuses a new network when nobody, no e-mail and no authenticator can prove it', function () {
    $admin = sicAccount('NN3', 'SUPER_ADMIN', null, ['10.0.0.0/8']);
    Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($admin->id)->toBase()->update(['mfa_secret' => null, 'mfa_confirmed_at' => null]));   // no authenticator: cannot add an e-mail here

    expect(app(NewNetwork::class)->possible(sicFresh($admin)))->toBeFalse()
        ->and(fn () => app(LoginService::class)->verifyPassword($admin->username, Make::PASSWORD, NEW_IP, newNetworkCheck: true))
        ->toThrow(AuthenticationFailed::class);
});

it('has the SaaS owner approved only by another SaaS owner', function () {
    $home = Make::entity('NNP');
    $saas = Make::platformAdmin($home);
    $other = Make::platformAdmin($home);
    $owner = Make::account($home, 'OWNER', ['mfa' => true]);
    Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->whereIn('id', [$other->id, $owner->id, $saas->id])->update(['first_sign_in_completed_at' => now()]));

    expect(app(SignInApprovals::class)->approvers(sicFresh($saas))->pluck('id')->all())->toBe([$other->id])
        ->and(app(SignInApprovals::class)->approvers(sicFresh($owner))->pluck('id')->contains($other->id))->toBeFalse();
});

it('resets the password of an Owner or Super Admin with the code sent to the confirmed e-mail', function () {
    $admin = sicAccount('PR1', 'SUPER_ADMIN', 'admin@example.com');
    $old = sicSession($admin, '127.0.0.1');
    $browser = (string) Str::uuid();

    app(PasswordReset::class)->request(strtoupper($admin->username), $browser, '127.0.0.1');
    $code = sicCode('admin@example.com');
    expect(fn () => app(PasswordReset::class)->checkCode($admin->username, $code, (string) Str::uuid()))
        ->toThrow(BusinessRuleViolation::class, 'wrong or too old')
        ->and(fn () => app(PasswordReset::class)->reset($admin->username, 'A-new-Passw0rd-2026', $browser, '127.0.0.1'))
        ->toThrow(BusinessRuleViolation::class, 'Start again');

    expect(app(PasswordReset::class)->checkCode($admin->username, $code, $browser))->toHaveCount(4)->toContain(sicNumber('admin@example.com'));
    app(PasswordReset::class)->pickNumber($admin->username, sicNumber('admin@example.com'), $browser);
    app(PasswordReset::class)->reset($admin->username, 'A-new-Passw0rd-2026', $browser, '127.0.0.1');
    $admin = sicFresh($admin);
    expect(Hash::check('A-new-Passw0rd-2026', $admin->password))->toBeTrue()
        ->and(Make::system(fn () => AuthSession::query()->withoutGlobalScopes()->find($old->id))->revoked_at)->not->toBeNull()
        ->and($admin->mfa_confirmed_at)->not->toBeNull();   // the authenticator is still asked
});

it('sends nothing (and says nothing) for other roles, unknown usernames and logins without a confirmed e-mail', function () {
    $clerk = sicAccount('PR2', 'ACCOUNTANT', 'clerk@example.com');
    $noMail = sicAccount('PR3', 'OWNER', null);

    app(PasswordReset::class)->request($clerk->username, 'b1', '10.1.1.1');
    app(PasswordReset::class)->request($noMail->username, 'b2', '10.1.1.2');
    app(PasswordReset::class)->request('nobody.here', 'b3', '10.1.1.3');

    Mail::assertNothingSent();
    expect(fn () => app(PasswordReset::class)->checkCode($clerk->username, '123456', 'b1'))
        ->toThrow(BusinessRuleViolation::class, 'wrong or too old');
});

it('shows the forgot-password page and its link on the sign-in page', function () {
    $this->get('/admin/login')->assertOk()->assertSee('Forgot your password?');
    $this->get('/admin/forgot-password')->assertOk()->assertSee('Reset your password');
});

it('lets a signed-in person confirm the e-mail their security codes go to', function () {
    $saas = Make::platformAdmin(Make::entity('SE1'));
    Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($saas->id)->update(['first_sign_in_completed_at' => now()]));
    $web = Make::webSession(sicFresh($saas));

    $this->actingAs(sicFresh($saas), 'web')->withSession($web)->get('/admin/security-email')->assertOk()->assertSee('Set the e-mail your security codes go to');
    app(EmailCode::class)->send(sicFresh($saas), 'saas@example.com', $web[EnsureWebAuthSession::SESSION_KEY], EmailCode::CONFIRM_EMAIL);
    Mail::assertSent(FirstSignInVerificationMail::class, fn (FirstSignInVerificationMail $m) => $m->purpose === EmailCode::CONFIRM_EMAIL && $m->hasTo('saas@example.com'));
});

it('makes the e-mail and mobile compulsory on a Super Admin\'s or Owner\'s login, and keeps them', function () {
    $entity = Make::entity('SC1');
    $admin = Make::account($entity, 'SUPER_ADMIN');
    Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($admin->id)->update(['first_sign_in_completed_at' => now()]));
    $admin = sicFresh($admin);
    $this->actingAs($admin, 'web')->withSession(Make::webSession($admin));
    app(AuthContextBinder::class)->bind($admin);
    $superAdmin = Role::query()->where('entity_id', $entity->id)->where('code', 'SUPER_ADMIN')->firstOrFail();
    $gate = Role::query()->where('entity_id', $entity->id)->where('code', 'GATE_OPERATOR')->firstOrFail();

    Livewire::test(CreateLoginAccount::class)
        ->fillForm(['full_name' => 'Mill Admin', 'username' => 'mill.admin2', 'role_id' => $superAdmin->id, 'password' => 'Temp-Password-123!'])
        ->call('create')->assertHasFormErrors(['email' => 'required', 'phone' => 'required']);
    Livewire::test(CreateLoginAccount::class)
        ->fillForm(['full_name' => 'Mill Admin', 'username' => 'mill.admin2', 'role_id' => $superAdmin->id, 'password' => 'Temp-Password-123!',
            'email' => 'Admin2@Example.com', 'phone' => '0301-7654321'])
        ->call('create')->assertHasNoFormErrors();
    // Other roles: not compulsory.
    Livewire::test(CreateLoginAccount::class)
        ->fillForm(['full_name' => 'Gate Man', 'username' => 'gate.man2', 'role_id' => $gate->id, 'password' => 'Temp-Password-123!'])
        ->call('create')->assertHasNoFormErrors();

    $created = sicFresh(Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->where('username', 'mill.admin2')->sole()));
    expect($created->email)->toBe('admin2@example.com')
        ->and($created->person->phone)->toBe(PakistanIds::normalizeMobile('0301-7654321'))
        ->and($created->email_verified_at)->toBeNull();
});

it('sends every code only to the e-mail on the login, never to another one typed', function () {
    $owner = Make::account(Make::entity('SC2'), 'OWNER', ['mfa' => true]);
    Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($owner->id)->update(['email' => 'set.by.admin@example.com']));

    app(FirstSignIn::class)->sendVerification(sicFresh($owner), 'someone.else@example.com', null);
    Mail::assertSent(FirstSignInVerificationMail::class, fn (FirstSignInVerificationMail $m) => $m->hasTo('set.by.admin@example.com'));
    Mail::assertNotSent(FirstSignInVerificationMail::class, fn (FirstSignInVerificationMail $m) => $m->hasTo('someone.else@example.com'));
    expect(sicFresh($owner)->email)->toBe('set.by.admin@example.com');

    // Without one, the first address given is kept and confirmed by its code.
    $newbie = Make::account(Make::entity('SC3'), 'SUPER_ADMIN', ['mfa' => true]);
    app(FirstSignIn::class)->sendVerification(sicFresh($newbie), 'First@Example.com', null);
    app(FirstSignIn::class)->verifyCode(sicFresh($newbie), sicCode('first@example.com'), null);
    app(FirstSignIn::class)->verifyNumber(sicFresh($newbie), sicNumber('first@example.com'), null);
    expect(sicFresh($newbie)->email)->toBe('first@example.com')->and(sicFresh($newbie)->email_verified_at)->not->toBeNull();
});

it('brings an Owner without an e-mail or mobile to My security e-mail after signing in', function () {
    config(['ricemill.auth.require_security_contact' => true]);
    $owner = Make::account(Make::entity('SC4'), 'OWNER', ['mfa' => true]);
    Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($owner->id)->update(['first_sign_in_completed_at' => now()]));
    $web = Make::webSession(sicFresh($owner));

    expect(sicFresh($owner)->missingSecurityContact())->toBeTrue();
    $this->actingAs(sicFresh($owner), 'web')->withSession($web)->get('/admin')->assertRedirect('/admin/security-email');
    $this->actingAs(sicFresh($owner), 'web')->withSession($web)->get('/admin/security-email')->assertOk()
        ->assertSee('An e-mail and a mobile number are compulsory');

    Make::system(function () use ($owner): void {
        LoginAccount::query()->withoutGlobalScopes()->whereKey($owner->id)->update(['email' => 'o4@example.com']);
        Person::query()->withoutGlobalScopes()->whereKey($owner->person_id)->update(['phone' => '+923001234567']);
    });
    expect(sicFresh($owner)->missingSecurityContact())->toBeFalse();
    $this->actingAs(sicFresh($owner), 'web')->withSession($web)->get('/admin')->assertOk();
});

it('approves a new device with the code sent to the e-mail on the login, without anybody else', function () {
    $owner = sicAccount('ND1', 'OWNER', 'nd1@example.com');
    Make::trustedDevice($owner, (string) Str::uuid());   // an earlier device: another Owner would have to approve a new one
    $uid = (string) Str::uuid();

    // The app (API) still waits for an Owner's approval.
    app(AuthContextBinder::class)->bind($owner);
    expect(fn () => app(LoginService::class)->completeLogin(sicFresh($owner), AuthSession::CHANNEL_API, new DeviceInfo((string) Str::uuid(), 'android', 'Phone'), '127.0.0.1', 'pest', true))
        ->toThrow(AuthenticationFailed::class);
    Make::reset();

    // The web sign-in goes on, held until the e-mail code proves the device.
    app(AuthContextBinder::class)->bind(sicFresh($owner));
    $session = app(LoginService::class)->completeLogin(sicFresh($owner), AuthSession::CHANNEL_WEB, new DeviceInfo($uid, 'web', 'Chrome on Windows'), '127.0.0.1', 'pest', true);
    Make::reset();
    $session = Make::system(fn () => AuthSession::query()->withoutGlobalScopes()->findOrFail($session->id));
    $web = [EnsureWebAuthSession::SESSION_KEY => $session->id];
    expect($session->device_pending_at)->not->toBeNull()->and($session->network_pending_at)->toBeNull();

    $this->actingAs(sicFresh($owner), 'web')->withSession($web)->get('/admin')->assertRedirect('/admin/new-network');
    $this->actingAs(sicFresh($owner), 'web')->withSession($web)->get('/admin/new-network')->assertOk()
        ->assertSee('You are signing in on a new device')->assertSee('Nobody else has to approve this device');

    app(NewNetwork::class)->sendCode(sicFresh($owner), $session);
    Mail::assertSent(FirstSignInVerificationMail::class, fn (FirstSignInVerificationMail $m) => $m->purpose === EmailCode::NEW_DEVICE && $m->hasTo('nd1@example.com'));
    app(NewNetwork::class)->verifyCode(sicFresh($owner), $session, sicCode('nd1@example.com'));
    $done = app(NewNetwork::class)->verifyNumber(sicFresh($owner), $session, sicNumber('nd1@example.com'), '127.0.0.1');

    expect($done)->toBe(['stage' => 'done', 'network' => null, 'device' => 'Chrome on Windows'])
        ->and(Make::system(fn () => Device::query()->withoutGlobalScopes()->where('device_uid', $uid)->where('platform', 'web')->sole())->isTrusted())->toBeTrue()
        ->and(Make::system(fn () => AuthSession::query()->withoutGlobalScopes()->find($session->id))->device_pending_at)->toBeNull();
    $this->actingAs(sicFresh($owner), 'web')->withSession($web)->get('/admin')->assertOk();
});

it('still asks an Owner to approve a new device when the login has no e-mail and no authenticator', function () {
    $owner = sicAccount('ND2', 'OWNER', null);
    Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($owner->id)->toBase()->update(['mfa_secret' => null, 'mfa_confirmed_at' => null]));   // no authenticator: cannot add an e-mail here
    Make::trustedDevice($owner, (string) Str::uuid());

    expect(app(NewNetwork::class)->canProveDevice(sicFresh($owner)))->toBeFalse();
    app(AuthContextBinder::class)->bind(sicFresh($owner));
    expect(fn () => app(LoginService::class)->completeLogin(sicFresh($owner), AuthSession::CHANNEL_WEB, new DeviceInfo((string) Str::uuid(), 'web', 'Chrome'), '127.0.0.1', 'pest', true))
        ->toThrow(AuthenticationFailed::class);
});

it('lets the server admin set the e-mail and mobile of the SaaS owner, so a new network is proven by its code (auth:contact)', function () {
    $saas = Make::platformAdmin(Make::entity('AC1'));
    Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($saas->id)->update(['first_sign_in_completed_at' => now(),
        'mfa_secret' => null, 'mfa_confirmed_at' => null]));   // no authenticator either: nothing can prove a new network
    expect(app(NewNetwork::class)->possible(sicFresh($saas)))->toBeFalse();

    $this->artisan('auth:contact', ['username' => $saas->username])->expectsOutputToContain('nothing yet')->assertSuccessful();
    $this->artisan('auth:contact', ['username' => $saas->username, '--email' => 'not-an-email'])->assertFailed();
    $this->artisan('auth:contact', ['username' => $saas->username, '--email' => 'Saas@Example.com', '--phone' => '0300-1234567'])
        ->expectsOutputToContain('the code sent to that e-mail')->assertSuccessful();

    expect(sicFresh($saas)->email)->toBe('saas@example.com')
        ->and(app(NewNetwork::class)->possible(sicFresh($saas)))->toBeTrue()
        ->and(sicFresh($saas)->person->phone)->toBe(PakistanIds::normalizeMobile('0300-1234567'));
});

it('lets a login without an e-mail (but with its authenticator) give one on the check screen and prove the new network', function () {
    config(['ricemill.security.super_admin_require_ip_allowlist' => true]);
    $saas = sicAccount('NE1', 'OWNER', null, ['10.0.0.0/8']);
    Make::system(fn () => LoginAccount::query()->withoutGlobalScopes()->whereKey($saas->id)->update(['is_platform_admin' => true]));

    expect(app(NewNetwork::class)->possible(sicFresh($saas)))->toBeTrue()
        ->and(app(NewNetwork::class)->canAddEmail(sicFresh($saas)))->toBeTrue();
    $session = sicSession(sicFresh($saas), NEW_IP);
    $web = [EnsureWebAuthSession::SESSION_KEY => $session->id];
    $this->actingAs(sicFresh($saas), 'web')->withSession($web)->withServerVariables(['REMOTE_ADDR' => NEW_IP])
        ->get('/admin/new-network')->assertOk()->assertSee('Your login has no e-mail yet')->assertSee('Your e-mail address');

    app(NewNetwork::class)->sendCode(sicFresh($saas), $session, 'Owner.New@Example.com');
    expect(sicFresh($saas)->email)->toBe('owner.new@example.com')->and(sicFresh($saas)->email_verified_at)->toBeNull();
    app(NewNetwork::class)->verifyCode(sicFresh($saas), $session, sicCode('owner.new@example.com'));
    $done = app(NewNetwork::class)->verifyNumber(sicFresh($saas), $session, sicNumber('owner.new@example.com'), NEW_IP);

    expect($done['network'])->toBe('149.40.161.0/24')
        ->and(sicFresh($saas)->email_verified_at)->not->toBeNull();
    // From then on the code goes only to that address.
    expect(app(NewNetwork::class)->canAddEmail(sicFresh($saas)))->toBeFalse();
});

it('asks for all the checks on a new network: the e-mail code and number, then QR + number picked on the person\'s own other device', function () {
    $owner = sicAccount('AL1', 'ACCOUNTANT', 'all@example.com', ['10.0.0.0/8', '127.0.0.0/24']);   // a top role would need nobody
    $mine = sicSession(sicFresh($owner), '127.0.0.1');            // already signed in at the office (an approved device)
    Make::system(fn () => Device::query()->withoutGlobalScopes()->whereKey($mine->device_id)->update(['trusted_at' => now()]));
    $held = sicSession(sicFresh($owner), NEW_IP);                 // the new sign-in, held
    expect($held->network_pending_at)->not->toBeNull()
        ->and(app(NewNetwork::class)->approvalNeeded(sicFresh($owner), $held))->toBeTrue();

    // 1. The e-mail code: not enough on its own, the QR code + number come next.
    app(NewNetwork::class)->sendCode(sicFresh($owner), $held);
    app(NewNetwork::class)->verifyCode(sicFresh($owner), $held, sicCode('all@example.com'));
    $done = app(NewNetwork::class)->verifyNumber(sicFresh($owner), $held, sicNumber('all@example.com'), NEW_IP);
    $held = Make::system(fn () => AuthSession::query()->withoutGlobalScopes()->find($held->id));
    expect($done['stage'])->toBe('approval')->and($held->code_proven_at)->not->toBeNull()->and($held->network_pending_at)->not->toBeNull();

    // 2. "Is it you?": the person's other sign-in sees it and picks the number; the held one itself cannot.
    $request = app(SignInApprovals::class)->open(sicFresh($owner), $held, EmailCode::NEW_NETWORK)['approval'];
    expect(app(SignInApprovals::class)->pendingFor(sicFresh($owner), $mine->id)->pluck('id')->all())->toBe([$request->id])
        ->and(app(SignInApprovals::class)->pendingFor(sicFresh($owner), $held->id))->toBeEmpty()
        ->and(fn () => app(SignInApprovals::class)->pick(sicFresh($owner), $request->id, $request->number, $held->id))->toThrow(BusinessRuleViolation::class);
    expect(app(SignInApprovals::class)->pick(sicFresh($owner), $request->id, $request->number, $mine->id))->toBeTrue();

    $held = Make::system(fn () => AuthSession::query()->withoutGlobalScopes()->find($held->id));
    expect($held->network_pending_at)->toBeNull()
        ->and(Make::system(fn () => app(IpAllowlistPolicy::class)->allows(sicFresh($owner), NEW_IP)))->toBeTrue();
});

it('uses a code to the mobile as the second check when no other device is signed in to scan the QR code', function () {
    config(['ricemill.security.super_admin_require_ip_allowlist' => true, 'ricemill.messaging.sms_url' => 'https://sms.example.test/send']);
    $texts = [];
    Http::fake(['sms.example.test/*' => function ($request) use (&$texts) {
        $texts[] = $request['text'];

        return Http::response(['ok' => true]);
    }]);
    $owner = sicAccount('MC1', 'ACCOUNTANT', 'mc@example.com', ['10.0.0.0/8']);   // a top role would need nobody
    Make::system(fn () => Person::query()->withoutGlobalScopes()->whereKey($owner->person_id)->update(['phone' => '+923001234567']));
    $held = sicSession(sicFresh($owner), NEW_IP);
    expect(app(SignInApprovals::class)->canBeApproved(sicFresh($owner), $held->id))->toBeFalse()
        ->and(app(MobileCode::class)->available(sicFresh($owner)))->toBeTrue()
        ->and(app(NewNetwork::class)->approvalNeeded(sicFresh($owner), $held))->toBeTrue();

    // Not before the e-mail code.
    expect(fn () => app(NewNetwork::class)->verifyMobile(sicFresh($owner), $held, '123456'))->toThrow(BusinessRuleViolation::class, 'e-mail first');
    app(NewNetwork::class)->sendCode(sicFresh($owner), $held);
    app(NewNetwork::class)->verifyCode(sicFresh($owner), $held, sicCode('mc@example.com'));
    expect(app(NewNetwork::class)->verifyNumber(sicFresh($owner), $held, sicNumber('mc@example.com'), NEW_IP)['stage'])->toBe('approval');

    app(MobileCode::class)->send(sicFresh($owner), $held);
    preg_match('/\d{6}/', (string) end($texts), $m);
    $code = $m[0];
    expect(fn () => app(NewNetwork::class)->verifyMobile(sicFresh($owner), $held, $code === '000000' ? '111111' : '000000'))
        ->toThrow(BusinessRuleViolation::class, '4 tries left');
    $done = app(NewNetwork::class)->verifyMobile(sicFresh($owner), $held, $code);

    expect($done['network'])->toBe('149.40.161.0/24')
        ->and(Make::system(fn () => AuthSession::query()->withoutGlobalScopes()->find($held->id))->network_pending_at)->toBeNull();
});

it('asks the person\'s own second factor after the e-mail codes — authenticator, recovery or mobile code — and nobody else', function () {
    config(['ricemill.auth.second_factor' => true, 'ricemill.messaging.sms_url' => 'https://sms.example.test/send']);
    $texts = [];
    Http::fake(['sms.example.test/*' => function ($request) use (&$texts) {
        $texts[] = $request['text'];

        return Http::response(['ok' => true]);
    }]);
    $owner = sicAccount('SF1', 'ACCOUNTANT', 'sf@example.com', ['10.0.0.0/8']);   // not a top role: still nobody else approves
    Make::system(fn () => Person::query()->withoutGlobalScopes()->whereKey($owner->person_id)->update(['phone' => '+923001234567']));
    $held = sicSession(sicFresh($owner), NEW_IP);
    $factor = app(SecondFactor::class);
    expect($factor->methods(sicFresh($owner)))->toContain('totp')->toContain('mobile')
        ->and(app(NewNetwork::class)->approvalNeeded(sicFresh($owner), Make::system(fn () => AuthSession::query()->withoutGlobalScopes()->find($held->id))->forceFill(['code_proven_at' => now()])))->toBeFalse();

    app(NewNetwork::class)->sendCode(sicFresh($owner), $held);
    app(NewNetwork::class)->verifyCode(sicFresh($owner), $held, sicCode('sf@example.com'));
    // The e-mail codes alone do not let it go.
    expect(app(NewNetwork::class)->verifyNumber(sicFresh($owner), $held, sicNumber('sf@example.com'), NEW_IP)['stage'])->toBe('factor')
        ->and(fn () => app(NewNetwork::class)->verifyMobile(sicFresh($owner), $held, '123456'))->toThrow(BusinessRuleViolation::class, 'Prove it is you first');

    $code = Make::totp(sicFresh($owner));
    expect(fn () => $factor->proveCode(sicFresh($owner), $held->id, 'totp', $code === '000000' ? '111111' : '000000'))
        ->toThrow(BusinessRuleViolation::class, 'tries left');
    $factor->proveCode(sicFresh($owner), $held->id, 'totp', $code);
    expect($factor->proven(sicFresh($owner), $held->id))->toBeTrue()
        // The same authenticator code cannot be used again.
        ->and(fn () => $factor->proveCode(sicFresh($owner), 'other-check', 'totp', $code))->toThrow(BusinessRuleViolation::class);

    $done = app(NewNetwork::class)->afterSecondFactor(sicFresh($owner), $held, NEW_IP);
    expect($done)->toBe(['stage' => 'done', 'network' => '149.40.161.0/24', 'device' => null]);

    // A code to the mobile works too (for another check), and a recovery code.
    $factor->sendMobile(sicFresh($owner), 'browser-x');
    preg_match('/\d{6}/', (string) end($texts), $m);
    $factor->proveMobile(sicFresh($owner), 'browser-x', $m[0]);
    expect($factor->proven(sicFresh($owner), 'browser-x'))->toBeTrue();
    app(AuthContextBinder::class)->bind(sicFresh($owner));
    $recovery = app(MfaService::class)->regenerateRecoveryCodes(sicFresh($owner));
    Make::reset();
    expect($factor->methods(sicFresh($owner)))->toContain('recovery');
    $factor->proveCode(sicFresh($owner), 'browser-y', 'recovery', $recovery[0]);
    expect($factor->proven(sicFresh($owner), 'browser-y'))->toBeTrue()
        ->and(fn () => $factor->proveCode(sicFresh($owner), 'browser-z', 'recovery', $recovery[0]))->toThrow(BusinessRuleViolation::class);
});

it('resets a password only after the second factor too', function () {
    config(['ricemill.auth.second_factor' => true]);
    $admin = sicAccount('SF2', 'SUPER_ADMIN', 'sf2@example.com');
    $browser = (string) Str::uuid();
    app(PasswordReset::class)->request($admin->username, $browser, '127.0.0.1');
    app(PasswordReset::class)->checkCode($admin->username, sicCode('sf2@example.com'), $browser);
    app(PasswordReset::class)->pickNumber($admin->username, sicNumber('sf2@example.com'), $browser);

    expect(app(PasswordReset::class)->factorNeeded($admin->username, $browser))->toBeTrue()
        ->and(fn () => app(PasswordReset::class)->reset($admin->username, 'A-new-Passw0rd-2026', $browser, '127.0.0.1'))->toThrow(BusinessRuleViolation::class, 'Start again')
        ->and(fn () => app(PasswordReset::class)->factorAccount($admin->username, (string) Str::uuid()))->toThrow(BusinessRuleViolation::class);

    app(SecondFactor::class)->proveCode(app(PasswordReset::class)->factorAccount($admin->username, $browser), $browser, 'totp', Make::totp(sicFresh($admin)));
    app(PasswordReset::class)->reset($admin->username, 'A-new-Passw0rd-2026', $browser, '127.0.0.1');
    expect(Hash::check('A-new-Passw0rd-2026', sicFresh($admin)->password))->toBeTrue();
    $this->get('/admin/forgot-password')->assertOk();
});

it('takes the person\'s own passkey as the second factor, never somebody else\'s, and shows the step on the check screen', function () {
    config(['ricemill.auth.second_factor' => true]);
    $owner = sicAccount('SF3', 'OWNER', 'sf3@example.com', ['10.0.0.0/8']);
    $other = sicAccount('SF4', 'OWNER', 'sf4@example.com');
    $passkeys = app(PasskeyService::class);
    $mine = new VirtualAuthenticator;
    $theirs = new VirtualAuthenticator;
    $passkeys->register(sicFresh($owner), $mine->register($passkeys->registrationOptions(sicFresh($owner))), 'My phone');
    $passkeys->register(sicFresh($other), $theirs->register($passkeys->registrationOptions(sicFresh($other))), 'Their phone');
    Make::reset();

    $factor = app(SecondFactor::class);
    expect($factor->methods(sicFresh($owner))[0])->toBe('passkey');
    expect(fn () => $factor->provePasskey(sicFresh($owner), 'k1', $theirs->assert($passkeys->authenticationOptions(), $other->id)))
        ->toThrow(BusinessRuleViolation::class, 'not one of this login');
    $factor->provePasskey(sicFresh($owner), 'k1', $mine->assert($passkeys->authenticationOptions(), $owner->id));
    expect($factor->proven(sicFresh($owner), 'k1'))->toBeTrue();

    // The check screen shows the step once the e-mail codes are done.
    $held = sicSession(sicFresh($owner), NEW_IP);
    app(NewNetwork::class)->sendCode(sicFresh($owner), $held);
    app(NewNetwork::class)->verifyCode(sicFresh($owner), $held, sicCode('sf3@example.com'));
    app(NewNetwork::class)->verifyNumber(sicFresh($owner), $held, sicNumber('sf3@example.com'), NEW_IP);
    $this->actingAs(sicFresh($owner), 'web')->withSession([EnsureWebAuthSession::SESSION_KEY => $held->id])->withServerVariables(['REMOTE_ADDR' => NEW_IP])
        ->get('/admin/new-network')->assertOk()->assertSee('Prove it is you')->assertSee('Use my passkey')->assertSee('Authenticator app');
});

<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Auth\SessionService;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\ChangePassword;
use App\Filament\Pages\TwoFactor;
use App\Filament\Resources\LoginAccountResource;
use App\Filament\Resources\LoginAccountResource\Pages\CreateLoginAccount;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Models\AuditLog;
use App\Models\AuthSession;
use App\Models\LoginAccount;
use App\Models\Role;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\Make;

beforeEach(function () {
    $this->entity = Make::entity('WEB');
    Make::reset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

describe('sign-in page', function () {
    it('signs in with username and password', function () {
        $gate = Make::account($this->entity, 'GATE_OPERATOR');

        Livewire::test(Login::class)
            ->fillForm(['username' => strtoupper($gate->username), 'password' => Make::PASSWORD])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        expect(auth('web')->id())->toBe($gate->id)
            ->and(session(EnsureWebAuthSession::SESSION_KEY))->not->toBeNull()
            ->and(Make::system(fn () => AuthSession::query()->sole()->channel))->toBe('web');
    });

    it('rejects a wrong password with a generic message', function () {
        $gate = Make::account($this->entity, 'GATE_OPERATOR');

        Livewire::test(Login::class)
            ->fillForm(['username' => $gate->username, 'password' => 'nope'])
            ->call('authenticate')
            ->assertHasFormErrors(['username']);

        expect(auth('web')->check())->toBeFalse();
    });

    it('asks for the 2FA code in a second step', function () {
        $owner = Make::account($this->entity, 'SUPER_ADMIN');

        $page = Livewire::test(Login::class)
            ->fillForm(['username' => $owner->username, 'password' => Make::PASSWORD])
            ->call('authenticate')
            ->assertSet('awaitingCode', true);
        expect(auth('web')->check())->toBeFalse();

        // Six boxes that send the code by themselves; a wrong code empties them for another try.
        $page->assertSee('Two-step verification')->assertSee('Digit 6 of 6')->assertSee('Lost your phone? Use a recovery code')->assertSee('Verify &amp; continue', false);
        $page->fillForm(['code' => '000000'])->call('authenticate')->assertHasFormErrors(['code'])->assertSet('data.code', null);
        expect(auth('web')->check())->toBeFalse();
        $page->call('toggleRecoveryCode')->assertSet('useRecoveryCode', true)->assertSee('Recovery code')->call('toggleRecoveryCode');

        $page->fillForm(['code' => Make::totp($owner)])->call('authenticate')->assertHasNoFormErrors();
        expect(auth('web')->id())->toBe($owner->id);
    });

    it('cannot jump to the code step without passing the password step', function () {
        Make::account($this->entity, 'SUPER_ADMIN');

        Livewire::test(Login::class)
            ->set('awaitingCode', true)
            ->fillForm(['code' => '123456'])
            ->call('authenticate')
            ->assertHasFormErrors(['code']);

        expect(auth('web')->check())->toBeFalse();
    });

    it('keeps brokers out of the back office', function () {
        $broker = Make::account($this->entity, 'BROKER');

        Livewire::test(Login::class)
            ->fillForm(['username' => $broker->username, 'password' => Make::PASSWORD])
            ->call('authenticate')
            ->assertHasFormErrors(['username']);
    });
});

describe('after sign-in', function () {
    it('confines a role that requires 2FA to the setup page until enrolled', function () {
        $owner = Make::account($this->entity, 'SUPER_ADMIN', ['mfa' => false]);
        $session = Make::webSession($owner);

        $this->actingAs($owner, 'web')->withSession($session)->get('/admin/users')
            ->assertRedirect(TwoFactor::getUrl());
        $this->get('/admin/two-factor')->assertOk()->assertSee('requires two-factor');
    });

    it('enrolls 2FA and returns one-time recovery codes', function () {
        $owner = Make::account($this->entity, 'SUPER_ADMIN', ['mfa' => false]);
        $this->actingAs($owner, 'web')->withSession(Make::webSession($owner));
        app(AuthContextBinder::class)->bind($owner);

        $page = Livewire::test(TwoFactor::class);
        $page->instance()->qrSvg(); // generates the secret in the session
        $secret = session('mfa.enroll_secret');

        $page->fillForm(['code' => app(Google2FA::class)->getCurrentOtp($secret)])->call('confirm')->assertHasNoFormErrors();

        expect($page->get('recoveryCodes'))->toHaveCount(10)
            ->and(Make::system(fn () => $owner->fresh()->hasMfa()))->toBeTrue();
    });

    it('forces a temporary password to be changed first', function () {
        $gate = Make::account($this->entity, 'GATE_OPERATOR', ['must_change_password' => true]);

        $this->actingAs($gate, 'web')->withSession(Make::webSession($gate))->get('/admin')
            ->assertRedirect(ChangePassword::getUrl());
    });

    it('signs the browser out when its session is revoked elsewhere', function () {
        $gate = Make::account($this->entity, 'GATE_OPERATOR');
        $session = Make::webSession($gate);
        $this->actingAs($gate, 'web')->withSession($session)->get('/admin')->assertOk();

        Make::system(fn () => app(SessionService::class)->revoke(AuthSession::query()->findOrFail($session[EnsureWebAuthSession::SESSION_KEY]), 'test'));

        $this->get('/admin')->assertRedirect(Filament::getLoginUrl());
    });

    it('signs out after the idle timeout', function () {
        $gate = Make::account($this->entity, 'GATE_OPERATOR');
        $this->actingAs($gate, 'web')->withSession(Make::webSession($gate))->get('/admin')->assertOk();

        $this->travel(31)->minutes();
        $this->get('/admin')->assertRedirect(Filament::getLoginUrl());
    });

    it('signs out when the role\'s absolute session limit is reached', function () {
        $sa = Make::owner($this->entity);
        $this->actingAs($sa, 'web')->withSession(Make::webSession($sa));

        $limit = $sa->role->session_max_minutes; // the Owner: 12 hours
        foreach (range(1, intdiv($limit, 15) - 1) as $i) {
            $this->travel(15)->minutes(); // active, so the 30-minute idle limit never triggers
            $this->get('/admin')->assertOk();
        }
        $this->travel(20)->minutes(); // 5 minutes past the limit
        $this->get('/admin')->assertRedirect(Filament::getLoginUrl());
    });

    it('sends security headers', function () {
        $gate = Make::account($this->entity, 'GATE_OPERATOR');
        $this->actingAs($gate, 'web')->withSession(Make::webSession($gate))->get('/admin')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing('X-Powered-By');   // the PHP version is never told
    });
});

describe('user administration', function () {
    it('creates a user with a temporary password and audits it', function () {
        $owner = Make::account($this->entity, 'SUPER_ADMIN');
        $this->actingAs($owner, 'web')->withSession(Make::webSession($owner));
        app(AuthContextBinder::class)->bind($owner);
        $gateRole = Role::query()->where('code', 'GATE_OPERATOR')->firstOrFail();

        Livewire::test(CreateLoginAccount::class)
            ->fillForm([
                'full_name' => 'Ramesh Kumar', 'username' => 'ramesh.gate', 'role_id' => $gateRole->id,
                'password' => 'Temp-Password-123!',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = LoginAccount::query()->where('username', 'ramesh.gate')->sole();
        expect($created->must_change_password)->toBeTrue()
            ->and($created->entity_id)->toBe($this->entity->id)
            ->and(AuditLog::query()->where('action', 'login_account.created')->where('subject_id', $created->id)->exists())->toBeTrue();
    });

    it('rejects a username taken in another entity', function () {
        $other = Make::entity('OTH');
        Make::account($other, 'GATE_OPERATOR', ['username' => 'taken.name']);
        $owner = Make::account($this->entity, 'SUPER_ADMIN');
        $this->actingAs($owner, 'web')->withSession(Make::webSession($owner));
        app(AuthContextBinder::class)->bind($owner);
        $gateRole = Role::query()->where('code', 'GATE_OPERATOR')->firstOrFail();

        Livewire::test(CreateLoginAccount::class)
            ->fillForm(['full_name' => 'X', 'username' => 'taken.name', 'role_id' => $gateRole->id, 'password' => 'Temp-Password-123!'])
            ->call('create')
            ->assertHasFormErrors(['username']);
    });

    it('does not offer roles with more rights than the creator holds', function () {
        $supervisor = Make::account($this->entity, 'SUPERVISOR');
        Role::query(); // noop
        app(AuthContextBinder::class)->bind(Make::account($this->entity, 'SUPER_ADMIN'));
        // Give the supervisor user.manage only, through the real approval path being out of scope here:
        Make::system(fn () => DB::table('role_permissions')->insert([
            'role_id' => $supervisor->role_id, 'permission_code' => 'user.manage', 'entity_id' => $this->entity->id, 'created_at' => now(),
        ]));
        $supervisor->role->flushPermissionCache();

        $this->actingAs($supervisor, 'web')->withSession(Make::webSession($supervisor));
        app(AuthContextBinder::class)->bind($supervisor);

        $offered = collect(LoginAccountResource::assignableRoles())->values()->implode('|');
        expect($offered)->toContain('Weighbridge / Gate Operator')
            ->not->toContain('Owner')
            ->not->toContain('Accountant')
            ->not->toContain('Super Admin');
    });
});

describe('hardening', function () {
    it('allows pages to load only from this site (and Firebase), never to be framed or re-based', function () {
        $csp = (string) $this->get('/admin/login')->headers->get('Content-Security-Policy');
        expect($csp)->toContain("default-src 'self'")->toContain("frame-ancestors 'none'")->toContain("object-src 'none'")
            ->toContain("base-uri 'self'")->toContain("form-action 'self'")->toContain('connect-src')
            ->not->toContain('*;')->not->toContain('script-src *');
    });

    it('believes a forwarded address only from the configured proxy', function () {
        $this->get('/admin/login', ['X-Forwarded-For' => '198.51.100.9']);
        expect(request()->ip())->toBe('127.0.0.1');   // no proxy configured: the header is ignored
        expect(config('ricemill.security.trusted_proxies'))->toBe([]);
    });
});

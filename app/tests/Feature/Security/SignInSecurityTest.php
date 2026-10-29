<?php

declare(strict_types=1);

use App\Domain\Access\PermissionCatalog;
use App\Domain\Access\RoleTemplates;
use App\Domain\Approvals\Actions\TrustDevice;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Auth\DeviceService;
use App\Domain\Auth\PasskeyService;
use App\Domain\Gate\LiveWeight;
use App\Domain\Security\GeoIp;
use App\Domain\Settings\SettingsService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\LiveWeightPage;
use App\Filament\Pages\MyPasskeys;
use App\Filament\Pages\SignInAttempts;
use App\Filament\Pages\SignInRules;
use App\Filament\Resources\AuthSessionResource\Pages\ListAuthSessions;
use App\Filament\Resources\EntityResource\Pages\EditEntity;
use App\Filament\Widgets\LiveWeightBoard;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Http\Middleware\VerifyAgentSignature;
use App\Models\ApprovalRequest;
use App\Models\AuthSession;
use App\Models\Device;
use App\Models\Entity;
use App\Models\Gate\Scale;
use App\Models\LoginAccount;
use App\Models\Passkey;
use App\Support\Values\UserAgentInfo;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Make;
use Tests\Support\VirtualAuthenticator;

beforeEach(function () {
    $this->entity = Make::entity('SEC');
    Make::reset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function secSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $web = Make::webSession($a);
    $t->actingAs($a, 'web')->withSession($web);
    // As EnsureWebAuthSession does on a real request: this session is the current one.
    app(AuthContextBinder::class)->bind($a, Make::system(fn () => AuthSession::query()->find($web[EnsureWebAuthSession::SESSION_KEY])));
}

function secSignOut(object $t): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    Make::reset();
}

describe('passkeys', function () {
    it('registers a passkey on the device and signs in with it alone, as two factors', function () {
        $owner = Make::account($this->entity, 'SUPER_ADMIN', ['mfa' => true]);
        $device = new VirtualAuthenticator;

        secSignIn($this, $owner);
        $page = Livewire::test(MyPasskeys::class);
        $options = $page->instance()->registrationOptions();
        $page->call('storePasskey', $device->register($options), 'Office PC')->assertNotified('Passkey added. Next time choose "Sign in with a passkey".');
        $stored = Make::system(fn () => Passkey::query()->sole());
        expect($stored->name)->toBe('Office PC')->and($stored->authenticatable_id)->toBe($owner->id)->and($stored->entity_id)->toBe($owner->entity_id);

        secSignOut($this);
        $login = Livewire::test(Login::class);
        $challenge = $login->instance()->passkeyOptions();
        $login->call('passkeyLogin', $device->assert($challenge, $owner->id))->assertHasNoErrors()->assertRedirect();

        $session = Make::system(fn () => AuthSession::query()->where('login_account_id', $owner->id)->where('sign_in_method', 'passkey')->sole());
        expect(auth('web')->id())->toBe($owner->id)
            ->and($session->mfa_passed_at)->not->toBeNull()
            ->and(Make::system(fn () => Passkey::query()->sole()->last_used_at))->not->toBeNull();
    });

    it('refuses an unknown passkey, a replayed challenge and a locked account', function () {
        $owner = Make::account($this->entity, 'SUPER_ADMIN', ['mfa' => true]);
        $device = new VirtualAuthenticator;
        secSignIn($this, $owner);
        $page = Livewire::test(MyPasskeys::class);
        $page->call('storePasskey', $device->register($page->instance()->registrationOptions()), 'Phone');

        // A key the server never saw.
        secSignOut($this);
        $stranger = new VirtualAuthenticator;
        $login = Livewire::test(Login::class);
        $login->call('passkeyLogin', $stranger->assert($login->instance()->passkeyOptions(), $owner->id))->assertHasErrors(['data.username']);
        expect(auth('web')->check())->toBeFalse();

        // A key that only checked presence (no fingerprint, face or PIN) is not enough.
        $login = Livewire::test(Login::class);
        $login->call('passkeyLogin', $device->assert($login->instance()->passkeyOptions(), $owner->id, userVerified: false))->assertHasErrors(['data.username']);
        expect(auth('web')->check())->toBeFalse();

        // The same signed answer twice: the challenge was spent the first time.
        $login = Livewire::test(Login::class);
        $answer = $device->assert($login->instance()->passkeyOptions(), $owner->id);
        $login->call('passkeyLogin', $answer)->assertRedirect();
        secSignOut($this);
        Livewire::test(Login::class)->call('passkeyLogin', $answer)->assertHasErrors(['data.username']);

        // A locked account stays locked whatever the key.
        Make::system(fn () => $owner->fresh()->forceFill(['locked_until' => now()->addHour()])->save());
        $login = Livewire::test(Login::class);
        $login->call('passkeyLogin', $device->assert($login->instance()->passkeyOptions(), $owner->id))->assertHasErrors(['data.username']);
        expect(auth('web')->check())->toBeFalse();
    });

    it('lets a person remove their own passkey and an administrator remove anyone\'s', function () {
        $owner = Make::account($this->entity, 'SUPER_ADMIN', ['mfa' => true]);
        $clerk = Make::account($this->entity, 'GATE_OPERATOR');
        $device = new VirtualAuthenticator;
        secSignIn($this, $clerk);
        $page = Livewire::test(MyPasskeys::class);
        $page->call('storePasskey', $device->register($page->instance()->registrationOptions()), 'Gate phone');
        $key = Make::system(fn () => Passkey::query()->sole());

        $other = Make::account($this->entity, 'LAB_TECHNICIAN');
        app(AuthContextBinder::class)->bind($other);
        expect(fn () => app(PasskeyService::class)->remove($key, $other))->toThrow(BusinessRuleViolation::class, 'only your own');

        app(AuthContextBinder::class)->bind($owner);
        app(PasskeyService::class)->remove($key, $owner);
        expect(Make::system(fn () => Passkey::query()->count()))->toBe(0);
    });
});

/** A password sign-in on the real page from the browser that carries this device cookie. */
function secPasswordLogin(LoginAccount $a, string $deviceUid, ?string $code = null): Testable
{
    $page = Livewire::withCookie(Login::DEVICE_COOKIE, $deviceUid)->test(Login::class)
        ->fillForm(['username' => $a->username, 'password' => Make::PASSWORD])->call('authenticate');
    if ($code !== null) {
        $page->fillForm(['code' => $code])->call('authenticate');
    }

    return $page;
}

describe('trusted devices', function () {
    it('stops a new device until the owner approves it, when the rule is "everyone"', function () {
        $owner = Make::account($this->entity, 'SUPER_ADMIN', ['mfa' => true]);
        $clerk = Make::account($this->entity, 'GATE_OPERATOR');
        Make::system(fn () => app(SettingsService::class)->put(SettingsService::TRUSTED_DEVICES, 'everyone', $this->entity->id));
        $pc = (string) Str::uuid();

        secPasswordLogin($clerk, $pc)->assertHasFormErrors(['username']);
        expect(auth('web')->check())->toBeFalse();
        $request = Make::system(fn () => ApprovalRequest::query()->where('action', TrustDevice::KEY)->sole());

        // Nobody approves their own device; the owner does.
        app(AuthContextBinder::class)->bind($clerk);
        expect(app(ApprovalService::class)->canDecide($clerk, $request))->toBeFalse();
        app(AuthContextBinder::class)->bind($owner);
        app(ApprovalService::class)->approve($request, $owner, 'Gate office PC');
        Make::reset();

        secPasswordLogin($clerk, $pc)->assertHasNoFormErrors()->assertRedirect();
        expect(auth('web')->id())->toBe($clerk->id)
            ->and(Make::system(fn () => Device::query()->where('device_uid', $pc)->sole()->isTrusted()))->toBeTrue();
    });

    it('leaves attendance-only employees out, applies "selected" per account, and does nothing when off', function () {
        $employee = Make::account($this->entity, 'EMPLOYEE');
        $clerk = Make::account($this->entity, 'GATE_OPERATOR');
        $flagged = Make::account($this->entity, 'LAB_TECHNICIAN');
        Make::system(fn () => $flagged->forceFill(['require_trusted_device' => true])->save());
        $devices = app(DeviceService::class);
        $settings = fn (string $mode) => Make::system(fn () => app(SettingsService::class)->put(SettingsService::TRUSTED_DEVICES, $mode, $this->entity->id));

        $settings('everyone');
        expect($devices->requiresTrustedDevice($employee))->toBeFalse()->and($devices->requiresTrustedDevice($clerk))->toBeTrue();
        $settings('selected');
        expect($devices->requiresTrustedDevice($clerk))->toBeFalse()->and($devices->requiresTrustedDevice($flagged))->toBeTrue();
        $settings('off');
        expect($devices->requiresTrustedDevice($flagged))->toBeFalse();
        secPasswordLogin($clerk, (string) Str::uuid())->assertHasNoFormErrors();
    });

    it('lets the owner set the rule on screen, and keeps others off that screen', function () {
        $owner = Make::account($this->entity, 'SUPER_ADMIN', ['mfa' => true]);
        secSignIn($this, Make::account($this->entity, 'GATE_OPERATOR'));
        $this->get(SignInRules::getUrl())->assertForbidden();

        secSignIn($this, $owner);
        Livewire::test(SignInRules::class)->assertSet('data.trusted_devices', 'off')
            ->fillForm(['trusted_devices' => 'everyone'])->call('save')->assertNotified('Sign-in rules saved. They apply from the next sign-in.');
        expect(app(SettingsService::class)->trustedDevicesMode($this->entity->id))->toBe('everyone');
    });
});

describe('sessions', function () {
    it('records the browser, system and network of a sign-in, and the browser position when given', function () {
        $clerk = Make::account($this->entity, 'GATE_OPERATOR');
        $this->withServerVariables(['HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36']);
        secPasswordLogin($clerk, (string) Str::uuid())->assertRedirect();
        $session = Make::system(fn () => AuthSession::query()->sole());
        expect($session->sign_in_method)->toBe('password')
            ->and($session->location_source)->toBe('local')            // 127.0.0.1: the mill's own network
            ->and($session->placeLabel())->toBe('Mill network (local address)');

        $clerk = Make::system(fn () => $clerk->fresh(['role', 'person', 'entity']));
        secSignIn($this, $clerk);
        $this->postJson(route('filament.admin.session-location'), ['latitude' => 29.5405, 'longitude' => 71.6315, 'accuracy' => 25])->assertOk();
        $own = Make::system(fn () => AuthSession::query()->where('login_account_id', $clerk->id)->where('location_source', 'browser')->sole());
        expect($own->location_source)->toBe('browser')->and($own->latitude)->toBe(29.5405)->and($own->mapUrl())->toContain('openstreetmap.org');
        $this->postJson(route('filament.admin.session-location'), ['latitude' => 300, 'longitude' => 0])->assertUnprocessable();

        expect(UserAgentInfo::parse('Mozilla/5.0 (Linux; Android 14; SM-A146B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36')->label())
            ->toBe('Chrome 140 on Android 14')
            ->and(app(GeoIp::class)->lookup('192.168.1.20')['source'])->toBe('local')
            ->and(app(GeoIp::class)->lookup('not an ip'))->toBeNull();
    });

    it('tells the person and the owner about a sign-in from a new device', function () {
        $owner = Make::account($this->entity, 'SUPER_ADMIN', ['mfa' => true]);
        $clerk = Make::account($this->entity, 'GATE_OPERATOR');
        secPasswordLogin($clerk, (string) Str::uuid())->assertRedirect();      // first sign-in ever: not news
        secSignOut($this);
        expect(Make::system(fn () => $clerk->fresh()->notifications()->count()))->toBe(0);

        secPasswordLogin($clerk, (string) Str::uuid())->assertRedirect();      // another browser
        expect(Make::system(fn () => $clerk->fresh()->notifications()->sole()->data['title']))->toBe('New sign-in to your account')
            ->and(Make::system(fn () => $owner->fresh()->notifications()->count()))->toBe(1);
    });

    it('signs out all other sessions of the person, keeps this one, and lists failed sign-ins for the owner', function () {
        $owner = Make::account($this->entity, 'SUPER_ADMIN', ['mfa' => true]);
        $clerk = Make::account($this->entity, 'GATE_OPERATOR');
        Livewire::test(Login::class)->fillForm(['username' => $clerk->username, 'password' => 'wrong'])->call('authenticate');
        foreach ([1, 2] as $i) {
            Make::webSession(Make::system(fn () => $clerk->fresh(['role', 'person', 'entity'])));
        }
        $clerk = Make::system(fn () => $clerk->fresh(['role', 'person', 'entity']));
        secSignIn($this, $clerk);
        Livewire::test(ListAuthSessions::class)->callAction('others')->assertNotified('2 other session(s) signed out.');
        expect(Make::system(fn () => AuthSession::query()->where('login_account_id', $clerk->id)->whereNull('revoked_at')->count()))->toBe(1);
        $this->get(SignInAttempts::getUrl())->assertForbidden();

        secSignIn($this, $owner);
        Livewire::test(SignInAttempts::class)->assertSee($clerk->username)->assertSee('Wrong password');
    });
});

describe('owner rights', function () {
    it('gives the owner every mill permission, including any added later, but not other firms, the narrowing ones or the Office Desk', function () {
        $missing = array_values(array_diff(PermissionCatalog::codes(), RoleTemplates::get(RoleTemplates::SUPER_ADMIN)['permissions']));
        sort($missing);
        expect($missing)->toBe(['commission.view_own', 'deal.view_own', 'entity.manage', 'office.restore', 'office.view', 'portal.view_own']);
    });

    it('lets the owner edit the own mill details but not its status, nor another firm', function () {
        $owner = Make::account($this->entity, 'SUPER_ADMIN', ['mfa' => true]);
        $other = Make::entity('OTH');
        secSignIn($this, $owner);
        Livewire::test(EditEntity::class, ['record' => $this->entity->id])
            ->assertFormFieldIsDisabled('status')->assertFormFieldIsDisabled('code')
            ->fillForm(['phone' => '0300-6834588', 'city' => 'Lodhran'])->call('save')->assertHasNoFormErrors();
        expect(Make::system(fn () => Entity::query()->find($this->entity->id)->city))->toBe('Lodhran')
            ->and($owner->can('update', $this->entity))->toBeTrue()
            ->and($owner->can('update', $other))->toBeFalse();
    });
});

describe('live weight', function () {
    it('takes the gate agent reading and shows it live, then "no signal" when it stops', function () {
        $owner = Make::account($this->entity, 'SUPER_ADMIN', ['mfa' => true]);
        $agent = Make::account($this->entity, 'GATE_OPERATOR', ['username' => 'sec.agent']);
        app(AuthContextBinder::class)->bind($owner);
        $scale = Scale::query()->create(['code' => 'WB1', 'name' => 'Main weighbridge', 'capacity_kg' => '60000', 'resolution_kg' => '10']);
        ['secret' => $secret] = app(DeviceService::class)->registerAgent($agent, 'agent-pc-live-0001', 'Weighbridge PC');
        Make::reset();
        $access = $this->postJson('/api/v1/auth/login', ['username' => 'sec.agent', 'password' => Make::PASSWORD, 'device_uid' => 'agent-pc-live-0001', 'platform' => 'agent'])
            ->assertOk()->json('access_token');
        $send = function (array $body) use ($secret, $access) {
            $json = json_encode($body);
            $ts = (string) time();
            $nonce = (string) Str::uuid();

            return $this->call('POST', '/api/v1/gate/live-weight', [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer '.$access, 'HTTP_X_DEVICE_ID' => 'agent-pc-live-0001', 'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json', 'HTTP_X_TIMESTAMP' => $ts, 'HTTP_X_NONCE' => $nonce,
                'HTTP_X_SIGNATURE' => hash_hmac('sha256', VerifyAgentSignature::canonical($ts, $nonce, 'POST', '/api/v1/gate/live-weight', $json), $secret),
            ], $json);
        };

        $send(['scale_id' => $scale->id, 'kg' => '24560', 'stable' => true])->assertOk();
        $send(['scale_id' => $scale->id, 'kg' => '999999', 'stable' => true])->assertUnprocessable();   // beyond the scale

        app(AuthContextBinder::class)->bind($owner);
        expect(app(LiveWeight::class)->current($scale))->toMatchArray(['kg' => 24560.0, 'stable' => true, 'live' => true]);

        secSignIn($this, $owner);
        Livewire::test(LiveWeightBoard::class)->assertSee('24,560')->assertSee('STABLE')->assertSee('614.00 maund');
        $this->get(LiveWeightPage::getUrl())->assertOk();

        $this->travel(10)->seconds();
        Livewire::test(LiveWeightBoard::class)->assertSee('NO SIGNAL');
    });

    it('refuses readings from logins that cannot capture weights', function () {
        $lab = Make::account($this->entity, 'LAB_TECHNICIAN');
        app(AuthContextBinder::class)->bind($lab);
        expect(fn () => app(LiveWeight::class)->record(['scale_id' => (string) Str::uuid(), 'kg' => 10, 'stable' => true]))
            ->toThrow(BusinessRuleViolation::class, 'weighment.capture');
    });
});

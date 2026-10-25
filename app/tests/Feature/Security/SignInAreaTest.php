<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Auth\DeviceService;
use App\Domain\Auth\Exceptions\AuthenticationFailed;
use App\Domain\Auth\LoginService;
use App\Domain\Security\SignInArea;
use App\Domain\Settings\SettingsService;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\SignInRules;
use App\Filament\Resources\Platform\CustomerResource\Pages\ViewCustomer;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Http\Middleware\VerifyAgentSignature;
use App\Models\AuthSession;
use App\Models\Entity;
use App\Models\Hr\Site;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Make;

// The mill at Lodhran, a 300 m circle; a point inside it, and one 9 km away.
const MILL_LAT = 29.534000;
const MILL_LNG = 71.632400;
const AWAY_LAT = 29.600000;
const AWAY_LNG = 71.690000;

function millSite(Entity $mill): Site
{
    return Make::system(fn () => app(TenantContext::class)->runInEntity($mill->id, fn () => Site::query()->create([
        'code' => 'MAIN', 'name' => 'Main mill', 'kind' => 'mill', 'latitude' => MILL_LAT, 'longitude' => MILL_LNG, 'radius_m' => 300, 'status' => 'active',
    ])));
}

function areaOn(Entity $mill): void
{
    Make::system(fn () => app(SettingsService::class)->put(SettingsService::SIGN_IN_AREA, 'on', $mill->id));
}

function areaRefusal(LoginAccount $a, mixed $lat, mixed $lng): ?string
{
    try {
        app(LoginService::class)->assertInsideArea($a, $lat, $lng, 20, '127.0.0.1');
    } catch (AuthenticationFailed $e) {
        expect($e->publicCode)->toBe(AuthenticationFailed::OUTSIDE_AREA);

        return $e->reason;
    }

    return null;
}

function placeSession(LoginAccount $a, array $session, float $lat, float $lng, int $minutesAgo = 0): void
{
    Make::system(fn () => AuthSession::query()->whereKey($session[EnsureWebAuthSession::SESSION_KEY])
        ->update(['latitude' => $lat, 'longitude' => $lng, 'location_accuracy_m' => 20, 'location_at' => now()->subMinutes($minutesAgo)]));
}

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->mill = Make::entity('GEO');
    millSite($this->mill);
});

it('lets anyone sign in from anywhere until the mill switches it on', function () {
    $clerk = Make::account($this->mill, 'GATE_OPERATOR');
    expect(areaRefusal($clerk, null, null))->toBeNull();
});

it('refuses sign-in without a position or outside the mill, and lets it in inside', function () {
    areaOn($this->mill);
    $clerk = Make::account($this->mill, 'GATE_OPERATOR');

    expect(areaRefusal($clerk, null, null))->toBe(SignInArea::LOCATION_NEEDED)
        ->and(areaRefusal($clerk, AWAY_LAT, AWAY_LNG))->toBe(SignInArea::OUTSIDE)
        ->and(areaRefusal($clerk, MILL_LAT + 0.001, MILL_LNG))->toBeNull();   // ~110 m from the centre

    // The sign-in page: refused outside, with the message; inside it signs in and the session keeps the position.
    Livewire::test(Login::class)
        ->fillForm(['username' => $clerk->username, 'password' => Make::PASSWORD, 'latitude' => AWAY_LAT, 'longitude' => AWAY_LNG, 'accuracy' => 15])
        ->call('authenticate')->assertHasFormErrors(['username' => 'You are outside the mill. Sign-in is allowed only inside the mill\'s premises.']);
    Livewire::test(Login::class)
        ->fillForm(['username' => $clerk->username, 'password' => Make::PASSWORD])
        ->call('authenticate')->assertDispatched('rm-need-location')->assertHasFormErrors(['username']);
    Livewire::test(Login::class)
        ->fillForm(['username' => $clerk->username, 'password' => Make::PASSWORD, 'latitude' => MILL_LAT, 'longitude' => MILL_LNG, 'accuracy' => 15])
        ->call('authenticate')->assertHasNoFormErrors()->assertRedirect();
    expect((float) Make::system(fn () => AuthSession::query()->where('login_account_id', $clerk->id)->sole()->latitude))->toBe(MILL_LAT);
});

it('ends a signed-in session that leaves the mill or stops sending its position', function () {
    areaOn($this->mill);
    $clerk = Make::account($this->mill, 'GATE_OPERATOR');

    $session = Make::webSession($clerk);
    placeSession($clerk, $session, MILL_LAT, MILL_LNG);
    $this->actingAs($clerk, 'web')->withSession($session)->get('/admin')->assertOk();

    // The page reports a position outside: the session ends and the page goes back to sign-in.
    $this->postJson(route('filament.admin.session-location'), ['latitude' => AWAY_LAT, 'longitude' => AWAY_LNG, 'accuracy' => 10])
        ->assertJson(['ok' => false, 'signed_out' => true]);

    app('auth')->forgetGuards();
    $this->flushSession();
    $stale = Make::webSession($clerk);
    placeSession($clerk, $stale, MILL_LAT, MILL_LNG, minutesAgo: SignInArea::STALE_MINUTES + 5);
    $this->actingAs($clerk, 'web')->withSession($stale)->get('/admin')->assertRedirect('/admin/login');
    $this->get('/admin/login')->assertSee('Sign-in needs your location');
});

it('holds an Owner to any of its mills, never the product owner', function () {
    $second = Make::entity('GEO2');
    areaOn($second);
    $owner = Make::owner($this->mill);

    expect(app(SignInArea::class)->mills($owner))->toBe([$second->id])
        ->and(areaRefusal($owner, AWAY_LAT, AWAY_LNG))->toBe(SignInArea::OUTSIDE);   // the second mill has no site: nowhere is inside

    $platform = Make::platformAdmin(Make::entity('PLT'));
    areaOn(Make::system(fn () => Entity::query()->findOrFail($platform->entity_id)));
    expect(areaRefusal($platform, null, null))->toBeNull();
});

it('holds the mobile app to the mill too: at sign-in and on every call', function () {
    areaOn($this->mill);
    $clerk = Make::account($this->mill, 'GATE_OPERATOR');
    $body = ['username' => $clerk->username, 'password' => Make::PASSWORD, 'device_uid' => 'phone-0001-abcdef', 'device_name' => 'Phone', 'platform' => 'android'];

    $this->postJson('/api/v1/auth/login', $body)->assertStatus(403)->assertJsonPath('error', AuthenticationFailed::OUTSIDE_AREA);
    $pair = $this->postJson('/api/v1/auth/login', $body + ['latitude' => MILL_LAT, 'longitude' => MILL_LNG, 'accuracy' => 10])->assertOk()->json();

    $headers = ['Authorization' => 'Bearer '.$pair['access_token'], 'X-Device-Id' => 'phone-0001-abcdef', 'Accept' => 'application/json'];
    $this->getJson('/api/v1/auth/me', $headers + ['X-Location' => MILL_LAT.','.MILL_LNG.',10'])->assertOk();
    Make::reset();
    $this->getJson('/api/v1/auth/me', $headers + ['X-Location' => AWAY_LAT.','.AWAY_LNG.',10'])->assertUnauthorized()
        ->assertJson(['reason' => AuthenticationFailed::OUTSIDE_AREA]);
});

it('excuses a gate agent only when it signs its sign-in; its device ID alone opens nothing (web or app)', function () {
    areaOn($this->mill);
    $gate = Make::account($this->mill, 'GATE_OPERATOR');
    $uid = 'gate-agent-pc-0001';
    $secret = Make::system(fn () => app(DeviceService::class)->registerAgent($gate, $uid, 'Weighbridge PC'))['secret'];

    // A browser that sets the agent's device cookie, from 9 km away: refused.
    Livewire::withCookie(Login::DEVICE_COOKIE, $uid)->test(Login::class)
        ->fillForm(['username' => $gate->username, 'password' => Make::PASSWORD, 'latitude' => AWAY_LAT, 'longitude' => AWAY_LNG, 'accuracy' => 10])
        ->call('authenticate')->assertHasFormErrors(['username']);
    expect(auth('web')->check())->toBeFalse();

    // The app with the agent's ID but no signature: refused. Signed with the agent's secret: let in (no GPS needed).
    $body = ['username' => $gate->username, 'password' => Make::PASSWORD, 'device_uid' => $uid, 'platform' => 'agent'];
    $this->postJson('/api/v1/auth/login', $body, ['X-Device-Id' => $uid])->assertStatus(403)->assertJsonPath('error', AuthenticationFailed::OUTSIDE_AREA);

    $json = json_encode($body, JSON_THROW_ON_ERROR);
    $ts = (string) time();
    $nonce = 'nonce-'.bin2hex(random_bytes(8));
    $sig = hash_hmac('sha256', VerifyAgentSignature::canonical($ts, $nonce, 'POST', '/api/v1/auth/login', $json), $secret);
    $this->call('POST', '/api/v1/auth/login', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_DEVICE_ID' => $uid,
        'HTTP_X_TIMESTAMP' => $ts, 'HTTP_X_NONCE' => $nonce, 'HTTP_X_SIGNATURE' => $sig,
    ], $json)->assertOk();
});

it('is switched on only with a site and from inside it; the product owner can lift it', function () {
    $empty = Make::entity('GEOX');
    $sa = Make::account($empty, 'SUPER_ADMIN', ['mfa' => true]);
    $session = Make::webSession($sa);
    $this->actingAs($sa, 'web')->withSession($session);
    app(AuthContextBinder::class)->bind($sa);
    Livewire::test(SignInRules::class)->fillForm(['trusted_devices' => 'off', 'sign_in_area' => true])->call('save')
        ->assertNotified('Add the mill\'s site first (HR → Sites: its position and radius).');
    expect(app(SignInArea::class)->isOn($empty->id))->toBeFalse();

    app('auth')->forgetGuards();
    $this->flushSession();
    $sa = Make::account($this->mill, 'SUPER_ADMIN', ['mfa' => true]);
    $session = Make::webSession($sa);
    placeSession($sa, $session, AWAY_LAT, AWAY_LNG);
    $this->actingAs($sa, 'web')->withSession($session);
    app(AuthContextBinder::class)->bind($sa, Make::system(fn () => AuthSession::query()->find($session[EnsureWebAuthSession::SESSION_KEY])));
    Livewire::test(SignInRules::class)->fillForm(['trusted_devices' => 'off', 'sign_in_area' => true])->call('save')
        ->assertNotified('Switch this on from inside the mill: you are outside its sites now.');
    placeSession($sa, $session, MILL_LAT, MILL_LNG);
    app(AuthContextBinder::class)->bind($sa, Make::system(fn () => AuthSession::query()->find($session[EnsureWebAuthSession::SESSION_KEY])));
    Livewire::test(SignInRules::class)->fillForm(['trusted_devices' => 'off', 'sign_in_area' => true])->call('save')
        ->assertNotified('Sign-in rules saved. They apply from the next sign-in.');
    expect(app(SignInArea::class)->isOn($this->mill->id))->toBeTrue();

    // The product owner lifts it from the customer's page.
    app('auth')->forgetGuards();
    $this->flushSession();
    $platform = Make::platformAdmin(Make::entity('PLT'));
    $this->actingAs($platform, 'web')->withSession(Make::webSession($platform));
    app(AuthContextBinder::class)->bind($platform);
    Livewire::test(ViewCustomer::class, ['record' => $this->mill->customer_id])->callAction('areaOff')
        ->assertNotified('"Only inside the mill" is off for all its mills.');
    expect(app(SignInArea::class)->isOn($this->mill->id))->toBeFalse();
});

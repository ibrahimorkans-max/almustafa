<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Security\AccessReview;
use App\Domain\Security\ReverseGeocoder;
use App\Filament\Pages\AccessReviewPage;
use App\Filament\Resources\AuthSessionResource\Pages\ListAuthSessions;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Models\AuthSession;
use App\Models\Hr\Site;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\Make;

beforeEach(function () {
    $this->entity = Make::entity('LOC');
    Make::reset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function locSignIn(object $t, LoginAccount $a): AuthSession
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $web = Make::webSession($a);
    $t->actingAs($a, 'web')->withSession($web);
    $session = Make::system(fn () => AuthSession::query()->find($web[EnsureWebAuthSession::SESSION_KEY]));
    app(AuthContextBinder::class)->bind($a, $session);

    return $session;
}

const NOMINATIM_LODHRAN = [
    'display_name' => 'Multan Road, Model Town, Lodhran, Lodhran District, Punjab, 59320, Pakistan',
    'address' => ['road' => 'Multan Road', 'neighbourhood' => 'Model Town', 'city' => 'Lodhran', 'state_district' => 'Lodhran District',
        'state' => 'Punjab', 'postcode' => '59320', 'country' => 'Pakistan', 'country_code' => 'pk'],
];

it('lets the browser share its position (the security headers no longer block it)', function () {
    $headers = (string) $this->get('/admin/login')->headers->get('Permissions-Policy');
    expect($headers)->toContain('geolocation=(self)')->not->toContain('geolocation=()');
});

it('records the exact area, city, region and country of a session, and keeps the IP place apart', function () {
    config(['ricemill.geocoder.driver' => 'nominatim']);
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response(NOMINATIM_LODHRAN)]);
    $clerk = Make::account($this->entity, 'GATE_OPERATOR');
    $session = locSignIn($this, $clerk);
    Make::system(fn () => $session->forceFill(['city' => 'Lahore', 'region' => 'Punjab', 'country' => 'PK', 'ip_city' => 'Lahore', 'ip_region' => 'Punjab', 'ip_country' => 'PK', 'location_source' => 'ip'])->save());

    $this->postJson(route('filament.admin.session-location'), ['latitude' => 29.540512, 'longitude' => 71.631544, 'accuracy' => 12])
        ->assertOk()->assertJson(['place' => 'Multan Road, Model Town, Lodhran, Punjab, Pakistan']);

    $s = Make::system(fn () => $session->fresh());
    expect($s->area)->toBe('Multan Road, Model Town')->and($s->city)->toBe('Lodhran')->and($s->region)->toBe('Punjab')
        ->and($s->country)->toBe('PK')->and($s->country_name)->toBe('Pakistan')->and($s->address)->toContain('59320')
        ->and($s->location_source)->toBe('browser')->and($s->location_accuracy_m)->toBe(12)->and($s->location_at)->not->toBeNull()
        ->and($s->ipPlaceLabel())->toBe('Lahore, Punjab, PK')
        ->and($s->mapUrl())->toContain('mlat=29.540512')->toContain('#map=17/');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'lat=29.540512') && $request->hasHeader('User-Agent'));

    // The same spot again comes from the cache, not the internet.
    $this->postJson(route('filament.admin.session-location'), ['latitude' => 29.540512, 'longitude' => 71.631544, 'accuracy' => 10])->assertOk();
    Http::assertSentCount(1);

    $admin = Make::account($this->entity, 'SUPER_ADMIN');
    locSignIn($this, $admin);
    Livewire::test(ListAuthSessions::class)->set('tableFilters.state.value', 'all')
        ->assertSee('Multan Road, Model Town, Lodhran, Punjab, Pakistan')->assertSee('Exact (device GPS / Wi-Fi) ±10 m')->assertSee('IP: Lahore, Punjab, PK');
});

it('names the nearest mill site when there is no internet (mill server)', function () {
    config(['ricemill.geocoder.driver' => 'off']);
    Http::fake();
    Make::system(fn () => Site::query()->forceCreate(['entity_id' => $this->entity->id, 'code' => 'MILL', 'name' => 'Main mill', 'kind' => 'mill',
        'latitude' => '29.5405000', 'longitude' => '71.6315000', 'radius_m' => 300, 'status' => 'active']));
    $clerk = Make::account($this->entity, 'GATE_OPERATOR');
    $session = locSignIn($this, $clerk);

    $this->postJson(route('filament.admin.session-location'), ['latitude' => 29.5410, 'longitude' => 71.6315, 'accuracy' => 30])->assertOk();
    $s = Make::system(fn () => $session->fresh());
    expect($s->area)->toBe('At Main mill (56 m)')->and($s->placeLabel())->toStartWith('At Main mill (56 m)');
    Http::assertNothingSent();

    // Far from every site and no internet: the coordinates are kept, the place stays the IP's.
    $this->postJson(route('filament.admin.session-location'), ['latitude' => 31.5204, 'longitude' => 74.3587])->assertOk();
    expect(Make::system(fn () => $session->fresh())->area)->toBeNull();
});

it('reads Nominatim addresses of villages and towns', function () {
    $village = ReverseGeocoder::fromNominatim(['village' => 'Basti Maluk', 'county' => 'Multan', 'state' => 'Punjab', 'country' => 'Pakistan', 'country_code' => 'pk']);
    expect($village)->toMatchArray(['area' => null, 'city' => 'Basti Maluk', 'region' => 'Punjab', 'country' => 'PK', 'country_name' => 'Pakistan']);

    $town = ReverseGeocoder::fromNominatim(['suburb' => 'Gulberg III', 'city' => 'Lahore', 'state' => 'Punjab', 'country_code' => 'pk']);
    expect($town)->toMatchArray(['area' => 'Gulberg III', 'city' => 'Lahore', 'country_name' => 'Pakistan']);

    expect(ReverseGeocoder::countryName('AE'))->toBe('United Arab Emirates');
});

it('shows on the access review where each person is: exact place, IP place, and who is online now', function () {
    $clerk = Make::account($this->entity, 'GATE_OPERATOR');
    $session = locSignIn($this, $clerk);
    Make::system(fn () => $session->forceFill([
        'location_source' => 'browser', 'latitude' => 29.540512, 'longitude' => 71.631544, 'location_accuracy_m' => 12, 'location_at' => now(),
        'area' => 'Multan Road, Model Town', 'city' => 'Lodhran', 'region' => 'Punjab', 'country' => 'PK', 'country_name' => 'Pakistan',
        'address' => 'Multan Road, Model Town, Lodhran, Punjab, 59320, Pakistan', 'ip_city' => 'Lahore', 'ip_region' => 'Punjab', 'ip_country' => 'PK',
        'last_seen_at' => now(),
    ])->save());
    $idle = Make::account($this->entity, 'ACCOUNTANT');

    $admin = Make::account($this->entity, 'SUPER_ADMIN');
    locSignIn($this, $admin);
    $rows = collect(app(AccessReview::class)->rows())->keyBy('username');
    expect($rows[$clerk->username])->toMatchArray([
        'online' => 'online now', 'location' => 'Multan Road, Model Town, Lodhran, Punjab, Pakistan', 'location_exact' => true,
        'ip_location' => 'Lahore, Punjab, PK', 'coordinates' => '29.540512, 71.631544',
    ])->and($rows[$clerk->username]['location_detail'])->toStartWith('Exact (GPS / Wi-Fi) ±12 m')
        ->and($rows[$idle->username])->toMatchArray(['online' => 'offline', 'location' => 'no sign-in in 90 days']);

    Livewire::test(AccessReviewPage::class)->set('show', 'online')
        ->assertSee('Multan Road, Model Town, Lodhran, Punjab, Pakistan')->assertSee('IP: Lahore, Punjab, PK')->assertSee('online now')
        ->assertDontSee($idle->username)
        ->set('show', 'all')->assertSee($idle->username)->assertSee('no sign-in in 90 days');

    expect(app(AccessReview::class)->csv())->toContain('location_detail')->toContain('29.540512, 71.631544');
});

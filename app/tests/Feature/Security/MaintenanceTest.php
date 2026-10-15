<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Security\MaintenanceService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\SecurityCentre;
use App\Models\AuditLog;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Make;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->entity = Make::entity('MNT');
    $this->owner = Make::account($this->entity, 'SUPER_ADMIN');
    $this->sa = Make::owner($this->entity);
});

function scheduleAs(object $sa, string $message = 'Updating the system, back soon.', ?CarbonInterface $start = null, ?CarbonInterface $end = null): array
{
    app(AuthContextBinder::class)->bind($sa);
    $r = app(MaintenanceService::class)->schedule($message, $start ?? now(), $end ?? now()->addHours(2));
    Make::reset();

    return $r;
}

it('answers the API with 503 and Retry-After, and the web with an honest page', function () {
    $pair = Make::apiTokens($this->owner);
    scheduleAs($this->sa, 'Server move, back at 6 PM.', now(), now()->addHour());

    $api = $this->getJson('/api/v1/auth/me', Make::apiHeaders($pair))->assertStatus(503)
        ->assertJson(['error' => 'maintenance', 'message' => 'Server move, back at 6 PM.']);
    expect((int) $api->headers->get('Retry-After'))->toBeGreaterThan(3500)->toBeLessThanOrEqual(3600);

    $web = $this->get('/admin/login')->assertStatus(503)->assertSee('Server move, back at 6 PM.')->assertSee('Expected back at');
    expect($web->headers->has('Retry-After'))->toBeTrue();
});

it('keeps health checks and document verification up', function () {
    scheduleAs($this->sa);
    $this->get('/health')->assertStatus(503)->assertJsonStructure(['status', 'checks']); // health's own verdict, not the maintenance page
    $this->get('/verify')->assertOk();
});

it('lets the Super Admin through with the secret link, and nobody with a wrong one', function () {
    $r = scheduleAs($this->sa);

    $this->get('/maintenance/bypass/wrong-token')->assertNotFound();
    $res = $this->get('/maintenance/bypass/'.$r['bypass_token'])->assertRedirect('/admin');
    $cookie = collect($res->headers->getCookies())->first(fn ($c) => $c->getName() === MaintenanceService::BYPASS_COOKIE);
    expect($cookie)->not->toBeNull()->and($cookie->isHttpOnly())->toBeTrue();

    $this->withUnencryptedCookie(MaintenanceService::BYPASS_COOKIE, $r['bypass_token'])->get('/admin/login')->assertOk();
    expect(Make::system(fn () => DB::table('maintenance_windows')->value('bypass_hash')))->toBe(hash('sha256', $r['bypass_token']));
});

it('starts and ends on schedule, and can be ended early', function () {
    $r = scheduleAs($this->sa, 'Planned upgrade tonight.', now()->addHour(), now()->addHours(2));
    $this->get('/admin/login')->assertOk();

    $this->travel(61)->minutes();
    Cache::flush();
    $this->get('/admin/login')->assertStatus(503);

    app(AuthContextBinder::class)->bind($this->sa);
    app(MaintenanceService::class)->end($r['id']);
    Make::reset();
    $this->get('/admin/login')->assertOk();
    expect(Make::system(fn () => AuditLog::query()->whereIn('action', ['security.maintenance.scheduled', 'security.maintenance.ended'])->count()))->toBe(2);
});

it('is for Super Admins only and needs a sensible window', function () {
    app(AuthContextBinder::class)->bind($this->owner);
    expect(fn () => app(MaintenanceService::class)->schedule('Owner tries', now(), now()->addHour()))->toThrow(BusinessRuleViolation::class, 'Only an Owner');
    app(AuthContextBinder::class)->bind($this->sa);
    expect(fn () => app(MaintenanceService::class)->schedule('Backwards', now(), now()->subHour()))->toThrow(BusinessRuleViolation::class, 'end time')
        ->and(fn () => app(MaintenanceService::class)->schedule('Too long', now(), now()->addDays(2)))->toThrow(BusinessRuleViolation::class, '24 hours');
});

it('is scheduled from the Security centre, which hands over the link once', function () {
    $this->actingAs($this->sa, 'web')->withSession(Make::webSession($this->sa));
    app(AuthContextBinder::class)->bind($this->sa);
    Livewire::test(SecurityCentre::class)
        ->callAction('maintenance', ['message' => 'Database upgrade', 'starts_at' => now()->addDay()->format('Y-m-d H:i'), 'ends_at' => now()->addDay()->addHour()->format('Y-m-d H:i')])
        ->assertNotified('Maintenance scheduled.');
    Livewire::test(SecurityCentre::class)->assertSee('Database upgrade')->assertActionVisible('endMaintenance');
});

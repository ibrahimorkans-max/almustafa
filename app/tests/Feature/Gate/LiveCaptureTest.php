<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Gate\GateService;
use App\Domain\Gate\LiveCapture;
use App\Domain\Gate\LiveWeight;
use App\Domain\Gate\WeighmentChecks;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\ConnectWeighbridge;
use App\Filament\Resources\Gate\WeighmentCheckResource;
use App\Filament\Resources\Gate\WeighmentCheckResource\Pages\ListWeighmentChecks;
use App\Filament\Widgets\LiveWeightBoard;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Models\AuthSession;
use App\Models\Gate\GateEntry;
use App\Models\Gate\Weighment;
use App\Models\Gate\WeighmentCheck;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new GateFixtures(Make::entity('LWC'));
    $this->sauda = $this->f->confirmedSauda('100000');
    $this->operator = Make::account($this->f->entity, 'GATE_OPERATOR');
    $this->f->as($this->operator);
    $this->entry = app(GateService::class)->arrive(['registration_no' => 'MNA 21 1234', 'sauda_id' => $this->sauda->id, 'bag_count' => 400]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function lwcSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $web = Make::webSession($a);
    $t->actingAs($a, 'web')->withSession($web);
    app(AuthContextBinder::class)->bind($a, Make::system(fn () => AuthSession::query()->find($web[EnsureWebAuthSession::SESSION_KEY])));
}

/** The gate agent sending readings, one a second. */
function lwcFeed(object $t, array $kgs, bool $stable = true): void
{
    $t->f->as($t->operator);
    foreach ($kgs as $kg) {
        app(LiveWeight::class)->record(['scale_id' => $t->f->scale->id, 'kg' => $kg, 'stable' => $stable]);
        $t->travel(1)->seconds();
    }
}

it('lists vehicles waiting for a weight with the weight they need next', function () {
    $waiting = app(LiveCapture::class)->waiting();

    expect($waiting)->toHaveCount(1)
        ->and($waiting[0])->toMatchArray(['id' => $this->entry->id, 'kind' => 'gross'])
        ->and($waiting[0]['label'])->toContain('MNA211234');
});

it('records the gross weight from a stable live reading with the readings as evidence', function () {
    lwcFeed($this, [25470, 25480, 25480, 25480, 25480, 25480]);

    $w = app(LiveCapture::class)->record($this->entry, $this->f->scale);

    expect($w->kind)->toBe('gross')
        ->and($w->weight_kg)->toBe('25480.000')
        ->and($w->flags)->toContain('live_screen')->toContain('no_photo')->not->toContain('stability_unverified')
        ->and($w->readings['via'])->toBe('live_screen')
        ->and($w->readings['samples'])->toHaveCount((int) config('ricemill.gate.min_stable_samples'))
        ->and($w->created_by)->toBe($this->operator->id)
        ->and($this->entry->fresh()->status)->toBe('gross_weighed')
        ->and(app(LiveCapture::class)->waiting())->toHaveCount(0);   // the lab comes next, not the scale
});

it('refuses while the weight moves, without a signal, or before enough readings', function () {
    lwcFeed($this, [25480, 25480]);
    expect(fn () => app(LiveCapture::class)->record($this->entry, $this->f->scale))->toThrow(BusinessRuleViolation::class, 'Not enough readings');

    lwcFeed($this, [24000, 25480, 25100, 25480, 25480, 25480]);
    expect(fn () => app(LiveCapture::class)->record($this->entry, $this->f->scale))->toThrow(BusinessRuleViolation::class, 'still moving');

    lwcFeed($this, array_fill(0, 6, 25480), stable: false);
    expect(fn () => app(LiveCapture::class)->record($this->entry, $this->f->scale))->toThrow(BusinessRuleViolation::class, 'still moving');

    lwcFeed($this, array_fill(0, 6, 25480));
    $this->travel(10)->seconds();
    expect(fn () => app(LiveCapture::class)->record($this->entry, $this->f->scale))->toThrow(BusinessRuleViolation::class, 'No signal');

    expect(Weighment::query()->count())->toBe(0);
});

it('lets the operator record from the Live weight board, and only someone who may capture weights', function () {
    lwcFeed($this, array_fill(0, 6, 25480));
    lwcSignIn($this, $this->operator);

    Livewire::test(LiveWeightBoard::class)->assertSee('Vehicle on the platform')->assertSee('needs gross')
        ->call('recordWeight', $this->f->scale->id)->assertNotified('Choose the vehicle on the platform first.')
        ->set('selected.'.$this->f->scale->id, $this->entry->id)->assertSee('Record gross')
        ->call('recordWeight', $this->f->scale->id)->assertNotified('Gross 25,480 kg recorded for MNA211234.');
    expect(Weighment::query()->where('gate_entry_id', $this->entry->id)->count())->toBe(1);

    lwcSignIn($this, Make::account($this->f->entity, 'LAB_TECHNICIAN'));
    Livewire::test(LiveWeightBoard::class)->assertDontSee('Vehicle on the platform');
});

it('lets the Super Admin check each weight: OK, or a query with a note, never their own', function () {
    lwcFeed($this, array_fill(0, 6, 25480));
    $w = app(LiveCapture::class)->record($this->entry, $this->f->scale);

    lwcSignIn($this, $this->f->owner);
    expect(WeighmentCheckResource::getNavigationBadge())->toBe('1');
    Livewire::test(ListWeighmentChecks::class)->assertCanSeeTableRecords([$w])->assertSee('Live weight screen')
        ->callTableAction('query', $w, ['note' => ''])->assertHasTableActionErrors(['note'])
        ->callTableAction('query', $w, ['note' => 'Truck looked half on the platform'])->assertNotified('Queried.');
    Livewire::test(ListWeighmentChecks::class)->set('tableFilters.state.value', 'queried')->assertCanSeeTableRecords([$w]);
    $this->travel(1)->seconds();
    Livewire::test(ListWeighmentChecks::class)->set('tableFilters.state.value', 'all')->callTableAction('ok', $w, ['note' => 'Checked against the CCTV'])->assertNotified('Marked OK.');

    $checks = WeighmentCheck::query()->where('weighment_id', $w->id)->orderBy('checked_at')->get();
    expect($checks->pluck('decision')->all())->toBe(['query', 'ok'])
        ->and($w->fresh()->lastCheck()->decision)->toBe('ok')
        ->and(WeighmentCheckResource::getNavigationBadge())->toBeNull();

    // Append-only.
    expect(fn () => $checks->first()->forceFill(['note' => 'changed later'])->save())->toThrow(LogicException::class);
    expect(fn () => Make::system(fn () => DB::table('weighment_checks')->update(['note' => 'changed later'])))->toThrow(Exception::class);
});

it('keeps the person who recorded a weight, and staff without the right, from checking it', function () {
    // Staff with the right to check still may not check their own weight…
    $sup = Make::account($this->f->entity, 'SUPERVISOR');
    Make::system(fn () => DB::table('role_permissions')->insertOrIgnore([
        'role_id' => $sup->role_id, 'permission_code' => 'weighment.review', 'entity_id' => $this->f->entity->id, 'created_at' => now(),
    ]));
    $sup->role->flushPermissionCache();
    $this->f->as($sup);
    $w = app(GateService::class)->recordWeighment($this->entry, 'gross', '25480', $this->f->scale, GateFixtures::readings('25480'), null);
    expect(fn () => app(WeighmentChecks::class)->decide($w, 'ok', null))->toThrow(BusinessRuleViolation::class, 'someone else');

    // …while the mill's Super Admin may check a weight they recorded themselves.
    $this->f->as($this->f->owner);
    $tare = app(GateService::class)->recordWeighment($this->entry, 'gross', '25490', $this->f->scale, GateFixtures::readings('25490'), null);
    expect(app(WeighmentChecks::class)->decide($tare, 'ok', null)->decision)->toBe('ok');

    $this->f->as($this->operator);
    expect(fn () => app(WeighmentChecks::class)->decide($w, 'ok', null))->toThrow(BusinessRuleViolation::class, 'weighment.review');

    lwcSignIn($this, $this->operator);
    $this->get(WeighmentCheckResource::getUrl())->assertForbidden();
    $this->get(ConnectWeighbridge::getUrl())->assertForbidden();

    lwcSignIn($this, $this->f->owner2);
    expect(app(WeighmentChecks::class)->decide($w, 'ok', null)->decision)->toBe('ok');
});

it('shows the connection steps and whether each weighbridge sends readings', function () {
    lwcSignIn($this, $this->f->owner);
    $this->get(ConnectWeighbridge::getUrl())->assertOk()->assertSee('Check the indicator has a data port')->assertSee('Switch on continuous output')->assertSee('No signal');

    lwcFeed($this, [25480]);
    lwcSignIn($this, $this->f->owner);
    $this->get(ConnectWeighbridge::getUrl())->assertOk()->assertSee('Connected')->assertSee('last reading 25,480 kg');
});

it('shows the readings of an Owner working across all firms', function () {
    lwcFeed($this, array_fill(0, 2, 25480));
    $owner = Make::owner($this->f->entity);
    app(AuthContextBinder::class)->bind($owner, null, null, AuthContextBinder::ALL_ENTITIES);

    expect(collect(app(LiveWeight::class)->all())->firstWhere('scale_id', $this->f->scale->id))->toMatchArray(['kg' => 25480.0, 'live' => true]);
});

it('counts the gross and tare an inbound and an outbound vehicle need', function () {
    $in = (new GateEntry)->forceFill(['direction' => 'inbound']);
    $out = (new GateEntry)->forceFill(['direction' => 'outbound']);

    expect(LiveCapture::nextKind(tap($in, fn ($e) => $e->status = 'arrived')))->toBe('gross')
        ->and(LiveCapture::nextKind(tap(clone $in, fn ($e) => $e->status = 'unloaded')))->toBe('tare')
        ->and(LiveCapture::nextKind(tap(clone $in, fn ($e) => $e->status = 'gross_weighed')))->toBeNull()
        ->and(LiveCapture::nextKind(tap($out, fn ($e) => $e->status = 'arrived')))->toBe('tare')
        ->and(LiveCapture::nextKind(tap(clone $out, fn ($e) => $e->status = 'loaded')))->toBe('gross');
});

it('walks through connecting the devices step by step, ticking what the system can see', function () {
    lwcSignIn($this, $this->f->owner);
    $page = Livewire::test(ConnectWeighbridge::class);
    $steps = collect($page->instance()->steps());

    // The fixture has a weighbridge and a gate operator login: those steps are done by themselves.
    expect($steps->pluck('done', 'title')->all())->toMatchArray([
        'Add the weighbridge in the ERP' => true, 'Create the PC\'s login' => true, 'Register the gate agent' => false,
    ])->and($page->get('step'))->toBe(1);   // the first step not done yet

    $page->assertSee('Step 1 of 12')->call('toggle', 1)->assertSet('step', 2)->assertSee('Switch on continuous output')
        ->call('next')->call('next')->assertSee('Step 4 of 12')->assertSee('Done — the system can see')
        ->call('go', 7)->assertSee('setup-windows.ps1')->call('back')->assertSet('step', 6);
    expect(Livewire::test(ConnectWeighbridge::class)->instance()->steps()[0]['done'])->toBeTrue();   // kept for this sign-in

    lwcFeed($this, [25480]);
    lwcSignIn($this, $this->f->owner);
    $steps = collect(Livewire::test(ConnectWeighbridge::class)->instance()->steps())->pluck('done', 'title');
    expect($steps['Find the indicator'])->toBeTrue()->and($steps['Test the connection and the live weight'])->toBeTrue();
});

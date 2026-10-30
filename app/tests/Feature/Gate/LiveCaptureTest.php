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
use App\Livewire\WeighQueue;
use App\Models\AuthSession;
use App\Models\Gate\GateEntry;
use App\Models\Gate\Weighment;
use App\Models\Gate\WeighmentCheck;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new GateFixtures(Make::entity('LWC'));
    $this->sauda = $this->f->confirmedSauda('100000');
    $this->operator = Make::account($this->f->entity, 'GATE_OPERATOR');
    $this->f->as($this->operator);
    $this->entry = app(GateService::class)->arrive(['registration_no' => 'MNA 21 1234', 'sauda_id' => $this->sauda->id, 'bag_count' => 400]);
    // The lab decides before any weighing.
    $this->f->as($this->f->owner);
    GateFixtures::passLab(app(GateService::class), $this->entry);
    $this->f->as($this->operator);
    $this->entry = $this->entry->fresh();
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

it('lists every waiting vehicle in token order, a lot at the lab too, however many old tokens are still open', function () {
    // Many old tokens never finished (the vehicle left without its weights): they must not hide today's vehicles.
    $gate = app(GateService::class);
    $old = $gate->arrive(['registration_no' => 'LHR 1001', 'purpose' => 'other', 'goods_description' => 'Bran']);
    Make::system(fn () => GateEntry::query()->whereKey($old->id)->update(['arrived_at' => now()->subDays(20)]));
    for ($i = 0; $i < 160; $i++) {
        Make::system(fn () => DB::table('gate_entries')->insert(array_merge((array) DB::table('gate_entries')->where('id', $old->id)->first(), [
            'id' => (string) Str::uuid7(), 'token_serial' => null, 'token_no' => null, 'registration_no' => 'OLD'.$i, 'arrived_at' => now()->subDays(30),
        ])));
    }
    $atLab = $gate->arrive(['registration_no' => 'MNA 21 5555', 'sauda_id' => $this->sauda->id, 'bag_count' => 100]);
    $other = $gate->arrive(['registration_no' => 'LES 7001', 'purpose' => 'other', 'goods_description' => 'Rice']);

    $waiting = app(LiveCapture::class)->waiting();
    $mine = $waiting->whereIn('id', [$this->entry->id, $atLab->id, $other->id])->values();
    expect($mine->pluck('id')->all())->toBe([$this->entry->id, $atLab->id, $other->id])   // token order
        ->and($mine[1]['label'])->toContain('at the lab')->and($mine[1]['kind'])->toBeNull()
        ->and($mine[2]['kind'])->toBe('gross');
});

it('refreshes the waiting list by itself and after a weight, without redrawing the weighing form', function () {
    lwcSignIn($this, $this->operator);
    $queue = Livewire::test(WeighQueue::class, ['scaleId' => $this->f->scale->id]);
    $queue->assertSeeHtml('wire:poll.10s')->assertSee('MNA211234')->assertDontSee('LES7001');

    app(GateService::class)->arrive(['registration_no' => 'LES 7001', 'purpose' => 'other', 'goods_description' => 'Rice']);
    $queue->dispatch('weigh-queue-refresh')->assertSee('LES7001');
    // The board itself does not poll; only the list beside it does.
    $html = Livewire::test(LiveWeightBoard::class)->html();
    expect(substr_count($html, 'wire:poll'))->toBe(1)->and($html)->toContain('wire:poll.10s');
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
        // Still listed: its empty weight comes next, after unloading (chosen on the same screen).
        ->and(app(LiveCapture::class)->waiting()->pluck('kind')->all())->toBe(['tare']);
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

    $sid = $this->f->scale->id;
    Livewire::test(LiveWeightBoard::class)->assertSee('Vehicle number')->assertSee('Token 000001 · MNA211234')
        ->call('recordWeight', $sid)->assertNotified('Type the vehicle number, e.g. MNA-21-1234.')
        // Typing a vehicle that is already inside fills in its party and kind.
        ->set('plate.'.$sid, 'mna-21-1234')->assertSet('party.'.$sid, 'Muhammad Aslam')->assertSet('kind.'.$sid, 'paddy')
        ->assertSee('Record loaded weight (gross)')
        ->call('recordWeight', $sid)->assertNotified('Loaded weight (gross) 25,480 kg recorded — token 000001 · MNA-21-1234');
    expect(Weighment::query()->where('gate_entry_id', $this->entry->id)->count())->toBe(1);

    lwcSignIn($this, Make::account($this->f->entity, 'LAB_TECHNICIAN'));
    Livewire::test(LiveWeightBoard::class)->assertDontSee('Vehicle number');
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

    expect(LiveCapture::nextKind(tap($in, fn ($e) => $e->status = 'arrived')))->toBeNull() // waits for the lab first
        ->and(LiveCapture::nextKind(tap(clone $in, fn ($e) => $e->status = 'lab_accepted')))->toBe('gross')
        ->and(LiveCapture::nextKind(tap(clone $in, fn ($e) => $e->status = 'unloaded')))->toBe('tare')
        ->and(LiveCapture::nextKind(tap(clone $in, fn ($e) => $e->status = 'gross_weighed')))->toBe('tare')   // unloaded on the weight screen first
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

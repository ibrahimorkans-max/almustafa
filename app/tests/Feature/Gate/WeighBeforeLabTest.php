<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Gate\GatePolicy;
use App\Domain\Gate\GateService;
use App\Domain\Gate\LiveCapture;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\LabQueuePage;
use App\Filament\Pages\LabTestEntryPage;
use App\Filament\Support\QuickLabTest;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Models\AuthSession;
use App\Models\Gate\GateEntry;
use App\Models\Gate\LabTest;
use App\Models\Gate\Weighment;
use App\Models\LoginAccount;
use App\Models\Procurement\Sauda;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new GateFixtures(Make::entity('WBL'));
    $this->sauda = $this->f->confirmedSauda('100000');
    $this->sup = Make::account($this->f->entity, 'SUPERVISOR');
    $this->f->as($this->sup);
    $this->gate = app(GateService::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function wblWeigh(object $t, GateEntry $e, string $kind, string $kg): Weighment
{
    return $t->gate->recordWeighment($e, $kind, $kg, $t->f->scale, GateFixtures::readings($kg), str_repeat('a', 64));
}

function wblSignIn(object $t, LoginAccount $a): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $web = Make::webSession($a);
    $t->actingAs($a, 'web')->withSession($web);
    app(AuthContextBinder::class)->bind($a, Make::system(fn () => AuthSession::query()->find($web[EnsureWebAuthSession::SESSION_KEY])));
}

it('weighs a paddy vehicle as soon as it arrives, adds the lab test after, and unloads only once the lab accepts it', function () {
    expect(GatePolicy::weighBeforeLab($this->f->entity->id))->toBeTrue();   // the default
    $e = $this->gate->arrive(['registration_no' => 'MNA 21 1234', 'sauda_id' => $this->sauda->id, 'bag_count' => 400]);
    expect(LiveCapture::nextKind($e->fresh()))->toBe('gross');

    wblWeigh($this, $e, 'gross', '25480');
    $e = $e->fresh();
    expect($e->status)->toBe('gross_weighed')->and(LiveCapture::nextKind($e))->toBeNull()
        ->and(LiveCapture::steps($e)['tare']['why'])->toContain('After the lab accepts the lot')
        ->and(QuickLabTest::waiting())->toHaveKey($e->id);
    // Not unloaded, nor weighed empty, before the lab accepts it.
    expect(fn () => $this->gate->markUnloaded($e))->toThrow(BusinessRuleViolation::class, 'lab')
        ->and(fn () => wblWeigh($this, $e, 'tare', '10230'))->toThrow(BusinessRuleViolation::class);

    $this->gate->recordLabTest($e, ['results' => ['moisture' => 1350]]);
    $this->gate->decideLab($e->fresh(), true);
    $e = $e->fresh();
    expect($e->status)->toBe('lab_accepted')->and(LiveCapture::nextKind($e))->toBe('tare')->and(LiveCapture::steps($e)['unload'])->toBeTrue()
        ->and(QuickLabTest::waiting())->not->toHaveKey($e->id);

    $this->gate->markUnloaded($e);
    wblWeigh($this, $e, 'tare', '10230');
    $this->f->settle($this->gate, $e);
    $this->gate->issueSlip($e);
    $this->gate->exit($e);

    $e = $e->fresh();
    expect($e->status)->toBe('exited')->and($e->net_kg)->toBe('15250.000')
        ->and($e->events()->pluck('event')->all())->toBe(['arrived', 'gross_weighed', 'lab_accepted', 'unloaded', 'tare_weighed', 'settlement_proposed', 'settlement_approved', 'slip_issued', 'exited'])
        ->and($e->events()->whereNotNull('anomaly')->count())->toBe(0)
        ->and(bccomp($this->sauda->fresh()->delivered_kg, '0', 3))->toBe(1);
});

it('still takes the lab first when it comes first, and a rejected lot that was weighed leaves without unloading', function () {
    $first = $this->gate->arrive(['registration_no' => 'MNA 21 1111', 'sauda_id' => $this->sauda->id]);
    $this->gate->recordLabTest($first, ['results' => ['moisture' => 1300]]);
    $this->gate->decideLab($first->fresh(), true);
    expect(LiveCapture::nextKind($first->fresh()))->toBe('gross');
    wblWeigh($this, $first, 'gross', '20000');
    expect($first->fresh()->status)->toBe('lab_accepted')->and(LiveCapture::nextKind($first->fresh()))->toBe('tare');

    $wet = $this->gate->arrive(['registration_no' => 'MNA 21 2222', 'sauda_id' => $this->sauda->id]);
    wblWeigh($this, $wet, 'gross', '21000');
    $this->gate->recordLabTest($wet->fresh(), ['results' => ['moisture' => 2400]]);   // above grace: rejected at once
    expect($wet->fresh()->status)->toBe('lab_rejected');
    wblWeigh($this, $wet->fresh(), 'tare', '9000');
    $this->gate->exit($wet->fresh());
    expect($wet->fresh()->status)->toBe('exited')->and(Sauda::query()->find($this->sauda->id)->delivered_kg)->toBe('0.000');
});

it('lists the vehicles waiting for the lab on their own page, each opening a page to add its lab test', function () {
    $weighed = $this->gate->arrive(['registration_no' => 'MNA 21 3333', 'sauda_id' => $this->sauda->id, 'bag_count' => 300]);
    wblWeigh($this, $weighed, 'gross', '22000');
    $waiting = $this->gate->arrive(['registration_no' => 'MNA 21 4444', 'sauda_id' => $this->sauda->id]);
    $goods = $this->gate->arrive(['registration_no' => 'LES 7001', 'purpose' => 'other', 'goods_description' => 'Rice']);

    $labsup = Make::account($this->f->entity, 'LAB_SUPERVISOR');
    wblSignIn($this, $labsup);
    $this->get(LabQueuePage::getUrl())->assertOk()->assertSee('Lab tests to add')->assertSee('weighed as soon as they arrive');
    Livewire::test(LabQueuePage::class)->assertCanSeeTableRecords([$weighed, $waiting])->assertCanNotSeeTableRecords([$goods])
        ->assertSee('22,000 kg')->assertSee('not weighed yet');

    // The vehicle's own page: its details, the results with the verdict, the decision.
    $this->get(LabTestEntryPage::getUrl(['entry' => $weighed->id]))->assertOk()->assertSee('MNA-21-3333')->assertSee('22,000 kg')->assertSee('Save lab test');
    Livewire::test(LabTestEntryPage::class, ['entry' => $weighed->id])
        ->set('data.r_moisture', '15')->set('data.r_broken', '2')->assertSee('Within grace: can be accepted, with a warning.')
        ->set('data.decision', 'accept')->call('save')->assertHasNoErrors()->assertRedirect(LabQueuePage::getUrl());

    expect(LabTest::query()->where('gate_entry_id', $weighed->id)->sole()->results)->toBe(['moisture' => 1500, 'broken' => 200])
        ->and($weighed->fresh()->status)->toBe('lab_accepted');
    Livewire::test(LabQueuePage::class)->assertCanNotSeeTableRecords([$weighed])->assertCanSeeTableRecords([$waiting]);
    Livewire::test(LabTestEntryPage::class, ['entry' => $weighed->id])->assertSee('already decided');
});

it('lets the mill go back to "lab first", where nothing is weighed before the lab', function () {
    wblSignIn($this, $this->f->owner);
    Livewire::test(LabQueuePage::class)->callAction('policy', ['policy' => 'lab_first'])->assertHasNoActionErrors();
    expect(GatePolicy::weighBeforeLab($this->f->entity->id))->toBeFalse();

    $this->f->as($this->sup);
    $e = $this->gate->arrive(['registration_no' => 'MNA 21 5555', 'sauda_id' => $this->sauda->id]);
    expect(LiveCapture::nextKind($e->fresh()))->toBeNull()
        ->and(fn () => wblWeigh($this, $e, 'gross', '25000'))->toThrow(BusinessRuleViolation::class, "'gross_weighed' recorded before 'lab_accepted'");
});

it('shows the live weight in the top bar of every screen, read in the browser', function () {
    wblSignIn($this, $this->f->owner);

    $this->get('/admin')->assertOk()->assertSee('data-live-weight-mini', false)->assertSee($this->f->scale->code)
        ->assertSee('live-weight.js', false);
});

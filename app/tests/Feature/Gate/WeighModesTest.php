<?php

declare(strict_types=1);

use App\Domain\Gate\GateService;
use App\Domain\Gate\LiveCapture;
use App\Domain\Gate\SlipRenderer;
use App\Domain\Gate\WeighModes;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Pages\Platform\WeighmentModesPage;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateSlipVersion;
use App\Models\Gate\Weighment;
use App\Models\WeighmentModeChange;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new GateFixtures(Make::entity('WMD'));
    $this->gate = app(GateService::class);
    $this->platform = Make::platformAdmin(Make::entity('PLT'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** The SaaS owner sets the modes of the test company (or of this mill), then the mill's Super Admin works again. */
function wmdSet(object $t, ?array $company, ?array $mill = null, bool $millOwn = false): void
{
    $t->f->as($t->platform);
    if ($company !== null) {
        WeighModes::configure($t->f->entity->customer_id, null, $company, 'test');
    }
    if ($millOwn) {
        WeighModes::configure(null, $t->f->entity->id, $mill, 'test');
    }
    $t->f->as($t->f->owner);
}

function wmdWeigh(object $t, GateEntry $e, string $kind, string $kg): Weighment
{
    return $t->gate->recordWeighment($e, $kind, $kg, $t->f->scale, GateFixtures::readings($kg), str_repeat('a', 64));
}

it('lets only the SaaS owner switch modes, per company with mill overrides, and keeps every change', function () {
    expect(fn () => WeighModes::configure($this->f->entity->customer_id, null, [WeighModes::NO_LAB]))
        ->toThrow(BusinessRuleViolation::class, 'Only the SaaS owner');

    wmdSet($this, [WeighModes::LAB_BEFORE_SLIP, WeighModes::NO_LAB, WeighModes::LAB_FIRST]);
    expect(WeighModes::enabled($this->f->entity->id))->toBe([WeighModes::LAB_BEFORE_SLIP, WeighModes::NO_LAB, WeighModes::LAB_FIRST])
        ->and(WeighModes::effective($this->f->entity->id)['version'])->toBe('company:1');

    wmdSet($this, null, [WeighModes::WEIGH_FIRST_LAB_LATER], true);
    expect(WeighModes::enabled($this->f->entity->id))->toBe([WeighModes::WEIGH_FIRST_LAB_LATER])
        ->and(WeighModes::effective($this->f->entity->id)['level'])->toBe('mill');

    wmdSet($this, null, null, true);   // back to the company's defaults
    expect(WeighModes::effective($this->f->entity->id)['level'])->toBe('company');

    $changes = Make::system(fn () => WeighmentModeChange::query()->orderBy('id')->get());
    expect($changes->pluck('action')->all())->toBe(['set', 'set', 'inherit'])
        ->and($changes->first()->changed_by)->toBe($this->platform->id)
        ->and(fn () => Make::system(fn () => DB::table('weighment_mode_changes')->delete()))->toThrow(QueryException::class);
});

it('offers only enabled modes to new vehicles, and a vehicle keeps its mode when the setting changes', function () {
    wmdSet($this, [WeighModes::LAB_BEFORE_SLIP, WeighModes::LAB_FIRST]);
    $e = $this->gate->arrive(['registration_no' => 'MNA 21 1234', 'weigh_mode' => WeighModes::LAB_FIRST]);
    expect($e->weigh_mode)->toBe(WeighModes::LAB_FIRST)->and($e->mode_config_version)->toBe('company:1')
        ->and(fn () => $this->gate->arrive(['registration_no' => 'MNA 21 5555', 'weigh_mode' => WeighModes::NO_LAB]))
        ->toThrow(BusinessRuleViolation::class, 'not switched on');

    wmdSet($this, [WeighModes::NO_LAB]);   // lab first switched off: the vehicle already in keeps it
    expect($e->fresh()->flow())->toBe(GateEntry::INBOUND_FLOW)
        ->and(LiveCapture::nextKind($e->fresh()))->toBeNull()   // still waits for the lab
        ->and($this->gate->arrive(['registration_no' => 'MNA 21 7777'])->weigh_mode)->toBe(WeighModes::NO_LAB);

    wmdSet($this, []);
    expect(fn () => $this->gate->arrive(['registration_no' => 'MNA 21 8888']))->toThrow(BusinessRuleViolation::class, 'No weighing mode');
});

it('mode 2: weighs without a lab test and gives a slip with the weights only', function () {
    wmdSet($this, [WeighModes::NO_LAB]);
    $e = $this->gate->arrive(['registration_no' => 'MNA 21 1234', 'bag_count' => 400]);
    expect(LiveCapture::nextKind($e->fresh()))->toBe('gross')
        ->and(fn () => $this->gate->recordLabTest($e, ['results' => ['moisture' => 1400]]))->toThrow(BusinessRuleViolation::class, 'without a lab test');

    wmdWeigh($this, $e, 'gross', '25480');
    $this->gate->markUnloaded($e->fresh());
    wmdWeigh($this, $e, 'tare', '10230');
    $this->gate->issueSlip($e->fresh());

    $e = $e->fresh();
    $slip = GateSlipVersion::query()->where('gate_entry_id', $e->id)->sole();
    expect($e->net_kg)->toBe('15250.000')->and($e->payable_kg)->toBe('15250.000')->and($e->events()->whereNotNull('anomaly')->count())->toBe(0)
        ->and($slip->kind)->toBe('final')->and($slip->weigh_mode)->toBe(WeighModes::NO_LAB)->and($slip->snapshot['lab_accepted'])->toBeNull()
        ->and(app(SlipRenderer::class)->pdf($e))->toStartWith('%PDF');
});

it('mode 3: a weight-only slip at once, the lab later, then the final slip — both kept', function () {
    $sauda = $this->f->confirmedSauda('100000');
    wmdSet($this, [WeighModes::WEIGH_FIRST_LAB_LATER]);
    $e = $this->gate->arrive(['registration_no' => 'MNA 21 1234', 'bag_count' => 400, 'sauda_id' => $sauda->id]);
    wmdWeigh($this, $e, 'gross', '25480');
    $first = $this->gate->issueInitialSlip($e->fresh());
    expect($first->version)->toBe(1)->and($first->kind)->toBe('initial')->and($first->snapshot['gross_kg'])->toBe('25480.000')
        ->and(fn () => $this->gate->issueInitialSlip($e->fresh()))->toThrow(BusinessRuleViolation::class);

    $this->gate->recordLabTest($e->fresh(), ['results' => ['moisture' => 1350]]);
    $this->gate->decideLab($e->fresh(), true);
    $this->gate->markUnloaded($e->fresh());
    wmdWeigh($this, $e, 'tare', '10230');
    $this->f->settle($this->gate, $e->fresh());
    $this->gate->issueSlip($e->fresh());

    $versions = GateSlipVersion::query()->where('gate_entry_id', $e->id)->orderBy('version')->get();
    expect($versions->pluck('kind')->all())->toBe(['initial', 'final'])
        ->and($versions[0]->fresh()->snapshot)->toBe($first->snapshot)   // never overwritten
        ->and(app(SlipRenderer::class)->pdf($e->fresh(), 1))->toStartWith('%PDF')
        ->and(fn () => Make::system(fn () => DB::table('gate_slip_versions')->where('id', $first->id)->update(['kind' => 'final'])))->toThrow(QueryException::class);
});

it('mode 4: lab first — negotiation pending blocks weighing; approved goes to the weighbridge; failed is rejected', function () {
    wmdSet($this, [WeighModes::LAB_FIRST]);
    $e = $this->gate->arrive(['registration_no' => 'MNA 21 1234', 'bag_count' => 400]);
    $this->gate->recordLabTest($e, ['results' => ['moisture' => 1650]]);
    $this->gate->openNegotiation($e->fresh(), 'Moisture high, propose Rs 20 cut');
    expect($e->fresh()->labOutcome())->toBe('negotiation_pending')
        ->and(fn () => wmdWeigh($this, $e, 'gross', '25480'))->toThrow(BusinessRuleViolation::class, 'negotiation');

    $this->gate->closeNegotiation($e->fresh(), true, 'Agreed: Rs 20 per maund cut');
    expect($e->fresh()->labOutcome())->toBe('negotiation_approved')->and($e->fresh()->status)->toBe('lab_accepted')
        ->and(LiveCapture::nextKind($e->fresh()))->toBe('gross');
    wmdWeigh($this, $e, 'gross', '25480');
    expect($e->fresh()->status)->toBe('gross_weighed');

    // Failed: rejected; weighed only with an override and its reason.
    $r = $this->gate->arrive(['registration_no' => 'MNA 21 9999']);
    $this->gate->recordLabTest($r, ['results' => ['moisture' => 1650]]);
    $this->gate->openNegotiation($r->fresh(), 'Moisture high');
    $this->gate->closeNegotiation($r->fresh(), false, 'Seller refused the cut');
    expect($r->fresh()->labOutcome())->toBe('negotiation_failed')->and($r->fresh()->status)->toBe('lab_rejected')
        ->and(fn () => wmdWeigh($this, $r, 'gross', '24000'))->toThrow(BusinessRuleViolation::class);
    $this->gate->overrideWeighing($r->fresh(), 'Weigh before sending back for the record');
    wmdWeigh($this, $r, 'gross', '24000');
    expect($r->fresh()->gross_kg)->toBe('24000.000');
});

it('shows the SaaS owner every company and mill with its modes; mill staff cannot open it', function () {
    wmdSet($this, [WeighModes::LAB_BEFORE_SLIP, WeighModes::NO_LAB]);
    $this->actingAs($this->platform, 'web')->withSession(Make::webSession($this->platform));
    $this->f->as($this->platform);
    Livewire::test(WeighmentModesPage::class)->assertOk()->assertSee('Mill WMD')->assertSee('Without lab')->assertSee('Change history');

    $this->actingAs($this->f->owner, 'web')->withSession(Make::webSession($this->f->owner));
    expect(WeighmentModesPage::canAccess())->toBeFalse();
});

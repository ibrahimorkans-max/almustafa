<?php

declare(strict_types=1);

use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Accounts\ExpenseCategories;
use App\Domain\Accounts\Expenses;
use App\Domain\Accounts\PurchaseBillService;
use App\Domain\Accounts\StockValuation;
use App\Domain\Auth\AuthContextBinder;
use App\Domain\Documents\PdfRenderer;
use App\Domain\Gate\GateService;
use App\Domain\Gate\VehicleRegister;
use App\Domain\Reports\ReportCatalog;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Gate\LabParameterResource\Pages\ManageLabParameters;
use App\Filament\Widgets\ProcurementOverview;
use App\Models\Accounts\ExpenseCategory;
use App\Models\Accounts\PurchaseBill;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GatePriceVersion;
use App\Models\Gate\LabParameter;
use App\Models\Inventory\StockEntry;
use App\Models\LoginAccount;
use App\Models\Masters\Godown;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new GateFixtures(Make::entity('LFP'));
    $this->sauda = $this->f->confirmedSauda('100000', 100000); // Rs 1,000 per maund
    $this->tech = Make::account($this->f->entity, 'LAB_TECHNICIAN');
    $this->labsup = Make::account($this->f->entity, 'LAB_SUPERVISOR');
    $this->operator = Make::account($this->f->entity, 'GATE_OPERATOR');
    $this->officer = Make::account($this->f->entity, 'PURCHASE_OFFICER');
    $this->manager = Make::account($this->f->entity, 'PURCHASE_MANAGER');
    $this->gate = app(GateService::class);
    $this->f->as($this->operator);
    $this->godown = Godown::query()->create(['code' => 'S1', 'name' => 'Silo', 'type' => 'silo']);
    $this->e = $this->gate->arrive(['registration_no' => 'MNA211234', 'sauda_id' => $this->sauda->id, 'bag_count' => 400]);
});

function lfpWeigh(object $t, string $kind, string $kg): void
{
    $t->f->as($t->operator);
    $t->gate->recordWeighment($t->e, $kind, $kg, $t->f->scale, GateFixtures::readings($kg), str_repeat('a', 64));
}

/** Weighed loaded, unloaded, weighed empty: net 15,250 kg = 381.25 maund. */
function lfpWeighAll(object $t): void
{
    lfpWeigh($t, 'gross', '25480');
    $t->gate->markUnloaded($t->e, godown: $t->godown);
    lfpWeigh($t, 'tare', '10230');
}

function lfpLab(object $t, array $results): void
{
    $t->f->as($t->tech);
    $t->gate->recordLabTest($t->e, ['results' => $results]);
}

it('starts every mill with the seven lab tests, in English and Urdu, each with a standard and a grace limit', function () {
    $tests = LabParameter::query()->orderBy('sort')->get();
    expect($tests->pluck('code')->all())->toBe(['moisture', 'green_grain', 'shell', 'shewad_green', 'broken', 'red_rice', 'dandly'])
        ->and($tests->pluck('name_ur', 'code')->all())->toMatchArray(['moisture' => 'نمی', 'broken' => 'ٹوٹا ہوا دانہ', 'red_rice' => 'سرخ چاول'])
        ->and($tests->every(fn (LabParameter $p) => $p->grace_bp >= $p->standard_bp))->toBeTrue();
});

it('weighs nothing before the lab decides, and reads each result against its limits', function () {
    expect(fn () => lfpWeigh($this, 'gross', '25480'))->toThrow(BusinessRuleViolation::class, "'gross_weighed' recorded before 'lab_accepted'");

    // Moisture 13.5 % within standard 14; broken 6 % above standard 5, within grace 8.
    lfpLab($this, ['moisture' => 1350, 'broken' => 600]);
    $test = $this->e->labTests()->sole();
    expect(collect($test->evaluation)->pluck('verdict', 'code')->all())->toBe(['moisture' => 'ok', 'broken' => 'grace'])
        ->and($this->e->fresh()->status)->toBe('arrived'); // waits for the supervisor: nothing is decided automatically within grace

    expect(fn () => $this->gate->decideLab($this->e, true))->toThrow(BusinessRuleViolation::class, 'lab.decide'); // the technician only tests
    $this->f->as($this->labsup);
    $this->gate->decideLab($this->e, true, 'Broken within grace');
    lfpWeigh($this, 'gross', '25480');
    expect($this->e->fresh()->status)->toBe('gross_weighed');

    // A result once weighed belongs before weighing: no later lab test.
    expect(fn () => lfpLab($this, ['moisture' => 1300]))->toThrow(BusinessRuleViolation::class, 'before weighing');
});

it('rejects a lot automatically above a grace limit, with the reason, and the supervisor cannot accept it', function () {
    lfpLab($this, ['moisture' => 1750, 'broken' => 400]); // moisture 17.5 % > grace 16 %

    $rejection = $this->e->events()->where('event', 'lab_rejected')->sole();
    expect($this->e->fresh()->status)->toBe('lab_rejected')
        ->and($rejection->data['automatic'])->toBeTrue()
        ->and($rejection->data['note'])->toContain('Moisture (نمی) 17.5 % — above the grace limit 16 %');

    $this->f->as($this->labsup);
    expect(fn () => $this->gate->decideLab($this->e, true, 'Looks fine'))->toThrow(BusinessRuleViolation::class, 'grace limit')
        ->and(fn () => lfpWeigh($this, 'gross', '25480'))->toThrow(BusinessRuleViolation::class, 'before');

    // It may leave unweighed; nothing is bought or stocked.
    $this->f->as($this->operator);
    $this->gate->exit($this->e);
    expect($this->e->fresh()->status)->toBe('exited')->and(StockEntry::query()->count())->toBe(0)->and(PurchaseBill::query()->count())->toBe(0);
});

it('brings a rejected lot in only after a negotiated price, approved by someone else, and bills it at that price', function () {
    lfpLab($this, ['moisture' => 1750]);

    $this->f->as($this->manager);
    expect(fn () => $this->gate->acceptRejectedLot($this->e, 'Seller agreed to a lower rate'))->toThrow(BusinessRuleViolation::class, 'First agree a price');

    // The purchase officer offers a lower price; it needs the reason, and someone else decides.
    $this->f->as($this->officer);
    expect(fn () => $this->gate->proposePrice($this->e, 95000, null))->toThrow(BusinessRuleViolation::class, 'reason');
    $v1 = $this->gate->proposePrice($this->e, 95000, 'Moisture 17.5 %: drying loss and risk');
    expect(fn () => $this->gate->decidePrice($this->e, true))->toThrow(BusinessRuleViolation::class, 'purchase.approve');
    $this->f->as($this->manager);
    $this->gate->decidePrice($this->e, true, 'Agreed');

    // A further revision is rejected: the last accepted price stays Rs 950.
    $this->f->as($this->officer);
    $this->gate->proposePrice($this->e, 93000, 'Seller asked for faster payment');
    $this->f->as($this->manager);
    expect(fn () => $this->gate->decidePrice($this->e, false))->toThrow(BusinessRuleViolation::class, 'reason');
    $this->gate->decidePrice($this->e, false, 'No: Rs 950 stands');
    expect($this->gate->acceptedPrice($this->e)->id)->toBe($v1->id)
        ->and(GatePriceVersion::query()->orderBy('version')->pluck('status')->all())->toBe(['accepted', 'rejected']);

    $this->gate->acceptRejectedLot($this->e, 'Accepted after negotiation at Rs 950 per maund');
    expect($this->e->fresh()->status)->toBe('lab_accepted')
        ->and($this->e->events()->where('event', 'lab_accepted')->sole()->data['after_negotiation'])->toBeTrue();

    lfpWeighAll($this);
    // Waste Caat 100 kg; Caat per Maund 1 kg × 381.25 maund = 381.25 kg; payable 15,250 − 481.25 = 14,768.75 kg.
    $this->f->as($this->officer);
    $this->gate->proposeSettlement($this->e, '100', '1');
    expect(fn () => $this->gate->approveSettlement($this->e))->toThrow(BusinessRuleViolation::class, 'purchase.approve');
    $this->f->as($this->manager);
    $this->gate->approveSettlement($this->e, 'OK');
    $this->f->as($this->operator);
    $this->gate->issueSlip($this->e);

    $e = $this->e->fresh();
    $bill = PurchaseBill::query()->sole();
    expect($e->deduction_kg)->toBe('481.250')->and($e->payable_kg)->toBe('14768.750')
        ->and(StockEntry::query()->where('source_id', $e->id)->sole()->quantity_kg)->toBe('14768.750') // stock gets the payable weight
        ->and($bill->payable_kg)->toBe('14768.750')
        ->and($bill->rate_paise_per_maund)->toBe(95000)  // the last accepted price, not the sauda's Rs 1,000
        ->and($bill->rate_cut_paise)->toBe(0)
        ->and($bill->snapshot['settlement']['price_version'])->toBe(1);
});

it('checks the settlement: numbers only, Caat below the net weight, approved by someone else, before the slip', function () {
    lfpLab($this, ['moisture' => 1300]);
    $this->f->as($this->labsup);
    $this->gate->decideLab($this->e, true);
    lfpWeighAll($this);

    expect(fn () => $this->gate->issueSlip($this->e))->toThrow(BusinessRuleViolation::class, 'Approve the settlement');

    $this->f->as($this->manager);
    expect(fn () => $this->gate->proposeSettlement($this->e, 'lots', '0'))->toThrow(BusinessRuleViolation::class, 'number of kg')
        ->and(fn () => $this->gate->proposeSettlement($this->e, '15000', '1'))->toThrow(BusinessRuleViolation::class, 'cannot reach the net weight');
    $this->gate->proposeSettlement($this->e, '240', '0.5');
    expect(fn () => $this->gate->approveSettlement($this->e))->toThrow(BusinessRuleViolation::class, 'someone other than the person who proposed it');

    // At the offered (sauda) price, approved by another person: the lab's quality rate cut still applies.
    $this->f->as($this->f->owner);
    $this->gate->approveSettlement($this->e);
    expect($this->e->fresh()->payable_kg)->toBe('14819.375') // 15,250 − 240 − 0.5 × 381.25
        ->and($this->gate->acceptedPrice($this->e)->price_paise_per_maund)->toBe(100000)
        ->and(fn () => $this->gate->proposePrice($this->e, 90000, 'Too late'))->toThrow(BusinessRuleViolation::class, 'approved');
});

it('lets the Lab Supervisor set the lab limits, and the gate staff only see them', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $signIn = function (LoginAccount $a): void {
        app('auth')->forgetGuards();
        $this->flushSession();
        $this->actingAs($a, 'web')->withSession(Make::webSession($a));
        app(AuthContextBinder::class)->bind($a);
    };
    $moisture = LabParameter::query()->where('code', 'moisture')->sole();

    $signIn($this->labsup);
    Livewire::test(ManageLabParameters::class)->assertCanSeeTableRecords([$moisture])
        ->callTableAction('edit', $moisture, ['name_en' => 'Moisture', 'name_ur' => 'نمی', 'standard' => '15', 'grace' => '17.5', 'unit' => '%', 'sort' => 10, 'status' => 'active'])
        ->assertHasNoTableActionErrors();
    expect($moisture->fresh()->only(['standard_bp', 'grace_bp']))->toBe(['standard_bp' => 1500, 'grace_bp' => 1750]);

    Livewire::test(ManageLabParameters::class)
        ->callTableAction('edit', $moisture, ['name_en' => 'Moisture', 'name_ur' => 'نمی', 'standard' => '15', 'grace' => '12', 'unit' => '%', 'sort' => 10, 'status' => 'active'])
        ->assertHasTableActionErrors(['grace']);

    $signIn($this->tech);
    Livewire::test(ManageLabParameters::class)->assertCanSeeTableRecords([$moisture])->assertTableActionHidden('edit', $moisture);
    $signIn($this->operator);
    $this->get('/admin/lab-tests')->assertForbidden();
});

/** A lot rejected for moisture, negotiated to Rs 950, accepted, weighed, settled (Waste 100 kg, 1 kg per maund) and slipped. */
function lfpNegotiatedLot(object $t): GateEntry
{
    lfpLab($t, ['moisture' => 1750, 'broken' => 400]);
    $t->f->as($t->officer);
    $t->gate->proposePrice($t->e, 95000, 'Moisture 17.5 %');
    $t->f->as($t->manager);
    $t->gate->decidePrice($t->e, true);
    $t->gate->acceptRejectedLot($t->e, 'Accepted after negotiation at Rs 950');
    lfpWeighAll($t);
    $t->f->as($t->officer);
    $t->gate->proposeSettlement($t->e, '100', '1');
    $t->f->as($t->manager);
    $t->gate->approveSettlement($t->e);
    $t->f->as($t->operator);
    $t->gate->issueSlip($t->e);

    return $t->e->fresh();
}

it('holds a rejected lot apart (quarantine) without making it stock, and only a rejected lot', function () {
    lfpLab($this, ['moisture' => 1750]);
    $this->f->as($this->operator);
    $this->gate->holdRejectedLot($this->e, 'Shed 3, separate from the silo', 'Waiting for the seller');

    $e = $this->e->fresh();
    expect($e->flags)->toContain('held_quarantine')->and(StockEntry::query()->count())->toBe(0);

    // Accepted after negotiation, it is no longer held.
    $this->f->as($this->officer);
    $this->gate->proposePrice($this->e, 95000, 'Wet lot');
    $this->f->as($this->manager);
    $this->gate->decidePrice($this->e, true);
    $this->gate->acceptRejectedLot($this->e, 'Accepted after negotiation at Rs 950');
    expect($this->e->fresh()->flags)->not->toContain('held_quarantine');
    $this->f->as($this->operator);
    expect(fn () => $this->gate->holdRejectedLot($this->e, 'Shed 3'))->toThrow(BusinessRuleViolation::class, 'Only a lot the lab rejected');
});

it('reopens an approved settlement only before the slip, with a reason, to propose it again', function () {
    lfpLab($this, ['moisture' => 1300]);
    $this->f->as($this->labsup);
    $this->gate->decideLab($this->e, true);
    lfpWeighAll($this);
    $this->f->as($this->officer);
    $this->gate->proposeSettlement($this->e, '240', '0');
    $this->f->as($this->manager);
    $this->gate->approveSettlement($this->e);

    expect(fn () => $this->gate->reopenSettlement($this->e, 'short'))->toThrow(BusinessRuleViolation::class, 'reason');
    $this->gate->reopenSettlement($this->e, 'Waste Caat was entered without the bag weight');
    expect($this->gate->settlementApproved($this->e))->toBeFalse()
        ->and(fn () => $this->gate->approveSettlement($this->e))->toThrow(BusinessRuleViolation::class, 'reopened');

    $this->f->as($this->officer);
    $this->gate->proposeSettlement($this->e, '300', '0');
    $this->f->as($this->manager);
    $this->gate->approveSettlement($this->e);
    expect($this->e->fresh()->payable_kg)->toBe('14950.000');

    $this->f->as($this->operator);
    $this->gate->issueSlip($this->e);
    $this->f->as($this->manager);
    expect(fn () => $this->gate->reopenSettlement($this->e, 'Too late to change this one'))->toThrow(BusinessRuleViolation::class, 'purchase bill');
});

it('adds the vehicle\'s freight to its landed cost and stock value', function () {
    $e = lfpNegotiatedLot($this);
    $finance = Make::account($this->f->entity, 'FINANCE_MANAGER');
    $this->f->as($finance);
    $bill = app(PurchaseBillService::class)->post(PurchaseBill::query()->sole());
    $valuation = app(StockValuation::class);
    $before = $valuation->averageOf($this->f->paddy->id);

    app(ExpenseCategories::class)->installDefaults();
    $freight = ExpenseCategory::query()->where('code', 'FREIGHT')->sole();
    $cash = app(ChartOfAccounts::class)->system('cash');
    app(Expenses::class)->record($freight, CarbonImmutable::now('Asia/Karachi'), 1_500_000, 'Freight from Lodhran',
        $cash, null, post: true, vehicle: $e, capitalize: true);

    $landed = $valuation->landedCostOf($e->id);
    $cost = $bill->gross_paise - $bill->rate_cut_paise + $bill->brokerage_paise;
    expect($landed)->toBe(['purchase_paise' => $cost, 'charges_paise' => 1_500_000, 'landed_paise' => $cost + 1_500_000])
        // The stock now carries the purchase plus the freight, over the same 14,768.75 kg.
        ->and($valuation->averageOf($this->f->paddy->id))->toBe(bcdiv((string) ($cost + 1_500_000), '14768.75', 4))
        ->and(bccomp($valuation->averageOf($this->f->paddy->id), $before, 4))->toBe(1);

    expect(fn () => app(Expenses::class)->record($freight, CarbonImmutable::now('Asia/Karachi'), 100, 'Not for a vehicle',
        $cash, null, capitalize: true))->toThrow(BusinessRuleViolation::class, 'inbound vehicle');
});

it('reports settlements, lab results, rejections, negotiations, Caat and price variance', function () {
    lfpNegotiatedLot($this);
    $catalog = app(ReportCatalog::class);
    [$from, $to] = [CarbonImmutable::now('Asia/Karachi')->subDay(), CarbonImmutable::now('Asia/Karachi')];
    $this->f->as($this->f->owner);
    $text = fn (string $key) => collect($catalog->build($key, $from, $to))->flatMap(fn ($p) => array_map(fn ($r) => implode(' | ', $r), $p['rows']))->implode("\n");

    expect($text('settlements'))->toContain('14,768.75')->toContain('950.00')->toContain('481.25')
        ->and($text('lab_results'))->toContain('Moisture (نمی) | 17.5 % | 14 % | 16 % | ABOVE GRACE | accepted after negotiation')
        ->and($text('rejections'))->toContain('automatic')->toContain('Moisture 17.5 % > 16 %')->toContain('accepted after negotiation at 950.00')
        ->and($text('negotiations'))->toContain('1 | 950.00 | offered | accepted | Moisture 17.5 %')
        ->and($text('caat_analysis'))->toContain('100.00 | 1 | 381.25 | 481.25')
        // (1,000 − 950) × 369.21875 maund = Rs 18,460.94
        ->and($text('price_variance'))->toContain('1,000.00 | 950.00 | 50.00 | 369.22 | 18,460.94');

    expect(array_keys(ReportCatalog::forUser($this->manager)))->toContain('procurement');
});

it('prints the final settlement of a vehicle: lab, negotiation, Caat, money, approvals', function () {
    $e = lfpNegotiatedLot($this);
    $this->f->as($this->f->owner);
    app(VehicleRegister::class)->vehicleSlipPdf($e);
    expect(PdfRenderer::$lastHtml)->toContain('Lab results against the limits')->toContain('ABOVE GRACE')
        ->toContain('Negotiation (price versions)')->toContain('Caat, purchase and financial settlement')
        ->toContain('Total Caat')->toContain('Net supplier liability')->toContain('Approval history')
        ->toContain($this->officer->username)->toContain($this->manager->username)->toContain('Approved by');
});

it('gives the Finance Manager the money rights and shows the procurement figures on the dashboard', function () {
    $finance = Make::account($this->f->entity, 'FINANCE_MANAGER');
    expect($finance->hasPermission('payment.approve'))->toBeTrue()->and($finance->hasPermission('period.lock'))->toBeTrue()
        ->and($finance->hasPermission('gate.entry'))->toBeFalse();

    lfpNegotiatedLot($this);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->f->owner, 'web')->withSession(Make::webSession($this->f->owner));
    app(AuthContextBinder::class)->bind($this->f->owner);
    Livewire::test(ProcurementOverview::class)->assertSee('Negotiated reduction (month)')
        ->assertSee('Rs 18,460.94')->assertSee('481 kg')->assertSee('Settlements waiting');
});

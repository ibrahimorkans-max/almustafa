<?php

declare(strict_types=1);

use App\Domain\Gate\GateService;
use App\Domain\Hr\ContractorBilling;
use App\Domain\Hr\ContractorVehicles;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\Gate\GateEntryResource\Pages\ListGateEntries;
use App\Filament\Resources\Gate\GateEntryResource\Pages\ViewGateEntry;
use App\Filament\Resources\Hr\ContractorRateResource\Pages\ManageContractorRates;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Gate\Weighment;
use App\Models\Hr\ContractorRate;
use App\Models\LoginAccount;
use App\Models\Masters\Godown;
use App\Models\Masters\Party;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

/*
 * Labour contractors paid per vehicle (rates, many vehicles per contractor, billed once), paddy rejected
 * while unloading (net = only what was unloaded) and correcting a vehicle number after weighing.
 */
const CV_PHOTO = 'b000000000000000000000000000000000000000000000000000000000000000';

beforeEach(function () {
    $this->f = new GateFixtures(Make::entity('CVU'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->f->as($this->f->owner);
    $this->gate = app(GateService::class);
    $this->contractor = Party::query()->create(['name' => 'Rafiq Labour Co', 'is_vendor' => true]);
});

function cvSignIn(object $t, LoginAccount $account): void
{
    app('auth')->forgetGuards();
    $t->flushSession();
    $t->actingAs($account, 'web')->withSession(Make::webSession($account));
    $t->f->as($account);
}

function cvWeigh(object $t, GateEntry $e, string $kind, string $kg): Weighment
{
    return $t->gate->recordWeighment($e, $kind, $kg, $t->f->scale, GateFixtures::readings($kg), CV_PHOTO);
}

/** A vehicle that arrived some days ago with bags and a net weight. */
function cvVehicle(object $t, string $plate, string $type, int $bags, string $netKg, int $daysAgo = 3): GateEntry
{
    $e = $t->gate->arrive(['registration_no' => $plate, 'vehicle_type' => $type, 'bag_count' => $bags, 'jack_type' => 'with_jack'], occurredAt: now()->subDays($daysAgo));
    $e->forceFill(['net_kg' => $netKg])->save();

    return $e->fresh();
}

it('bills a contractor\'s many vehicles together at its rates, each vehicle once', function () {
    // Rs 12 per maund for any vehicle; tractors Rs 50 per 100 kg.
    ContractorRate::query()->create(['party_id' => $this->contractor->id, 'basis' => 'maund', 'rate_paise' => 12_00, 'effective_from' => now()->subMonth()->toDateString()]);
    ContractorRate::query()->create(['party_id' => $this->contractor->id, 'basis' => 'custom', 'basis_qty' => '100', 'basis_unit' => 'kg', 'vehicle_type' => 'tractor',
        'rate_paise' => 50_00, 'effective_from' => now()->subMonth()->toDateString()]);
    $truck = cvVehicle($this, 'MNA211234', 'truck', 400, '20000');
    $truck2 = cvVehicle($this, 'LEA4567', 'truck', 250, '12500');
    $tractor = cvVehicle($this, 'LEB1234', 'tractor', 80, '4000');
    $other = cvVehicle($this, 'LEC1234', 'truck', 100, '5000');

    expect(app(ContractorVehicles::class)->assign([$truck, $truck2, $tractor], $this->contractor))->toBe(3);

    $period = [CarbonImmutable::now('Asia/Karachi')->subDays(10), CarbonImmutable::now('Asia/Karachi')->subDay()];
    $bill = app(ContractorBilling::class)->prepare($this->contractor, $period[0], $period[1], 'vehicles', 0);
    expect($bill->lines()->count())->toBe(3)
        ->and($bill->gross_paise)->toBe(500 * 12_00 + 31250 * 12 + 40 * 50_00)   // 500 md and 312.5 md × Rs 12; 4,000 kg ÷ 100 × Rs 50
        ->and($truck->fresh()->contractor_bill_id)->toBe($bill->id)
        ->and($other->fresh()->contractor_bill_id)->toBeNull();

    // Billed once: nothing left to bill, and a billed vehicle cannot be moved to another contractor.
    expect(fn () => app(ContractorBilling::class)->prepare($this->contractor, $period[0], $period[1], 'vehicles', 0))
        ->toThrow(BusinessRuleViolation::class, 'no unbilled vehicles');
    expect(fn () => app(ContractorVehicles::class)->assign([$truck], null))->toThrow(BusinessRuleViolation::class, 'already on a contractor bill');

    // A refused bill frees its vehicles.
    $approver = Make::account($this->f->entity, 'SUPER_ADMIN');
    $this->f->as($approver);
    app(ContractorBilling::class)->reject($bill, 'Rates to be checked');
    expect($truck->fresh()->contractor_bill_id)->toBeNull();
});

it('refuses a vehicle with no rate for the contractor', function () {
    $truck = cvVehicle($this, 'MNA211234', 'truck', 400, '20000');
    app(ContractorVehicles::class)->assign([$truck], $this->contractor);

    expect(fn () => app(ContractorBilling::class)->prepare($this->contractor, CarbonImmutable::now('Asia/Karachi')->subDays(10), CarbonImmutable::now('Asia/Karachi')->subDay(), 'vehicles', 0))
        ->toThrow(BusinessRuleViolation::class, 'No rate');
});

it('links many vehicles to a contractor from the gate list, and sets rates on screen', function () {
    $a = cvVehicle($this, 'MNA211234', 'truck', 400, '20000', 1);
    $b = cvVehicle($this, 'LEA4567', 'truck', 250, '12500', 1);
    cvSignIn($this, $this->f->owner);

    Livewire::test(ListGateEntries::class)->filterTable('open', 'all')
        ->callTableBulkAction('assignContractor', [$a, $b], ['contractor_party_id' => $this->contractor->id, 'work' => 'unloading'])
        ->assertNotified('2 vehicle(s) linked to the contractor.');
    expect($a->fresh()->contractor_party_id)->toBe($this->contractor->id)->and($b->fresh()->contractor_work)->toBe('unloading');

    $vehicle = app(ContractorVehicles::class)->addVehicle($this->contractor, 'LEA-4567', 'truck');
    Livewire::test(ManageContractorRates::class)
        ->callAction('create', ['party_id' => $this->contractor->id, 'vehicle_id' => $vehicle->id, 'work' => 'unloading', 'basis' => 'maund', 'rate_paise' => '15',
            'effective_from' => now()->toDateString(), 'status' => 'active'])
        ->assertHasNoActionErrors();
    expect(ContractorRate::query()->sole())->rate_paise->toBe(15_00)->vehicle_id->toBe($vehicle->id)->code->toStartWith('CRL-');
});

it('accepts only the paddy unloaded when part of it is rejected, and counts only those bags', function () {
    $sauda = $this->f->confirmedSauda('100000');
    // Gate staff: a supervisor does every gate step (entry, weighing, lab).
    $this->f->as(Make::account($this->f->entity, 'SUPERVISOR'));
    $e = $this->gate->arrive(['registration_no' => 'MNA211234', 'sauda_id' => $sauda->id, 'bag_count' => 400, 'jack_type' => 'with_jack']);
    $this->gate->recordLabTest($e, ['moisture_bp' => 1500, 'grade' => 'A']);
    $this->gate->decideLab($e, true);
    cvWeigh($this, $e, 'gross', '25000');

    expect(fn () => $this->gate->markUnloaded($e, rejectedBags: 50))->toThrow(BusinessRuleViolation::class, 'why');
    $this->gate->markUnloaded($e, rejectedBags: 50, rejectionReason: 'Wet bags, returned to the seller', contractor: $this->contractor);
    // The empty weight carries the 50 rejected bags: no "tare differs" alarm for it.
    $tare = cvWeigh($this, $e, 'tare', '11500');
    $e->refresh();

    expect($e->net_kg)->toBe('13500.000')                       // only the paddy unloaded
        ->and($e->rejected_bags)->toBe(50)->and($e->acceptedBags())->toBe(350)
        ->and($e->deduction_kg)->toBe('210.000')                 // bag weight: 350 bags × 0.6 kg, not 400
        ->and($e->payable_kg)->toBe('13290.000')
        ->and($e->flags)->toContain('partly_rejected')
        ->and($tare->flags ?? [])->not->toContain('tare_deviation')
        ->and($e->contractor_party_id)->toBe($this->contractor->id);
    expect(GateEvent::query()->where('gate_entry_id', $e->id)->where('event', 'unloaded')->sole()->data)
        ->toMatchArray(['rejected_bags' => 50, 'rejection_reason' => 'Wet bags, returned to the seller']);
});

it('corrects a vehicle number after weighing, keeping the weights; after the slip only a main user may', function () {
    $e = $this->gate->arrive(['registration_no' => 'LEA4567', 'jack_type' => 'with_jack', 'direction' => 'inbound', 'purpose' => 'other']);
    cvWeigh($this, $e, 'gross', '25000');
    $operator = Make::account($this->f->entity, 'GATE_OPERATOR');
    $this->f->as($operator);

    expect(fn () => $this->gate->correctVehicle($e, 'not a plate', 'typed wrong'))->toThrow(BusinessRuleViolation::class, 'valid');
    $fixed = $this->gate->correctVehicle($e->fresh(), 'MNA-21-1234', 'Typed LEA instead of MNA at the gate');
    expect($fixed->registration_no)->toBe('MNA211234')->and($fixed->vehicle_id)->not->toBeNull()
        ->and($fixed->gross_kg)->toBe('25000.000')->and($fixed->flags)->toContain('vehicle_corrected')
        ->and(GateEvent::query()->where('gate_entry_id', $e->id)->where('event', 'vehicle_corrected')->sole()->data)
        ->toMatchArray(['from' => 'LEA4567', 'to' => 'MNA211234']);

    $e = $fixed->fresh();
    $e->forceFill(['status' => 'exited'])->save();
    expect(fn () => $this->gate->correctVehicle($e->fresh(), 'LEB-1234', 'Wrong again, sorry'))->toThrow(BusinessRuleViolation::class, 'only the Owner or the Super Admin');
    $this->f->as($this->f->owner);
    expect($this->gate->correctVehicle($e->fresh(), 'LEB-1234', 'Checked against the papers')->registration_no)->toBe('LEB1234');
});

it('offers the correction and the rejection on the gate screen', function () {
    $e = $this->gate->arrive(['registration_no' => 'LEA4567', 'jack_type' => 'with_jack', 'direction' => 'inbound', 'purpose' => 'other']);
    cvSignIn($this, $this->f->owner);
    Livewire::test(ViewGateEntry::class, ['record' => $e->id])
        ->callAction('correctVehicle', ['registration_no' => 'MNA-21-1234', 'reason' => 'Typed wrong at the gate'])
        ->assertNotified('Vehicle number corrected');
    expect($e->fresh()->registration_no)->toBe('MNA211234');
});

/** An accepted lot, weighed loaded, ready to unload. */
function cvLoaded(object $t, string $plate, string $gross = '25000'): GateEntry
{
    $sauda = $t->f->confirmedSauda('100000');   // made by the owner
    $t->f->as(Make::account($t->f->entity, 'SUPERVISOR'));
    $e = $t->gate->arrive(['registration_no' => $plate, 'sauda_id' => $sauda->id, 'bag_count' => 400, 'jack_type' => 'with_jack']);
    $t->gate->recordLabTest($e, ['moisture_bp' => 1500, 'grade' => 'A']);
    $t->gate->decideLab($e, true);
    cvWeigh($t, $e, 'gross', $gross);

    return $e->fresh();
}

it('takes the rejected paddy by weight: on the vehicle, net comes out as the paddy unloaded by itself', function () {
    $e = cvLoaded($this, 'MNA211234');
    $this->gate->markUnloaded($e, rejectionReason: 'Black grains in 40 bags', rejectedKg: '2000');
    cvWeigh($this, $e, 'tare', '12000');   // the empty vehicle (10,000) with the 2,000 kg rejected still on it
    $e->refresh();

    expect($e->net_kg)->toBe('13000.000')->and($e->rejected_kg)->toBe('2000.000')->and($e->rejected_off_vehicle)->toBeFalse()
        ->and($e->rejectionOnVehicle())->toBeTrue();
});

it('subtracts rejected paddy taken off the vehicle by its weight', function () {
    $e = cvLoaded($this, 'LEA4567');
    expect(fn () => $this->gate->markUnloaded($e, rejectedBags: 40, rejectionReason: 'Wet bags kept aside', rejectedOffVehicle: true))
        ->toThrow(BusinessRuleViolation::class, 'give the rejected weight in kg');
    $this->gate->markUnloaded($e, rejectionReason: 'Wet bags kept aside', rejectedKg: '2000', rejectedOffVehicle: true);
    cvWeigh($this, $e, 'tare', '10000');   // the empty vehicle on its own
    $e->refresh();

    expect($e->net_kg)->toBe('13000.000')                      // 25,000 − 10,000 − 2,000 kept aside
        ->and($e->rejectionOnVehicle())->toBeFalse();
});

it('records a rejection by weight on the gate screen, with small buttons', function () {
    $e = cvLoaded($this, 'LEB1234');
    cvSignIn($this, Make::account($this->f->entity, 'SUPERVISOR'));
    Livewire::test(ViewGateEntry::class, ['record' => $e->id])
        ->assertSeeHtml('fi-size-sm')
        ->callAction('unloaded', ['godown_id' => Godown::query()->create(['code' => 'G9', 'name' => 'Godown 9'])->id,
            'rejected' => true, 'rejected_kg' => '1500', 'rejected_where' => 'on', 'rejection_reason' => 'Mixed variety found'])
        ->assertNotified('Marked unloaded');
    expect($e->fresh()->rejected_kg)->toBe('1500.000');
});

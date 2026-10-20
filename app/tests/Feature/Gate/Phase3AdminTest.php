<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Gate\GateService;
use App\Filament\Pages\ConnectWeighbridge;
use App\Filament\Resources\DeviceResource\Pages\ListDevices;
use App\Filament\Resources\Gate\GateEntryResource\Pages\CreateGateEntry;
use App\Filament\Resources\Gate\GateEntryResource\Pages\ViewGateEntry;
use App\Filament\Resources\Gate\ScaleResource\Pages\CreateScale;
use App\Filament\Resources\Procurement\SaudaResource\Pages\CreateSauda;
use App\Filament\Resources\Procurement\SaudaResource\Pages\ViewSauda;
use App\Models\Device;
use App\Models\Gate\GateEntry;
use App\Models\Gate\LabTest;
use App\Models\Gate\Scale;
use App\Models\Inventory\StockBalance;
use App\Models\LoginAccount;
use App\Models\Masters\Godown;
use App\Models\Procurement\Sauda;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\GateFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new GateFixtures(Make::entity('ADM3'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function signInAs(object $t, LoginAccount $account): void
{
    $t->actingAs($account, 'web')->withSession(Make::webSession($account));
    app(AuthContextBinder::class)->bind($account);
}

dataset('phase 3 screens', [
    'gate' => ['/admin/gate', ['OWNER', 'SUPER_ADMIN', 'SUPERVISOR', 'LAB_TECHNICIAN', 'GATE_OPERATOR']],
    'saudas' => ['/admin/procurement/saudas', ['OWNER', 'SUPER_ADMIN', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR']],
    'quotes' => ['/admin/procurement/quotes', ['OWNER', 'SUPER_ADMIN', 'EXECUTIVE', 'ACCOUNTANT']],
    'requirements' => ['/admin/requirements', ['OWNER', 'SUPER_ADMIN', 'EXECUTIVE', 'ACCOUNTANT']],
    'weighbridges' => ['/admin/weighbridges', ['OWNER', 'SUPER_ADMIN', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER', 'LAB_TECHNICIAN', 'GATE_OPERATOR']],
    'deductions' => ['/admin/quality-deductions', ['OWNER', 'SUPER_ADMIN', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER', 'LAB_TECHNICIAN', 'GATE_OPERATOR']],
]);

it('opens procurement and gate screens only for the right roles', function (string $path, array $allowed) {
    foreach (['OWNER', 'SUPER_ADMIN', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER', 'LAB_TECHNICIAN', 'GATE_OPERATOR'] as $role) {
        $account = Make::account($this->f->entity, $role, ['mfa' => true]);
        $status = $this->actingAs($account, 'web')->withSession(Make::webSession($account))->get($path)->getStatusCode();
        app('auth')->forgetGuards();
        $this->flushSession();

        expect($status)->toBe(in_array($role, $allowed, true) ? 200 : 403, "{$role} → {$path}");
    }
})->with('phase 3 screens');

it('creates a sauda from maund and rupee inputs and stores kg and paisa', function () {
    signInAs($this, $this->f->owner);

    Livewire::test(CreateSauda::class)
        ->fillForm([
            'party_id' => $this->f->seller->id, 'product_id' => $this->f->paddy->id,
            'quantity_maund' => '1250.5', 'rate_rupees' => '2650.75', 'tolerance_percent' => '5',
            'brokerage_rupees' => '10', 'moisture_max_percent' => '17', 'payment_days' => 15,
            'delivery_from' => now()->toDateString(), 'delivery_to' => now()->addDays(20)->toDateString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $s = Sauda::query()->latest('created_at')->firstOrFail();
    expect($s->quantity_kg)->toBe('50020.000') // 1,250.5 maund × 40 kg
        ->and($s->rate_paise_per_maund)->toBe(265075)
        ->and($s->brokerage_paise_per_maund)->toBe(1000)
        ->and($s->quality_terms)->toBe(['moisture_max_bp' => 1700])
        ->and($s->status)->toBe('draft');

    Livewire::test(ViewSauda::class, ['record' => $s->id])->callAction('submit');
    expect($s->fresh()->status)->toBe('pending_approval');
    // The mill's Super Admin needs no second person: approving their own sauda confirms it.
    Livewire::test(ViewSauda::class, ['record' => $s->id])->callAction('approve', ['note' => 'x'])->assertNotified();
    expect($s->fresh()->status)->toBe('confirmed');
});

it('runs a vehicle visit from the gate screens', function () {
    $sauda = $this->f->confirmedSauda();
    $sup = Make::account($this->f->entity, 'SUPERVISOR');
    signInAs($this, $sup);

    Livewire::test(CreateGateEntry::class)
        ->fillForm(['registration_no' => 'mna211234', 'direction' => 'inbound', 'sauda_id' => $sauda->id, 'bag_count' => 400])
        ->call('create')
        ->assertHasNoFormErrors();
    $entry = GateEntry::query()->sole();

    // The lab first: the configured tests with their limits (moisture 15.5 % is above standard 14, within grace 16).
    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])
        ->callAction('labTest', ['r_moisture' => '15.5', 'r_broken' => '4', 'grade' => 'A'])
        ->assertNotified('Lab test recorded');
    expect(LabTest::query()->sole()->results)->toBe(['moisture' => 1550, 'broken' => 400])
        ->and(collect(LabTest::query()->sole()->evaluation)->pluck('verdict', 'code')->all())->toBe(['moisture' => 'grace', 'broken' => 'ok']);
    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])->assertSee('within grace');

    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])->callAction('accept', ['note' => null]);
    // Weights come from the indicator (agent); simulate that part through the service.
    app(GateService::class)->recordWeighment($entry, 'gross', '25480', $this->f->scale, GateFixtures::readings('25480'), str_repeat('c', 64));
    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])->assertActionHidden('labTest');
    $silo = Godown::query()->create(['code' => 'S1', 'name' => 'Paddy silo', 'type' => 'silo']);
    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])->callAction('unloaded')->assertHasActionErrors(['godown_id' => 'required']);
    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])->callAction('unloaded', ['godown_id' => $silo->id])->assertNotified('Marked unloaded');
    app(GateService::class)->recordWeighment($entry, 'tare', '10230', $this->f->scale, GateFixtures::readings('10230'), str_repeat('d', 64));

    // Settlement: net 15,250; Waste Caat 240 kg (400 bags × 600 g); Caat per Maund 0.5 kg × 381.25 maund = 190.625 → payable 14,819.375.
    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])->assertActionHidden('slip')
        ->callAction('proposeSettlement', ['waste_kg' => '240', 'per_maund_kg' => '0.5'])->assertNotified('Settlement proposed');
    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])->assertActionHidden('approveSettlement'); // not by the proposer
    signInAs($this, $this->f->owner);
    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])->callAction('approveSettlement', ['note' => 'OK'])->assertNotified('Settlement approved');
    signInAs($this, $sup);
    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])->assertSee('14819.375 kg')->callAction('slip')->assertNotified('Slip issued');
    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])->callAction('download')->assertFileDownloaded();
    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])->callAction('exit');

    // Stock receives the payable weight (the Caat is not kept).
    expect($entry->fresh()->status)->toBe('exited')->and($sauda->fresh()->delivered_kg)->toBe('14819.375')
        ->and(StockBalance::query()->where('godown_id', $silo->id)->value('quantity_kg'))->toBe('14819.375');
});

it('does not offer any way to type a weight except the Super Admin override', function () {
    $sup = Make::account($this->f->entity, 'SUPERVISOR');
    signInAs($this, $sup);
    $entry = app(GateService::class)->arrive(['registration_no' => 'MNA211234']);

    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])->assertActionHidden('manualWeight');

    $sa = Make::owner($this->f->entity);
    app('auth')->forgetGuards();
    $this->flushSession();
    signInAs($this, $sa);
    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])
        ->callAction('manualWeight', ['kind' => 'gross', 'weight_kg' => '25000', 'reason' => 'Indicator display failed during weighment'])
        ->assertNotified('Done: applied at once');
});

it('registers a gate agent and shows the secret once', function () {
    $agent = Make::account($this->f->entity, 'GATE_OPERATOR', ['username' => 'wb.agent']);
    signInAs($this, $this->f->owner);

    Livewire::test(ListDevices::class)
        ->callAction('registerAgent', ['login_account_id' => $agent->id, 'name' => 'Weighbridge PC'])
        ->assertNotified('Gate agent registered — copy these now');

    $device = Device::query()->where('platform', 'agent')->sole();
    expect($device->signing_secret)->toHaveLength(43)->and($device->isTrusted())->toBeTrue();
});

it('adds a new weighbridge from the screen', function () {
    signInAs($this, $this->f->owner);

    Livewire::test(CreateScale::class)
        ->fillForm(['code' => 'WB2', 'name' => 'Second weighbridge', 'capacity_kg' => '60000', 'resolution_kg' => '10', 'status' => 'active'])
        ->call('create')->assertHasNoFormErrors();
    expect(Scale::query()->where('code', 'WB2')->exists())->toBeTrue();

    // The same code twice in one mill is refused.
    Livewire::test(CreateScale::class)
        ->fillForm(['code' => 'WB2', 'name' => 'Again', 'capacity_kg' => '60000', 'resolution_kg' => '10', 'status' => 'active'])
        ->call('create')->assertHasFormErrors(['code' => 'unique']);
});

it('shows every connection detail of the weighbridge PC ready to copy, without secrets', function () {
    $agent = Make::account($this->f->entity, 'GATE_OPERATOR', ['username' => 'wb.agent']);
    signInAs($this, $this->f->owner);
    Livewire::test(ListDevices::class)->callAction('registerAgent', ['login_account_id' => $agent->id, 'name' => 'Weighbridge PC']);
    $device = Device::query()->where('platform', 'agent')->sole();

    $page = Livewire::test(ConnectWeighbridge::class)
        ->assertSee($this->f->scale->id)->assertSee($device->device_uid)->assertSee('wb.agent')->assertSee((string) config('app.url'))
        ->assertDontSee($device->signing_secret);
    $toml = $page->instance()->connectionDetails()['scales'][0]['toml'];
    expect($toml)->toContain('id = "'.$this->f->scale->id.'"')->toContain('device_uid = "'.$device->device_uid.'"')
        ->toContain('signing_secret = ""');
});

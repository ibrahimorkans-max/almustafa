<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Gate\GateService;
use App\Filament\Resources\DeviceResource\Pages\ListDevices;
use App\Filament\Resources\Gate\GateEntryResource\Pages\CreateGateEntry;
use App\Filament\Resources\Gate\GateEntryResource\Pages\ViewGateEntry;
use App\Filament\Resources\Procurement\SaudaResource\Pages\CreateSauda;
use App\Filament\Resources\Procurement\SaudaResource\Pages\ViewSauda;
use App\Models\Device;
use App\Models\Gate\GateEntry;
use App\Models\Gate\LabTest;
use App\Models\LoginAccount;
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
    'gate' => ['/admin/gate', ['SUPER_ADMIN', 'OWNER', 'SUPERVISOR', 'LAB_TECHNICIAN', 'GATE_OPERATOR']],
    'saudas' => ['/admin/procurement/saudas', ['SUPER_ADMIN', 'OWNER', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR']],
    'quotes' => ['/admin/procurement/quotes', ['SUPER_ADMIN', 'OWNER', 'EXECUTIVE', 'ACCOUNTANT']],
    'requirements' => ['/admin/requirements', ['SUPER_ADMIN', 'OWNER', 'EXECUTIVE', 'ACCOUNTANT']],
    'weighbridges' => ['/admin/weighbridges', ['SUPER_ADMIN', 'OWNER', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER', 'LAB_TECHNICIAN', 'GATE_OPERATOR']],
    'deductions' => ['/admin/quality-deductions', ['SUPER_ADMIN', 'OWNER', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER', 'LAB_TECHNICIAN', 'GATE_OPERATOR']],
]);

it('opens procurement and gate screens only for the right roles', function (string $path, array $allowed) {
    foreach (['SUPER_ADMIN', 'OWNER', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER', 'LAB_TECHNICIAN', 'GATE_OPERATOR'] as $role) {
        $account = Make::account($this->f->entity, $role, ['mfa' => true]);
        $status = $this->actingAs($account, 'web')->withSession(Make::webSession($account))->get($path)->getStatusCode();
        app('auth')->forgetGuards();
        $this->flushSession();

        expect($status)->toBe(in_array($role, $allowed, true) ? 200 : 403, "{$role} → {$path}");
    }
})->with('phase 3 screens');

it('creates a sauda from quintal and rupee inputs and stores kg and paise', function () {
    signInAs($this, $this->f->owner);

    Livewire::test(CreateSauda::class)
        ->fillForm([
            'party_id' => $this->f->seller->id, 'product_id' => $this->f->paddy->id,
            'quantity_quintal' => '1250.5', 'rate_rupees' => '2650.75', 'tolerance_percent' => '5',
            'brokerage_rupees' => '10', 'moisture_max_percent' => '17', 'payment_days' => 15,
            'delivery_from' => now()->toDateString(), 'delivery_to' => now()->addDays(20)->toDateString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $s = Sauda::query()->latest('created_at')->firstOrFail();
    expect($s->quantity_kg)->toBe('125050.000')
        ->and($s->rate_paise_per_quintal)->toBe(265075)
        ->and($s->brokerage_paise_per_quintal)->toBe(1000)
        ->and($s->quality_terms)->toBe(['moisture_max_bp' => 1700])
        ->and($s->status)->toBe('draft');

    Livewire::test(ViewSauda::class, ['record' => $s->id])->callAction('submit');
    expect($s->fresh()->status)->toBe('pending_approval');
    // The maker sees approve but the service refuses (maker-checker).
    Livewire::test(ViewSauda::class, ['record' => $s->id])->callAction('approve', ['note' => 'x'])->assertNotified();
    expect($s->fresh()->status)->toBe('pending_approval');
});

it('runs a vehicle visit from the gate screens', function () {
    $sauda = $this->f->confirmedSauda();
    $sup = Make::account($this->f->entity, 'SUPERVISOR');
    signInAs($this, $sup);

    Livewire::test(CreateGateEntry::class)
        ->fillForm(['registration_no' => 'hr05ab1234', 'direction' => 'inbound', 'sauda_id' => $sauda->id, 'bag_count' => 400])
        ->call('create')
        ->assertHasNoFormErrors();
    $entry = GateEntry::query()->sole();

    // Weights come from the indicator (agent); simulate that part through the service.
    app(GateService::class)->recordWeighment($entry, 'gross', '25480', $this->f->scale, GateFixtures::readings('25480'), str_repeat('c', 64));

    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])
        ->callAction('labTest', ['moisture' => '18.5', 'foreign_matter' => '3.5', 'grade' => 'A'])
        ->assertNotified('Lab test recorded');
    expect(LabTest::query()->sole()->moisture_bp)->toBe(1850);

    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])->callAction('accept', ['note' => null]);
    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])->callAction('unloaded');
    app(GateService::class)->recordWeighment($entry, 'tare', '10230', $this->f->scale, GateFixtures::readings('10230'), str_repeat('d', 64));
    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])->callAction('slip')->assertNotified('Slip issued');
    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])->callAction('download')->assertFileDownloaded();
    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])->callAction('exit');

    expect($entry->fresh()->status)->toBe('exited')->and($sauda->fresh()->delivered_kg)->toBe('14781.250');
});

it('does not offer any way to type a weight except the Super Admin override', function () {
    $sup = Make::account($this->f->entity, 'SUPERVISOR');
    signInAs($this, $sup);
    $entry = app(GateService::class)->arrive(['registration_no' => 'HR05AB1234']);

    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])->assertActionHidden('manualWeight');

    $sa = Make::superAdmin($this->f->entity);
    app('auth')->forgetGuards();
    $this->flushSession();
    signInAs($this, $sa);
    Livewire::test(ViewGateEntry::class, ['record' => $entry->id])
        ->callAction('manualWeight', ['kind' => 'gross', 'weight_kg' => '25000', 'reason' => 'Indicator display failed during weighment'])
        ->assertNotified('Sent for approval');
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

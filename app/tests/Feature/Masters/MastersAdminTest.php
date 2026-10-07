<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Domain\Sync\SyncService;
use App\Filament\Resources\Masters\PartyResource\Pages\CreateParty;
use App\Filament\Resources\Masters\PartyResource\Pages\EditParty;
use App\Filament\Resources\Masters\PartyResource\RelationManagers\BankAccountsRelationManager;
use App\Filament\Resources\Masters\VehicleResource\Pages\ManageVehicles;
use App\Filament\Resources\SyncConflictResource\Pages\ViewSyncConflict;
use App\Models\ApprovalRequest;
use App\Models\Device;
use App\Models\LoginAccount;
use App\Models\Masters\Party;
use App\Models\Masters\Vehicle;
use App\Models\SyncConflict;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Make;

beforeEach(function () {
    $this->entity = Make::entity('ADM');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function signIn(object $test, LoginAccount $account): void
{
    $test->actingAs($account, 'web')->withSession(Make::webSession($account));
    app(AuthContextBinder::class)->bind($account);
}

dataset('master screens', [
    // path, roles that may open it
    'parties' => ['/admin/masters/parties', ['SUPER_ADMIN', 'OWNER', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER', 'LAB_TECHNICIAN', 'GATE_OPERATOR']],
    'vehicles' => ['/admin/masters/vehicles', ['SUPER_ADMIN', 'OWNER', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER', 'LAB_TECHNICIAN', 'GATE_OPERATOR']],
    'products' => ['/admin/masters/products', ['SUPER_ADMIN', 'OWNER', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER', 'LAB_TECHNICIAN', 'GATE_OPERATOR']],
    'sync review' => ['/admin/sync-review', ['SUPER_ADMIN', 'OWNER', 'SUPERVISOR']],
]);

it('opens master screens only for permitted roles', function (string $path, array $allowed) {
    foreach (['SUPER_ADMIN', 'OWNER', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER', 'LAB_TECHNICIAN', 'GATE_OPERATOR'] as $role) {
        $account = Make::account($this->entity, $role, ['mfa' => true]);
        $status = $this->actingAs($account, 'web')->withSession(Make::webSession($account))->get($path)->getStatusCode();
        app('auth')->forgetGuards();
        $this->flushSession();

        expect($status)->toBe(in_array($role, $allowed, true) ? 200 : 403, "{$role} → {$path}");
    }
})->with('master screens');

it('lets the gate operator register a vehicle but not edit parties', function () {
    $gate = Make::account($this->entity, 'GATE_OPERATOR');
    signIn($this, $gate);

    Livewire::test(ManageVehicles::class)
        ->callAction('create', ['registration_no' => 'up 32 ab 4567', 'vehicle_type' => 'truck', 'status' => 'active'])
        ->assertHasNoActionErrors();
    expect(Vehicle::query()->sole()->registration_no)->toBe('UP32AB4567');

    Livewire::test(ManageVehicles::class)
        ->callAction('create', ['registration_no' => 'UP-32-AB-4567', 'vehicle_type' => 'truck', 'status' => 'active'])
        ->assertHasActionErrors(['registration_no']);

    $this->get('/admin/masters/parties/create')->assertForbidden();
});

it('creates a party with roles, credit limit in paise and validated GSTIN', function () {
    $acc = Make::account($this->entity, 'ACCOUNTANT');
    signIn($this, $acc);

    Livewire::test(CreateParty::class)
        ->fillForm([
            'code' => 'C-001', 'name' => 'Gupta Rice Traders', 'roles' => ['is_customer', 'is_broker'],
            'gst_registration_type' => 'regular', 'gstin' => '27AAPFU0939F1ZW', 'status' => 'active',
        ])
        ->call('create')
        ->assertHasFormErrors(['gstin']);

    Livewire::test(CreateParty::class)
        ->fillForm([
            'code' => 'C-001', 'name' => 'Gupta Rice Traders', 'roles' => ['is_customer', 'is_broker'],
            'phone' => '98765 43210', 'gst_registration_type' => 'regular', 'gstin' => '27aapfu0939f1zv',
            'credit_limit_rupees' => '250000.50', 'credit_days' => 30, 'status' => 'active',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $party = Party::query()->sole();
    expect($party->is_customer && $party->is_broker && ! $party->is_vendor)->toBeTrue()
        ->and($party->credit_limit_paise)->toBe(25000050)
        ->and($party->phone)->toBe('+919876543210')
        ->and($party->gstin)->toBe('27AAPFU0939F1ZV')
        ->and($party->state_code)->toBe('27');
});

it('files a bank account request instead of saving it', function () {
    $owner = Make::account($this->entity, 'OWNER');
    signIn($this, $owner);
    $party = Party::query()->create(['code' => 'V1', 'name' => 'Vendor', 'is_vendor' => true, 'gst_registration_type' => 'unregistered']);

    Livewire::test(BankAccountsRelationManager::class, ['ownerRecord' => $party, 'pageClass' => EditParty::class])
        ->callTableAction('request_add', data: [
            'account_holder' => 'Vendor', 'account_number' => '123456789012', 'account_number_confirmation' => '123456789012',
            'ifsc' => 'HDFC0001234', 'reason' => 'Onboarding',
        ])
        ->assertHasNoTableActionErrors();

    $request = ApprovalRequest::query()->sole();
    expect($request->action)->toBe('party.bank_account.change')
        ->and($request->status)->toBe('pending')
        ->and($party->bankAccounts()->count())->toBe(0);

    // The approval screen never shows the encrypted number.
    $this->get('/admin/approvals/'.$request->id)->assertOk()->assertSee('XXXX9012')->assertDontSee('123456789012');
});

it('lets a supervisor apply or dismiss items in the review queue', function () {
    $gate = Make::account($this->entity, 'GATE_OPERATOR');
    $supervisor = Make::account($this->entity, 'SUPERVISOR');

    // Two devices register the same truck offline with different ids → second one needs review.
    app(AuthContextBinder::class)->bind($gate);
    Make::trustedDevice($gate, 'gate-device-0001-abc', 'pwa');
    $device = Device::query()->sole();
    $events = fn (int $seq, string $id, string $type) => [[
        'event_id' => (string) Str::uuid(), 'client_seq' => $seq, 'occurred_at' => now()->toIso8601ZuluString(),
        'type' => 'vehicle.upsert', 'record_id' => $id, 'payload' => ['registration_no' => 'HR05AB1234', 'vehicle_type' => $type],
    ]];
    app(SyncService::class)->push($gate, $device, $events(1, (string) Str::uuid(), 'truck'));
    app(SyncService::class)->push($gate, $device, $events(2, (string) Str::uuid(), 'tempo'));
    $conflict = SyncConflict::query()->where('review_status', 'open')->sole();
    Make::reset();

    signIn($this, $supervisor);
    // Applying still clashes with the existing truck → refused with an explanation, item stays open.
    Livewire::test(ViewSyncConflict::class, ['record' => $conflict->id])
        ->callAction('apply', ['note' => 'Same truck'])
        ->assertNotified('Not resolved');
    expect($conflict->fresh()->review_status)->toBe('open');

    Livewire::test(ViewSyncConflict::class, ['record' => $conflict->id])
        ->callAction('dismiss', ['note' => 'Duplicate of existing truck HR05AB1234']);
    expect($conflict->fresh())->review_status->toBe('dismissed')
        ->and($conflict->fresh()->reviewed_by)->toBe($supervisor->id);
});

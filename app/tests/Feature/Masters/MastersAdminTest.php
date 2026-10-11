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
        ->callAction('create', ['registration_no' => 'les 22 4567', 'vehicle_type' => 'truck', 'status' => 'active'])
        ->assertHasNoActionErrors();
    expect(Vehicle::query()->sole()->registration_no)->toBe('LES224567');

    Livewire::test(ManageVehicles::class)
        ->callAction('create', ['registration_no' => 'LES-22-4567', 'vehicle_type' => 'truck', 'status' => 'active'])
        ->assertHasActionErrors(['registration_no']);

    $this->get('/admin/masters/parties/create')->assertForbidden();
});

it('creates a party with roles, credit limit in paisa and a validated NTN / STRN', function () {
    $acc = Make::account($this->entity, 'ACCOUNTANT');
    signIn($this, $acc);

    Livewire::test(CreateParty::class)
        ->fillForm([
            'code' => 'C-001', 'name' => 'Lodhran Rice Traders', 'roles' => ['is_customer', 'is_broker'],
            'ntn' => '1234567', 'strn' => '32778765', 'atl_status' => 'unknown', 'status' => 'active',
        ])
        ->call('create')
        ->assertHasFormErrors(['ntn', 'strn']);

    Livewire::test(CreateParty::class)
        ->fillForm([
            'code' => 'C-001', 'name' => 'Lodhran Rice Traders', 'roles' => ['is_customer', 'is_broker'],
            'phone' => '0300 6834588', 'ntn' => '1234567-8', 'strn' => '32-77-8765-432-10', 'cnic' => '36203-1234567-1',
            'province' => 'Punjab', 'atl_status' => 'active', 'atl_checked_on' => now()->toDateString(),
            'credit_limit_rupees' => '250000.50', 'credit_days' => 30, 'status' => 'active',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $party = Party::query()->sole();
    expect($party->is_customer && $party->is_broker && ! $party->is_vendor)->toBeTrue()
        ->and($party->credit_limit_paise)->toBe(25000050)
        ->and($party->phone)->toBe('+923006834588')
        ->and($party->ntn)->toBe('12345678')
        ->and($party->strn)->toBe('3277876543210')
        ->and($party->cnic)->toBe('3620312345671')
        ->and($party->tax_registration)->toBe('registered')
        ->and($party->isFiler())->toBeTrue();
});

it('files a bank account request instead of saving it', function () {
    $owner = Make::account($this->entity, 'OWNER');
    signIn($this, $owner);
    $party = Party::query()->create(['code' => 'V1', 'name' => 'Vendor', 'is_vendor' => true]);

    Livewire::test(BankAccountsRelationManager::class, ['ownerRecord' => $party, 'pageClass' => EditParty::class])
        ->callTableAction('request_add', data: [
            'account_holder' => 'Vendor', 'account_number' => 'PK36SCBL0000001123456702', 'account_number_confirmation' => 'PK36SCBL0000001123456702',
            'bank_name' => 'Standard Chartered', 'reason' => 'Onboarding',
        ])
        ->assertHasNoTableActionErrors();

    $request = ApprovalRequest::query()->sole();
    expect($request->action)->toBe('party.bank_account.change')
        ->and($request->status)->toBe('pending')
        ->and($party->bankAccounts()->count())->toBe(0);

    // The approval screen never shows the encrypted number.
    $this->get('/admin/approvals/'.$request->id)->assertOk()->assertSee('XXXX6702')->assertDontSee('PK36SCBL0000001123456702');
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
        'type' => 'vehicle.upsert', 'record_id' => $id, 'payload' => ['registration_no' => 'MNA211234', 'vehicle_type' => $type],
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
        ->callAction('dismiss', ['note' => 'Duplicate of existing truck MNA211234']);
    expect($conflict->fresh())->review_status->toBe('dismissed')
        ->and($conflict->fresh()->reviewed_by)->toBe($supervisor->id);
});

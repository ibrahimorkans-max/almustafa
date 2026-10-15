<?php

declare(strict_types=1);

use App\Domain\Alerts\AlertService;
use App\Domain\Production\ProductionService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Filament\Resources\AlertResource\Pages\ListAlerts;
use App\Filament\Resources\Inventory\RequisitionResource\Pages\CreateRequisition;
use App\Filament\Resources\Inventory\RequisitionResource\Pages\ViewRequisition;
use App\Filament\Resources\Inventory\StockBalanceResource\Pages\ListStockBalances;
use App\Filament\Resources\Inventory\StockCountResource\Pages\ListStockCounts;
use App\Filament\Resources\Production\OutturnStandardResource\Pages\ManageOutturnStandards;
use App\Filament\Resources\Production\ProductionBatchResource\Pages\CreateProductionBatch;
use App\Filament\Resources\Production\ProductionBatchResource\Pages\ViewProductionBatch;
use App\Models\Alert;
use App\Models\Inventory\Requisition;
use App\Models\Inventory\StockCount;
use App\Models\LoginAccount;
use App\Models\Production\OutturnStandard;
use App\Models\Production\ProductionBatch;
use App\Models\Production\ProductionLog;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\InventoryFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new InventoryFixtures(Make::entity('ADM4'));
    $this->f->as($this->f->storeManager);
    $this->f->stock($this->f->paddy, $this->f->silo, '20000');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function signInP4(object $t, LoginAccount $account): void
{
    $t->actingAs($account, 'web')->withSession(Make::webSession($account));
    $t->f->as($account);
}

dataset('phase 4 screens', [
    'stock' => ['/admin/stock', ['OWNER', 'SUPER_ADMIN', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER']],
    'ledger' => ['/admin/stock-ledger', ['OWNER', 'SUPER_ADMIN', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER']],
    'requisitions' => ['/admin/requisitions', ['OWNER', 'SUPER_ADMIN', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER']],
    'batches' => ['/admin/batches', ['OWNER', 'SUPER_ADMIN', 'EXECUTIVE', 'SUPERVISOR', 'INVENTORY_MANAGER', 'LAB_TECHNICIAN']],
    'outturn' => ['/admin/outturn-standards', ['OWNER', 'SUPER_ADMIN', 'EXECUTIVE', 'SUPERVISOR', 'INVENTORY_MANAGER', 'LAB_TECHNICIAN']],
    'counts' => ['/admin/stock-counts', ['OWNER', 'SUPER_ADMIN', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER']],
    'alerts' => ['/admin/alerts', ['OWNER', 'SUPER_ADMIN', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER', 'LAB_TECHNICIAN']],
]);

it('opens stock and production screens only for the right roles', function (string $path, array $allowed) {
    foreach (['OWNER', 'SUPER_ADMIN', 'EXECUTIVE', 'ACCOUNTANT', 'SUPERVISOR', 'INVENTORY_MANAGER', 'LAB_TECHNICIAN', 'GATE_OPERATOR'] as $role) {
        $account = Make::account($this->f->entity, $role, ['mfa' => true]);
        $status = $this->actingAs($account, 'web')->withSession(Make::webSession($account))->get($path)->getStatusCode();
        app('auth')->forgetGuards();
        $this->flushSession();

        expect($status)->toBe(in_array($role, $allowed, true) ? 200 : 403, "{$role} → {$path}");
    }
})->with('phase 4 screens');

it('shows stock in hand grouped by category', function () {
    signInP4($this, $this->f->supervisor);

    Livewire::test(ListStockBalances::class)->assertSee('Paddy 1121')->assertSee('20000.000')->assertSee('Paddy');
});

it('runs a batch from the screens: open, requisition, logs, correction, close', function () {
    signInP4($this, $this->f->supervisor);
    Livewire::test(CreateProductionBatch::class)
        ->fillForm(['paddy_product_id' => $this->f->paddy->id, 'via_requisition' => true, 'line' => 'Line A', 'opened_at' => now()->subHours(3)->format('Y-m-d H:i')])
        ->call('create')->assertHasNoFormErrors();
    $batch = ProductionBatch::query()->sole();

    Livewire::test(CreateRequisition::class)
        ->fillForm(['destination' => 'batch', 'to_batch_id' => $batch->id, 'product_id' => $this->f->paddy->id, 'from_godown_id' => $this->f->silo->id, 'quantity_kg' => '10000'])
        ->call('create')->assertHasNoFormErrors();
    $req = Requisition::query()->sole();
    Livewire::test(ViewRequisition::class, ['record' => $req->id])->callAction('approve')->assertNotified('The requester cannot approve their own requisition.');

    signInP4($this, $this->f->storeManager);
    Livewire::test(ViewRequisition::class, ['record' => $req->id])->callAction('approve', ['note' => 'ok'])->assertNotified('Approved');
    Livewire::test(ViewRequisition::class, ['record' => $req->id])->callAction('issue')->assertNotified('Stock issued');

    signInP4($this, $this->f->supervisor);
    $period = ['period_start' => now()->subHours(2)->format('Y-m-d H:i'), 'period_end' => now()->subHour()->format('Y-m-d H:i')];
    foreach ([[$this->f->rice, '6600'], [$this->f->broken, '480'], [$this->f->bran, '700'], [$this->f->husk, '2050']] as [$product, $kg]) {
        Livewire::test(ViewProductionBatch::class, ['record' => $batch->id])
            ->callAction('output', ['product_id' => $product->id, 'godown_id' => $this->f->store->id, 'quantity_kg' => $kg] + $period)
            ->assertNotified('Output logged');
    }
    $husk = ProductionLog::query()->where('product_id', $this->f->husk->id)->sole();
    Livewire::test(ViewProductionBatch::class, ['record' => $batch->id])
        ->callAction('correct', ['log_id' => $husk->id, 'reason' => 'Husk weighed twice'])->assertNotified('Log corrected');
    Livewire::test(ViewProductionBatch::class, ['record' => $batch->id])
        ->callAction('output', ['product_id' => $this->f->husk->id, 'godown_id' => $this->f->store->id, 'quantity_kg' => '2150'] + $period);

    Livewire::test(ViewProductionBatch::class, ['record' => $batch->id])->assertSee('Yield so far')->assertSee('66%');
    Livewire::test(ViewProductionBatch::class, ['record' => $batch->id])->callAction('close', ['notes' => 'Shift end'])->assertNotified('Batch closed');

    $batch->refresh();
    expect($batch->status)->toBe('closed')
        ->and($batch->input_kg)->toBe('10000.000')
        ->and($batch->yield_snapshot['unaccounted_kg'])->toBe('70.000')
        ->and(Alert::query()->count())->toBe(0);
    Livewire::test(ViewProductionBatch::class, ['record' => $batch->id])->assertActionHidden('output')->assertSee('Yield (frozen at close)');
});

it('records a stock count and sends the difference for approval', function () {
    signInP4($this, $this->f->storeManager);

    Livewire::test(ListStockCounts::class)
        ->callAction('count', ['product_id' => $this->f->paddy->id, 'godown_id' => $this->f->silo->id, 'counted_kg' => '19950', 'note' => 'Silo dip'])
        ->assertNotified('Difference of -50.000 kg sent for approval');

    $count = StockCount::query()->sole();
    expect($count->status)->toBe('pending')->and($count->approval_request_id)->not->toBeNull();
});

it('edits outturn standards in percent and stores basis points', function () {
    signInP4($this, $this->f->supervisor);
    OutturnStandard::query()->delete();

    Livewire::test(ManageOutturnStandards::class)
        ->callAction('create', ['paddy_product_id' => $this->f->paddy->id, 'output_product_id' => $this->f->rice->id, 'expected_bp' => '66.5', 'tolerance_bp' => '1.5', 'status' => 'active'])
        ->assertHasNoActionErrors();

    expect(OutturnStandard::query()->sole())->expected_bp->toBe(6650)->tolerance_bp->toBe(150);
});

it('acknowledges alerts with a note, by the responsible role only', function () {
    signInP4($this, $this->f->supervisor);
    $batch = app(ProductionService::class)->open($this->f->paddy);
    $alert = app(AlertService::class)->raise('unaccounted_loss', 'critical', 'Batch loss', null, $batch, [], 'batch.manage');

    // Since HR phase 12 an alert is listed only for holders of its permission; the service still refuses others.
    signInP4($this, $this->f->storeManager);
    Livewire::test(ListAlerts::class)->assertCanNotSeeTableRecords([$alert])->assertDontSee('Batch loss');
    expect(fn () => app(AlertService::class)->acknowledge($alert, 'Not mine to close'))
        ->toThrow(BusinessRuleViolation::class, "You need 'batch.manage' to acknowledge this alert.");

    signInP4($this, $this->f->supervisor);
    Livewire::test(ListAlerts::class)->assertSee('Batch loss')
        ->callTableAction('acknowledge', $alert, ['note' => 'Husk not weighed on night shift']);
    expect($alert->fresh())->status->toBe('acknowledged')->acknowledgement_note->toBe('Husk not weighed on night shift');
});

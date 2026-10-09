<?php

declare(strict_types=1);

use App\Domain\Inventory\StockLedger;
use App\Filament\Resources\Production\ProductionBatchResource\Pages\CreateProductionBatch;
use App\Filament\Resources\Production\ProductionBatchResource\Pages\ListProductionBatches;
use App\Models\AuditLog;
use App\Models\Inventory\StockEntry;
use App\Models\LoginAccount;
use App\Models\Production\ProductionBatch;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\InventoryFixtures;
use Tests\Support\Make;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->f = new InventoryFixtures(Make::entity('CBT'));
    $this->f->as($this->f->storeManager);
    $this->f->stock($this->f->paddy, $this->f->silo, '20000');
});

function signInBatch(object $t, LoginAccount $account): void
{
    $t->actingAs($account, 'web')->withSession(Make::webSession($account));
    $t->f->as($account);
}

function batchForm(object $t, array $overrides = []): array
{
    return $overrides + [
        'paddy_product_id' => $t->f->paddy->id,
        'godown_id' => $t->f->silo->id,
        'issue_kg' => '5000',
        'planned_kg' => '18000',
        'line' => 'Line A',
        'supervisor_login_account_id' => $t->f->supervisor2->id,
        'opened_at' => now()->subHour()->format('Y-m-d H:i'),
        'notes' => 'PR-126 lot from gate',
    ];
}

it('shows a Create batch button to roles that may open batches, and not to others', function () {
    signInBatch($this, $this->f->supervisor);
    Livewire::test(ListProductionBatches::class)->assertActionVisible('create')->assertSee('Create batch');

    $lab = Make::account($this->f->entity, 'LAB_TECHNICIAN');
    signInBatch($this, $lab);
    $this->get('/admin/batches/open')->assertForbidden();
});

it('creates a batch with a number, plan and supervisor, and issues the paddy through the stock ledger', function () {
    signInBatch($this, $this->f->supervisor);
    Livewire::test(CreateProductionBatch::class)->fillForm(batchForm($this))->call('create')->assertHasNoFormErrors();

    $batch = ProductionBatch::query()->firstOrFail();
    expect($batch->number)->not->toBeEmpty()
        ->and($batch->planned_kg)->toBe('18000.000')
        ->and($batch->supervisor_login_account_id)->toBe($this->f->supervisor2->id)
        ->and($batch->line)->toBe('Line A')
        ->and($batch->input_kg)->toBe('5000.000')
        ->and(app(StockLedger::class)->balance($this->f->paddy, $this->f->silo)->kg)->toBe('15000.000');

    $issue = StockEntry::query()->where('product_id', $this->f->paddy->id)->where('quantity_kg', '<', 0)->latest('created_at')->first();
    expect($issue)->not->toBeNull()->and($issue->quantity_kg)->toBe('-5000.000');
    expect(AuditLog::query()->where('subject_type', 'production_batches')->where('subject_id', $batch->id)->exists())->toBeTrue();
});

it('refuses to create a batch when the godown does not hold enough paddy, and creates nothing', function () {
    signInBatch($this, $this->f->supervisor);
    Livewire::test(CreateProductionBatch::class)->fillForm(batchForm($this, ['issue_kg' => '25000']))->call('create')
        ->assertHasFormErrors(['issue_kg']);

    expect(ProductionBatch::query()->count())->toBe(0)
        ->and(app(StockLedger::class)->balance($this->f->paddy, $this->f->silo)->kg)->toBe('20000.000');
});

it('numbers batches without gaps even after a refused attempt', function () {
    signInBatch($this, $this->f->supervisor);
    Livewire::test(CreateProductionBatch::class)->fillForm(batchForm($this, ['issue_kg' => '25000']))->call('create');
    Livewire::test(CreateProductionBatch::class)->fillForm(batchForm($this, ['issue_kg' => '1000']))->call('create')->assertHasNoFormErrors();
    Livewire::test(CreateProductionBatch::class)->fillForm(batchForm($this, ['issue_kg' => '1000']))->call('create')->assertHasNoFormErrors();

    $numbers = ProductionBatch::query()->orderBy('created_at')->pluck('number')->all();
    preg_match('/(\d+)$/', $numbers[0], $a);
    preg_match('/(\d+)$/', $numbers[1], $b);
    expect((int) $b[1])->toBe((int) $a[1] + 1)->and((int) $a[1])->toBe(1);
});

it('can open a batch empty when the paddy will come through a stores requisition', function () {
    signInBatch($this, $this->f->supervisor);
    Livewire::test(CreateProductionBatch::class)
        ->fillForm(batchForm($this, ['via_requisition' => true, 'issue_kg' => null, 'godown_id' => null]))->call('create')->assertHasNoFormErrors();
    expect(ProductionBatch::query()->sole()->input_kg)->toBe('0.000')
        ->and(app(StockLedger::class)->balance($this->f->paddy, $this->f->silo)->kg)->toBe('20000.000');
});

it('requires the issue quantity and godown', function () {
    signInBatch($this, $this->f->supervisor);
    Livewire::test(CreateProductionBatch::class)->fillForm(batchForm($this, ['issue_kg' => null, 'godown_id' => null]))->call('create')
        ->assertHasFormErrors(['issue_kg' => 'required', 'godown_id' => 'required']);
});

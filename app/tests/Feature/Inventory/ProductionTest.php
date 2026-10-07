<?php

declare(strict_types=1);

use App\Domain\Inventory\StockLedger;
use App\Domain\Production\ProductionService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Alert;
use App\Models\Production\OutturnStandard;
use App\Models\Production\ProductionBatch;
use App\Models\Production\ProductionLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\InventoryFixtures;
use Tests\Support\Make;

beforeEach(function () {
    $this->f = new InventoryFixtures(Make::entity('PRD'));
    $this->f->as($this->f->storeManager);
    $this->f->stock($this->f->paddy, $this->f->silo, '20000');
    $this->f->as($this->f->supervisor);
    $this->production = app(ProductionService::class);
    $this->stock = app(StockLedger::class);
});

function prodHour(int $h): array
{
    return [now()->startOfDay()->addHours($h), now()->startOfDay()->addHours($h + 1)];
}

function prodOutput(object $t, ProductionBatch $b, $product, string $kg, int $h = 9): ProductionLog
{
    return $t->production->recordOutput($b, $product, $t->f->store, $kg, ...prodHour($h));
}

it('opens numbered batches for paddy only', function () {
    $batch = $this->production->open($this->f->paddy, 'Line A', null, now()->startOfDay());

    expect($batch->number)->toStartWith('PRD/B/')->and($batch->isOpen())->toBeTrue();
    expect(fn () => $this->production->open($this->f->rice))->toThrow(BusinessRuleViolation::class, 'paddy');
});

it('moves stock with every log and keeps batch totals as a projection', function () {
    $batch = $this->production->open($this->f->paddy, openedAt: now()->startOfDay());
    $this->production->recordInput($batch, $this->f->silo, '10000', ...prodHour(8));
    prodOutput($this, $batch, $this->f->rice, '6450');
    prodOutput($this, $batch, $this->f->husk, '2000');

    $batch->refresh();
    expect($batch->input_kg)->toBe('10000.000')->and($batch->output_kg)->toBe('8450.000')
        ->and($this->stock->balance($this->f->paddy, $this->f->silo)->kg)->toBe('10000.000')
        ->and($this->stock->balance($this->f->rice, $this->f->store)->kg)->toBe('6450.000')
        ->and($this->stock->verify())->toBe([]);
});

it('refuses to feed more paddy than the godown holds', function () {
    $batch = $this->production->open($this->f->paddy, openedAt: now()->startOfDay());

    expect(fn () => $this->production->recordInput($batch, $this->f->silo, '20000.5', ...prodHour(8)))
        ->toThrow(BusinessRuleViolation::class, 'Not enough');
    expect(ProductionLog::query()->count())->toBe(0)->and($batch->fresh()->input_kg)->toBe('0.000');
});

it('validates logs: product kind, quantity and period', function () {
    $batch = $this->production->open($this->f->paddy, openedAt: now()->startOfDay());

    expect(fn () => prodOutput($this, $batch, $this->f->paddy, '10'))->toThrow(BusinessRuleViolation::class, 'not rice or a by-product');
    expect(fn () => prodOutput($this, $batch, $this->f->rice, '0'))->toThrow(BusinessRuleViolation::class);
    expect(fn () => prodOutput($this, $batch, $this->f->rice, '-5'))->toThrow(BusinessRuleViolation::class);
    [$start, $end] = prodHour(9);
    expect(fn () => $this->production->recordOutput($batch, $this->f->rice, $this->f->store, '10', $end, $start))
        ->toThrow(BusinessRuleViolation::class, 'end after');
});

it('corrects a wrong log with a reversing log and moves the stock back', function () {
    $batch = $this->production->open($this->f->paddy, openedAt: now()->startOfDay());
    $this->production->recordInput($batch, $this->f->silo, '5000', ...prodHour(8));
    $wrong = prodOutput($this, $batch, $this->f->rice, '3400');

    $fix = $this->production->reverse($wrong, 'Typed 3400 instead of 3040');
    prodOutput($this, $batch, $this->f->rice, '3040');

    expect($fix->reverses_id)->toBe($wrong->id)
        ->and($batch->fresh()->output_kg)->toBe('3040.000')
        ->and($this->stock->balance($this->f->rice, $this->f->store)->kg)->toBe('3040.000')
        ->and(ProductionLog::query()->count())->toBe(4);
    expect(fn () => $this->production->reverse($wrong, 'second time'))->toThrow(BusinessRuleViolation::class, 'already corrected');
    expect(fn () => $this->production->reverse($fix, 'undo the undo'))->toThrow(BusinessRuleViolation::class);
    expect(fn () => DB::table('production_logs')->where('id', $wrong->id)->update(['quantity_kg' => '1']))->toThrow(QueryException::class);
});

it('freezes the yield at close and raises alerts for drift and unaccounted loss', function () {
    $batch = $this->production->open($this->f->paddy, openedAt: now()->startOfDay());
    $this->production->recordInput($batch, $this->f->silo, '10000', ...prodHour(8));
    prodOutput($this, $batch, $this->f->rice, '6000');   // 60.00 % vs 65 ± 2 → drift
    prodOutput($this, $batch, $this->f->broken, '500');  // on standard
    prodOutput($this, $batch, $this->f->bran, '700');    // on standard
    prodOutput($this, $batch, $this->f->husk, '2000');   // on standard → 800 kg (8 %) unaccounted

    $closed = $this->production->close($batch);
    $snapshot = $closed->yield_snapshot;

    expect($closed->status)->toBe('closed')
        ->and($snapshot['input_kg'])->toBe('10000.000')
        ->and($snapshot['unaccounted_kg'])->toBe('800.000')
        ->and($snapshot['unaccounted_bp'])->toBe(800)
        ->and($snapshot['outturn'][$this->f->rice->id])->toMatchArray(['bp' => 6000, 'expected_bp' => 6500, 'drift_bp' => -500, 'out_of_range' => true])
        ->and($snapshot['outturn'][$this->f->bran->id]['out_of_range'])->toBeFalse();

    $alerts = Alert::query()->orderBy('type')->get();
    expect($alerts->pluck('type')->all())->toBe(['outturn_drift', 'unaccounted_loss'])
        ->and($alerts[1]->severity)->toBe('critical')
        ->and($alerts[1]->subject_id)->toBe($batch->id);

    // In-app notification for people who manage batches.
    expect(DB::table('notifications')->where('notifiable_id', $this->f->supervisor2->id)->count())->toBe(2);

    // Closed means closed: no more logs, and the snapshot no longer changes.
    expect(fn () => prodOutput($this, $closed, $this->f->rice, '10'))->toThrow(BusinessRuleViolation::class, 'closed');
    $this->f->as($this->f->gate->owner);
    OutturnStandard::query()->where('output_product_id', $this->f->rice->id)->update(['expected_bp' => 6000]);
    expect(app(ProductionService::class)->yieldOf($closed->fresh())['outturn'][$this->f->rice->id]['expected_bp'])->toBe(6500);
});

it('does not alert when the batch is within the standards', function () {
    $batch = $this->production->open($this->f->paddy, openedAt: now()->startOfDay());
    $this->production->recordInput($batch, $this->f->silo, '10000', ...prodHour(8));
    foreach ([[$this->f->rice, '6550'], [$this->f->broken, '480'], [$this->f->bran, '720'], [$this->f->husk, '2150']] as [$p, $kg]) {
        prodOutput($this, $batch, $p, $kg);
    }
    $this->production->close($batch);

    expect(Alert::query()->count())->toBe(0);
});

it('lets only production staff log and only batch managers open and close', function () {
    $this->f->as($this->f->storeManager);
    expect(fn () => $this->production->open($this->f->paddy))->toThrow(BusinessRuleViolation::class, 'batch.manage');

    $this->f->as($this->f->supervisor);
    $batch = $this->production->open($this->f->paddy, openedAt: now()->startOfDay());
    $this->f->as($this->f->storeManager);
    expect(fn () => $this->production->recordInput($batch, $this->f->silo, '10', ...prodHour(8)))->toThrow(BusinessRuleViolation::class, 'production.log');
});

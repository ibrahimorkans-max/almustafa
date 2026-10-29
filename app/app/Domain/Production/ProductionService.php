<?php

declare(strict_types=1);

namespace App\Domain\Production;

use App\Domain\Accounts\StockValuation;
use App\Domain\Alerts\AlertService;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Inventory\StockLedger;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Inventory\StockEntry;
use App\Models\LoginAccount;
use App\Models\Masters\Godown;
use App\Models\Masters\Product;
use App\Models\Production\OutturnStandard;
use App\Models\Production\ProductionBatch;
use App\Models\Production\ProductionLog;
use App\Support\Values\Weight;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Milling batches. Every input/output is an append-only production log that moves stock in
 * the same transaction (paddy out of a godown, rice/by-products into one). The batch's
 * input/output totals are a projection; the yield is frozen when the batch closes.
 */
final class ProductionService
{
    public const OUTPUT_CATEGORIES = ['rice', 'by_product'];

    public function __construct(
        private readonly StockLedger $stock,
        private readonly DocumentNumbers $numbers,
        private readonly YieldCalculator $yield,
        private readonly AlertService $alerts,
        private readonly StockValuation $valuation,
        private readonly ActorContext $actor,
    ) {}

    public function open(
        Product $paddy,
        ?string $line = null,
        ?string $notes = null,
        ?CarbonInterface $openedAt = null,
        ?string $plannedKg = null,
        ?LoginAccount $supervisor = null,
    ): ProductionBatch {
        $this->require('batch.manage');
        if ($paddy->category !== 'paddy') {
            throw new BusinessRuleViolation('A batch mills a paddy product.');
        }
        if ($plannedKg !== null && ! preg_match('/^\d{1,12}(\.\d{1,3})?$/', $plannedKg)) {
            throw new BusinessRuleViolation('The planned quantity must be a weight in kg.');
        }
        if ($supervisor !== null && (! $supervisor->isActive() || ! ($supervisor->hasPermission('production.log') || $supervisor->hasPermission('batch.manage')))) {
            throw new BusinessRuleViolation('The supervisor must be an active user who may record production.');
        }

        return DB::transaction(fn () => ProductionBatch::query()->create([
            'number' => $this->numbers->next(DocumentNumbers::BATCH, $openedAt),
            'paddy_product_id' => $paddy->id,
            'line' => $line,
            'planned_kg' => $plannedKg,
            'supervisor_login_account_id' => $supervisor?->id,
            'opened_at' => $openedAt ?? now(),
            'notes' => $notes,
        ]));
    }

    /**
     * Create batch: opens it and issues the paddy taken for it from the godown to production, in one
     * transaction. The stock ledger refuses an issue larger than the godown holds, and then nothing
     * is created.
     */
    public function create(
        Product $paddy,
        Godown $godown,
        string $issueKg,
        CarbonInterface $openedAt,
        ?string $line = null,
        ?string $plannedKg = null,
        ?LoginAccount $supervisor = null,
        ?string $notes = null,
    ): ProductionBatch {
        return DB::transaction(function () use ($paddy, $godown, $issueKg, $openedAt, $line, $plannedKg, $supervisor, $notes): ProductionBatch {
            $batch = $this->open($paddy, $line, $notes, $openedAt, $plannedKg, $supervisor);
            $end = now()->greaterThan($openedAt->copy()->addMinute()) ? now() : $openedAt->copy()->addMinute();
            $this->recordInput($batch, $godown, $issueKg, $openedAt, $end, 'Issued to production when the batch was created');

            return $batch->refresh();
        });
    }

    /** Paddy fed into the mill: issues it from the godown. */
    public function recordInput(
        ProductionBatch $batch,
        Godown $godown,
        string $quantityKg,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        ?string $note = null,
        ?string $requisitionId = null,
        ?string $id = null,
    ): ProductionLog {
        $this->require('production.log');

        return $this->append($batch, ProductionLog::INPUT, $batch->paddy, $godown, $quantityKg, $periodStart, $periodEnd, $note, $requisitionId, $id);
    }

    /**
     * Input booked by the stores when an approved requisition is issued to the batch.
     * The caller (RequisitionService) has already checked the store's permissions.
     */
    public function recordRequisitionInput(ProductionBatch $batch, Godown $godown, string $quantityKg, string $requisitionId, string $note): ProductionLog
    {
        $now = now();

        return $this->append($batch, ProductionLog::INPUT, $batch->paddy, $godown, $quantityKg, $now->copy()->subMinute(), $now, $note, $requisitionId, null);
    }

    /** Rice or a by-product (bran, husk, broken, …) coming off the line into a godown. */
    public function recordOutput(
        ProductionBatch $batch,
        Product $product,
        Godown $godown,
        string $quantityKg,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        ?string $note = null,
        ?string $id = null,
    ): ProductionLog {
        $this->require('production.log');
        if (! in_array($product->category, self::OUTPUT_CATEGORIES, true)) {
            throw new BusinessRuleViolation("{$product->name} is not rice or a by-product.");
        }

        return $this->append($batch, ProductionLog::OUTPUT, $product, $godown, $quantityKg, $periodStart, $periodEnd, $note, null, $id);
    }

    /**
     * Several outputs of one run at once (head rice, broken, bran, husk …): all are booked, or none.
     * Rows with the same product and godown are added together.
     *
     * @param  list<array{product: Product, godown: Godown, quantity_kg: string}>  $rows
     * @return list<ProductionLog>
     */
    public function recordOutputs(ProductionBatch $batch, array $rows, CarbonInterface $periodStart, CarbonInterface $periodEnd, ?string $note = null): array
    {
        $this->require('production.log');
        if ($rows === []) {
            throw new BusinessRuleViolation('Add at least one output.');
        }
        $merged = [];
        foreach ($rows as $row) {
            if (! preg_match('/^\d{1,12}(\.\d{1,3})?$/', $row['quantity_kg'])) {
                throw new BusinessRuleViolation('Quantity must be a positive weight in kg.');
            }
            $key = $row['product']->id.'|'.$row['godown']->id;
            $merged[$key] = isset($merged[$key])
                ? ['quantity_kg' => Weight::kg($merged[$key]['quantity_kg'])->plus(Weight::kg($row['quantity_kg']))->kg] + $merged[$key]
                : $row;
        }

        return DB::transaction(fn (): array => array_values(array_map(
            fn (array $row) => $this->recordOutput($batch, $row['product'], $row['godown'], $row['quantity_kg'], $periodStart, $periodEnd, $note),
            $merged,
        )));
    }

    /**
     * Input against all outputs and the processing loss: input = Σ outputs + loss. Each output's recovery
     * is its weight ÷ the paddy fed × 100.
     *
     * @return array{input_kg: string, output_kg: string, loss_kg: string, loss_percent: string, rows: list<array{product: string, kg: string, percent: string, expected: string|null}>}
     */
    public function reconciliation(ProductionBatch $batch): array
    {
        $y = $this->yieldOf($batch);
        $pct = fn (int $bp) => number_format($bp / 100, 2).' %';
        $rows = [];
        foreach ((array) ($y['outturn'] ?? []) as $row) {
            $rows[] = ['product' => (string) ($row['product'] ?? ''), 'kg' => (string) $row['kg'], 'percent' => $pct((int) $row['bp']),
                'expected' => $row['expected_bp'] === null ? null : $pct((int) $row['expected_bp'])];
        }

        return [
            'input_kg' => (string) ($y['input_kg'] ?? $batch->input_kg),
            'output_kg' => (string) ($y['output_kg'] ?? $batch->output_kg),
            'loss_kg' => (string) ($y['unaccounted_kg'] ?? '0'),
            'loss_percent' => $pct((int) ($y['unaccounted_bp'] ?? 0)),
            'rows' => $rows,
        ];
    }

    /** Correction of a wrong log: a reversing log with a reason, and the stock moved back. */
    public function reverse(ProductionLog $log, string $reason): ProductionLog
    {
        $this->require('production.log');
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleViolation('Give a reason for the correction.');
        }
        if ($log->isReversal()) {
            throw new BusinessRuleViolation('A correction cannot itself be reversed; record a new log instead.');
        }

        return DB::transaction(function () use ($log, $reason): ProductionLog {
            $batch = $this->lockBatch(ProductionBatch::query()->findOrFail($log->batch_id));
            $this->assertOpen($batch);
            if (ProductionLog::query()->where('reverses_id', $log->id)->exists()) {
                throw new BusinessRuleViolation('This log was already corrected.');
            }

            $ledger = $log->stock_ledger_id ? StockEntry::query()->find($log->stock_ledger_id) : null;
            $reversal = $ledger ? $this->stock->reverse($ledger, $reason) : null;

            $correction = ProductionLog::query()->create([
                'batch_id' => $batch->id,
                'kind' => $log->kind,
                'product_id' => $log->product_id,
                'godown_id' => $log->godown_id,
                'quantity_kg' => $log->quantity_kg,
                'period_start' => $log->period_start,
                'period_end' => $log->period_end,
                'reverses_id' => $log->id,
                'requisition_id' => $log->requisition_id,
                'stock_ledger_id' => $reversal?->id,
                'note' => $reason,
                'device_id' => $this->actor->deviceId(),
                'created_at' => now(),
            ]);
            $this->projectTotals($batch);

            return $correction;
        });
    }

    /**
     * Closes the batch and freezes its yield against the outturn standards of the paddy.
     * Raises alerts for outputs outside tolerance and for loss above the configured limit.
     */
    public function close(ProductionBatch $batch, ?string $notes = null): ProductionBatch
    {
        $this->require('batch.manage');

        return DB::transaction(function () use ($batch, $notes): ProductionBatch {
            $batch = $this->lockBatch($batch);
            $this->assertOpen($batch);
            $this->projectTotals($batch);
            if (bccomp($batch->input_kg, '0', 3) <= 0) {
                throw new BusinessRuleViolation('No paddy was fed into this batch yet, so there is no yield to close.');
            }

            $snapshot = $this->snapshot($batch);
            $batch->forceFill([
                'status' => ProductionBatch::CLOSED,
                'closed_at' => now(),
                'yield_snapshot' => $snapshot,
                'notes' => $notes ?? $batch->notes,
            ])->save();

            $this->raiseYieldAlerts($batch, $snapshot);
            $this->valuation->recordBatch($batch, $this->netQuantities($batch));

            return $batch;
        });
    }

    /**
     * Current (open) or frozen (closed) yield.
     *
     * @return array<string, mixed>
     */
    public function yieldOf(ProductionBatch $batch): array
    {
        return $batch->isOpen() ? $this->snapshot($batch) : (array) $batch->yield_snapshot;
    }

    /**
     * Net quantity per product and kind, reversals subtracted.
     *
     * @return array{input: array<string, string>, output: array<string, string>}
     */
    public function netQuantities(ProductionBatch $batch): array
    {
        $net = ['input' => [], 'output' => []];
        foreach (ProductionLog::query()->where('batch_id', $batch->id)->get() as $log) {
            $current = Weight::kg($net[$log->kind][$log->product_id] ?? '0');
            $q = Weight::kg($log->quantity_kg);
            $net[$log->kind][$log->product_id] = ($log->isReversal() ? $current->minus($q) : $current->plus($q))->kg;
        }

        return $net;
    }

    // ------------------------------------------------------------------ internals

    private function append(
        ProductionBatch $batch,
        string $kind,
        Product $product,
        Godown $godown,
        string $quantityKg,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        ?string $note,
        ?string $requisitionId,
        ?string $id,
    ): ProductionLog {
        if (! preg_match('/^\d{1,12}(\.\d{1,3})?$/', $quantityKg)) {
            throw new BusinessRuleViolation('Quantity must be a positive weight in kg.');
        }
        $quantity = Weight::kg($quantityKg);
        if (bccomp($quantity->kg, '0', 3) <= 0) {
            throw new BusinessRuleViolation('Quantity must be more than zero.');
        }
        if ($periodEnd->lessThanOrEqualTo($periodStart)) {
            throw new BusinessRuleViolation('The period must end after it starts.');
        }

        return DB::transaction(function () use ($batch, $kind, $product, $godown, $quantity, $periodStart, $periodEnd, $note, $requisitionId, $id): ProductionLog {
            $batch = $this->lockBatch($batch);
            $this->assertOpen($batch);
            if ($periodStart->lessThan($batch->opened_at->copy()->subDay())) {
                throw new BusinessRuleViolation('The period starts well before the batch was opened.');
            }

            $log = new ProductionLog([
                'batch_id' => $batch->id,
                'kind' => $kind,
                'product_id' => $product->id,
                'godown_id' => $godown->id,
                'quantity_kg' => $quantity->kg,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'requisition_id' => $requisitionId,
                'note' => $note,
                'device_id' => $this->actor->deviceId(),
                'created_at' => now(),
            ]);
            if ($id !== null) {
                $log->id = $id;
            }
            $log->id ??= $log->newUniqueId();

            $entry = $this->stock->post(
                $product,
                $godown,
                $kind === ProductionLog::INPUT ? Weight::zero()->minus($quantity) : $quantity,
                $kind === ProductionLog::INPUT ? StockEntry::PRODUCTION_ISSUE : StockEntry::PRODUCTION_OUTPUT,
                ['source_type' => 'production_log', 'source_id' => $log->id, 'batch_id' => $batch->id, 'note' => $batch->number],
                $periodEnd,
            );
            $log->stock_ledger_id = $entry->id;
            $log->save();
            $this->projectTotals($batch);

            return $log;
        });
    }

    private function projectTotals(ProductionBatch $batch): void
    {
        $net = $this->netQuantities($batch);
        $sum = fn (array $xs) => array_reduce($xs, fn (Weight $c, string $kg) => $c->plus(Weight::kg($kg)), Weight::zero())->kg;
        $batch->input_kg = $sum($net['input']);
        $batch->output_kg = $sum($net['output']);
        $batch->save();
    }

    /** @return array<string, mixed> */
    private function snapshot(ProductionBatch $batch): array
    {
        $net = $this->netQuantities($batch);
        $standards = OutturnStandard::query()
            ->where('paddy_product_id', $batch->paddy_product_id)->where('status', 'active')->get()
            ->mapWithKeys(fn (OutturnStandard $s) => [$s->output_product_id => ['expected_bp' => $s->expected_bp, 'tolerance_bp' => $s->tolerance_bp]])
            ->all();
        $result = $this->yield->calculate($batch->input_kg, $net['output'], $standards);

        $names = Product::query()->whereIn('id', array_keys($result['outturn']))->pluck('name', 'id');
        foreach ($result['outturn'] as $productId => $row) {
            $result['outturn'][$productId]['product'] = $names[$productId] ?? $productId;
        }
        $result['standards'] = $standards;
        $result['computed_at'] = now()->toIso8601ZuluString();

        return $result;
    }

    /** @param  array<string, mixed>  $snapshot */
    private function raiseYieldAlerts(ProductionBatch $batch, array $snapshot): void
    {
        foreach ($snapshot['outturn'] as $productId => $row) {
            if ($row['out_of_range']) {
                $this->alerts->raise(
                    'outturn_drift',
                    'warning',
                    sprintf('Batch %s: %s outturn %s%% (expected %s%%)', $batch->number, $row['product'], self::pct($row['bp']), self::pct((int) $row['expected_bp'])),
                    sprintf('Drift of %s percentage points exceeds the tolerance of %s.', self::pct((int) $row['drift_bp']), self::pct((int) $row['tolerance_bp'])),
                    $batch,
                    ['batch' => $batch->number, 'product_id' => $productId] + $row,
                    'batch.manage',
                );
            }
        }

        if ($snapshot['unaccounted_bp'] > (int) config('ricemill.inventory.unaccounted_loss_bp')) {
            $this->alerts->raise(
                'unaccounted_loss',
                'critical',
                sprintf('Batch %s: %s kg (%s%%) of paddy unaccounted for', $batch->number, $snapshot['unaccounted_kg'], self::pct($snapshot['unaccounted_bp'])),
                'Input minus all recorded outputs is above the allowed loss. Check unrecorded by-products, weighing and pilferage.',
                $batch,
                ['batch' => $batch->number, 'unaccounted_kg' => $snapshot['unaccounted_kg'], 'unaccounted_bp' => $snapshot['unaccounted_bp']],
                'batch.manage',
            );
        }
    }

    private static function pct(int $bp): string
    {
        return number_format($bp / 100, 2);
    }

    private function lockBatch(ProductionBatch $batch): ProductionBatch
    {
        /** @var ProductionBatch */
        return ProductionBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
    }

    private function assertOpen(ProductionBatch $batch): void
    {
        if (! $batch->isOpen()) {
            throw new BusinessRuleViolation("Batch {$batch->number} is closed.");
        }
    }

    private function require(string $permission): void
    {
        if (! ($this->actor->account()?->hasPermission($permission) ?? false)) {
            throw new BusinessRuleViolation("You need '{$permission}' for this.");
        }
    }
}

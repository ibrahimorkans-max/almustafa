<?php

declare(strict_types=1);

namespace App\Domain\Sync\Handlers;

use App\Domain\Production\ProductionService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Sync\Contracts\SyncHandler;
use App\Domain\Sync\SyncEvent;
use App\Domain\Sync\SyncOutcome;
use App\Models\LoginAccount;
use App\Models\Masters\Godown;
use App\Models\Masters\Product;
use App\Models\Production\ProductionBatch;
use App\Models\Production\ProductionLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Hourly production logs from the plant tablet. Append-only: recorded, rejected with a reason,
 * or "retry" when the godown does not (yet) hold the paddy — typically because the receipt or
 * an earlier transfer is still on another device.
 */
final class ProductionHandlers
{
    /** @return list<SyncHandler> */
    public static function all(ProductionService $production): array
    {
        return [new class($production) implements SyncHandler
        {
            public function __construct(private ProductionService $production) {}

            public function type(): string
            {
                return 'production_log.recorded';
            }

            public function canWrite(LoginAccount $account): bool
            {
                return $account->hasPermission('production.log');
            }

            public function requiresOnlineConfirmation(array $payload): bool
            {
                return false;
            }

            public function apply(SyncEvent $e, LoginAccount $account): SyncOutcome
            {
                if ($e->recordId === null) {
                    return new SyncOutcome(SyncOutcome::REJECTED, reason: 'record_id_required');
                }
                if (ProductionLog::query()->whereKey($e->recordId)->exists()) {
                    return new SyncOutcome(SyncOutcome::APPLIED, ['note' => 'already recorded']);
                }
                $v = Validator::make($e->payload, [
                    'batch_id' => ['required', 'uuid'],
                    'kind' => ['required', Rule::in([ProductionLog::INPUT, ProductionLog::OUTPUT])],
                    'product_id' => ['required_if:kind,output', 'nullable', 'uuid'],
                    'godown_id' => ['required', 'uuid'],
                    'quantity_kg' => ['required', 'regex:/^\d{1,12}(\.\d{1,3})?$/'],
                    'period_start' => ['required', 'date'],
                    'period_end' => ['required', 'date'],
                    'note' => ['nullable', 'string', 'max:1000'],
                ]);
                if ($v->fails()) {
                    return new SyncOutcome(SyncOutcome::REJECTED, ['errors' => $v->errors()->toArray()], 'validation_failed');
                }
                $p = $v->validated();
                $batch = ProductionBatch::query()->find($p['batch_id']);
                $godown = Godown::query()->find($p['godown_id']);
                if ($batch === null || $godown === null) {
                    return new SyncOutcome(SyncOutcome::RETRY, reason: 'reference_not_yet_synced');
                }
                $start = CarbonImmutable::parse($p['period_start']);
                $end = CarbonImmutable::parse($p['period_end']);

                try {
                    if ($p['kind'] === ProductionLog::INPUT) {
                        $log = $this->production->recordInput($batch, $godown, (string) $p['quantity_kg'], $start, $end, $p['note'] ?? null, null, $e->recordId);
                    } else {
                        $product = Product::query()->find($p['product_id']);
                        if ($product === null) {
                            return new SyncOutcome(SyncOutcome::REJECTED, reason: 'unknown_product');
                        }
                        $log = $this->production->recordOutput($batch, $product, $godown, (string) $p['quantity_kg'], $start, $end, $p['note'] ?? null, $e->recordId);
                    }
                } catch (BusinessRuleViolation $ex) {
                    if (str_starts_with($ex->getMessage(), 'Not enough')) {
                        return new SyncOutcome(SyncOutcome::RETRY, ['message' => $ex->getMessage()], 'insufficient_stock');
                    }

                    return new SyncOutcome(SyncOutcome::REJECTED, ['message' => $ex->getMessage()], 'business_rule');
                }

                return new SyncOutcome(SyncOutcome::APPLIED, ['production_log_id' => $log->id, 'stock_ledger_id' => $log->stock_ledger_id]);
            }
        }];
    }
}

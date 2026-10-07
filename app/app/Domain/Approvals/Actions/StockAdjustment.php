<?php

declare(strict_types=1);

namespace App\Domain\Approvals\Actions;

use App\Domain\Approvals\Contracts\ApprovalAction;
use App\Domain\Approvals\Contracts\HandlesRejection;
use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Inventory\StockLedger;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\ApprovalRequest;
use App\Models\Inventory\StockCount;
use App\Models\Inventory\StockEntry;
use App\Models\LoginAccount;
use App\Support\Values\Weight;
use Illuminate\Validation\ValidationException;

/**
 * Book the difference found by a physical stock count. Requested by whoever counted (stock.adjust),
 * approved by a different person who also holds stock.adjust. The difference posted is the one
 * found at counting time, so movements between counting and approval are not undone.
 */
final class StockAdjustment implements ApprovalAction, HandlesRejection
{
    public const KEY = 'stock.adjustment';

    public function __construct(private readonly StockLedger $stock) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function makerPermission(): string
    {
        return 'stock.adjust';
    }

    public function checkerMayDecide(LoginAccount $checker, ApprovalRequest $request): bool
    {
        return $checker->hasPermission('stock.adjust');
    }

    public function validate(array $payload, LoginAccount $maker): array
    {
        $count = StockCount::query()->find($payload['stock_count_id'] ?? null)
            ?? throw ValidationException::withMessages(['stock_count_id' => 'Stock count not found.']);
        if ($count->status !== 'pending') {
            throw ValidationException::withMessages(['stock_count_id' => 'This stock count was already settled.']);
        }

        return [
            'stock_count_id' => $count->id,
            'product' => $count->product->name,
            'godown' => $count->godown->name,
            'system_kg' => $count->system_kg,
            'counted_kg' => $count->counted_kg,
            'difference_kg' => $count->difference_kg,
        ];
    }

    public function execute(ApprovalRequest $request, LoginAccount $checker): array
    {
        /** @var StockCount|null $count */
        $count = StockCount::query()->whereKey($request->payload['stock_count_id'])->lockForUpdate()->first();
        if ($count === null || $count->status !== 'pending') {
            throw new ApprovalDenied('The stock count is no longer pending.');
        }

        try {
            $entry = $this->stock->post(
                $count->product,
                $count->godown,
                Weight::kg($count->difference_kg),
                StockEntry::ADJUSTMENT,
                ['source_type' => 'stock_count', 'source_id' => $count->id, 'note' => $count->note],
            );
        } catch (BusinessRuleViolation $e) {
            throw new ApprovalDenied($e->getMessage());
        }

        $count->forceFill(['status' => 'applied', 'stock_ledger_id' => $entry->id])->save();

        return ['stock_ledger_id' => $entry->id, 'difference_kg' => $count->difference_kg];
    }

    public function closed(ApprovalRequest $request): void
    {
        StockCount::query()->whereKey($request->payload['stock_count_id'] ?? null)->where('status', 'pending')
            ->first()?->forceFill(['status' => 'rejected'])->save();
    }
}

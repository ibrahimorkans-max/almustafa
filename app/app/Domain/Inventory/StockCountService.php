<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Alerts\AlertService;
use App\Domain\Approvals\Actions\StockAdjustment;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Inventory\StockCount;
use App\Models\Masters\Godown;
use App\Models\Masters\Product;
use App\Support\Values\Weight;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Physical verification. The system balance is captured at counting time; a difference is
 * never booked directly but goes to maker-checker approval (StockAdjustment). A count well
 * below the books also raises a shrinkage alert at once, whatever the approval outcome.
 */
final class StockCountService
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly ApprovalService $approvals,
        private readonly AlertService $alerts,
        private readonly ActorContext $actor,
    ) {}

    public function record(Product $product, Godown $godown, string $countedKg, ?string $note = null, ?CarbonInterface $date = null): StockCount
    {
        if (! ($this->actor->account()?->hasPermission('stock.adjust') ?? false)) {
            throw new BusinessRuleViolation("You need 'stock.adjust' for this.");
        }
        if (! preg_match('/^\d{1,12}(\.\d{1,3})?$/', $countedKg)) {
            throw new BusinessRuleViolation('Counted quantity must be a weight in kg (zero or more).');
        }

        return DB::transaction(function () use ($product, $godown, $countedKg, $note, $date): StockCount {
            // Lock the balance so the captured system figure cannot move under the count.
            DB::table('stock_balances')->where('product_id', $product->id)->where('godown_id', $godown->id)->lockForUpdate()->first();
            $system = $this->stock->balance($product, $godown);
            $counted = Weight::kg($countedKg);
            $difference = $counted->minus($system);

            if (StockCount::query()->where('product_id', $product->id)->where('godown_id', $godown->id)->where('status', 'pending')->exists()) {
                throw new BusinessRuleViolation('An earlier count of this stock is still waiting for approval.');
            }

            $count = StockCount::query()->create([
                'count_date' => ($date ?? now())->toDateString(),
                'product_id' => $product->id,
                'godown_id' => $godown->id,
                'system_kg' => $system->kg,
                'counted_kg' => $counted->kg,
                'difference_kg' => $difference->kg,
                'note' => $note,
            ]);

            if (bccomp($difference->kg, '0', 3) === 0) {
                $count->forceFill(['status' => 'applied'])->save();

                return $count;
            }

            $request = $this->approvals->submit(
                StockAdjustment::KEY,
                ['stock_count_id' => $count->id],
                sprintf('Stock adjustment %s kg: %s in %s', $difference->kg, $product->name, $godown->name),
                $note,
            );
            $count->forceFill(['approval_request_id' => $request->id])->save();

            $this->checkShrinkage($count, $system, $difference, $product, $godown);

            return $count;
        });
    }

    private function checkShrinkage(StockCount $count, Weight $system, Weight $difference, Product $product, Godown $godown): void
    {
        if (! $difference->isNegative() || bccomp($system->kg, '0', 3) <= 0) {
            return;
        }
        $bp = (int) bcdiv(bcmul(ltrim($difference->kg, '-'), '10000', 3), $system->kg, 0);
        if ($bp <= (int) config('ricemill.inventory.shrinkage_bp')) {
            return;
        }

        $this->alerts->raise(
            'shrinkage',
            'critical',
            sprintf('Shrinkage: %s short by %s kg in %s', $product->name, ltrim($difference->kg, '-'), $godown->name),
            sprintf('Count %s kg against %s kg in the books (%s%%).', $count->counted_kg, $system->kg, number_format($bp / 100, 2)),
            $count,
            ['product_id' => $product->id, 'godown_id' => $godown->id, 'difference_kg' => $difference->kg, 'shrinkage_bp' => $bp],
            'stock.adjust',
        );
    }
}

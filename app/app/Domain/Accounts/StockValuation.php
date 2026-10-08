<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Models\Accounts\ProductCost;
use App\Models\Accounts\PurchaseBill;
use App\Models\Inventory\StockBalance;
use App\Models\Masters\Product;
use App\Models\Production\ProductionBatch;
use App\Support\Values\Weight;

/**
 * Perpetual weighted-average cost per product (all godowns of the entity), in paise per kg.
 *
 * * Purchase: the bill's cost (gross − rate cut + brokerage) is spread over the physical net weight
 *   received, then averaged with the stock already held.
 * * Milling: at batch close the paddy consumed is costed at the paddy average; by-products take
 *   their realisable value per kg (product setting); the rest goes to the rice outputs by weight.
 *
 * Each change is an append-only ProductCost row, so the history of every average is kept.
 */
final class StockValuation
{
    public function recordPurchase(PurchaseBill $bill): void
    {
        $receivedKg = (string) ($bill->snapshot['slip']['net_kg'] ?? $bill->payable_kg);
        if (bccomp($receivedKg, '0', 3) <= 0) {
            return;
        }
        $cost = $bill->gross_paise - $bill->rate_cut_paise + $bill->brokerage_paise;
        $this->blend($bill->product_id, $receivedKg, (string) $cost, 'purchase_bill', $bill->id, [
            'bill' => $bill->number, 'cost_paise' => $cost, 'received_kg' => $receivedKg,
        ]);
    }

    /**
     * @param  array{input: array<string, string>, output: array<string, string>}  $net  quantities per product
     */
    public function recordBatch(ProductionBatch $batch, array $net): void
    {
        $inputKg = $net['input'][$batch->paddy_product_id] ?? '0';
        if (bccomp($inputKg, '0', 3) <= 0 || $net['output'] === []) {
            return;
        }
        $inputCost = bcmul($inputKg, $this->averageOf($batch->paddy_product_id), 4);

        $products = Product::query()->whereIn('id', array_keys($net['output']))->get()->keyBy('id');
        $byProductValue = '0';
        $mainKg = '0';
        foreach ($net['output'] as $productId => $kg) {
            $product = $products[$productId] ?? null;
            if ($product?->category === 'by_product') {
                $byProductValue = bcadd($byProductValue, bcmul($kg, (string) ($product->valuation_paise_per_kg ?? 0), 4), 4);
            } else {
                $mainKg = bcadd($mainKg, $kg, 3);
            }
        }
        // By-products can never absorb more than the whole batch cost.
        if (bccomp($byProductValue, $inputCost, 4) > 0) {
            $byProductValue = $inputCost;
        }
        $mainCost = bcsub($inputCost, $byProductValue, 4);
        $mainPerKg = bccomp($mainKg, '0', 3) > 0 ? bcdiv($mainCost, $mainKg, 4) : '0';

        foreach ($net['output'] as $productId => $kg) {
            if (bccomp($kg, '0', 3) <= 0) {
                continue;
            }
            $product = $products[$productId] ?? null;
            $perKg = $product?->category === 'by_product' ? (string) ($product->valuation_paise_per_kg ?? 0) : $mainPerKg;
            $this->blend($productId, $kg, bcmul($kg, $perKg, 4), 'production_batch', $batch->id, [
                'batch' => $batch->number, 'input_cost_paise' => $inputCost, 'paise_per_kg' => $perKg,
            ], alreadyInStockKg: $kg);
        }
    }

    /** Current average cost in paise per kg ("0.0000" if never costed). */
    public function averageOf(string $productId): string
    {
        return (string) (ProductCost::query()->where('product_id', $productId)->latest('created_at')->orderByDesc('id')->value('avg_paise_per_kg') ?? '0.0000');
    }

    /**
     * Value of stock in hand per product × godown at the current averages.
     *
     * @return list<array{product_id: string, godown_id: string, quantity_kg: string, avg_paise_per_kg: string, value_paise: int}>
     */
    public function stockValue(): array
    {
        $rows = [];
        $averages = [];
        foreach (StockBalance::query()->where('quantity_kg', '<>', 0)->get() as $b) {
            $avg = $averages[$b->product_id] ??= $this->averageOf($b->product_id);
            $rows[] = [
                'product_id' => $b->product_id, 'godown_id' => $b->godown_id, 'quantity_kg' => $b->quantity_kg,
                'avg_paise_per_kg' => $avg, 'value_paise' => (int) bcadd(bcmul($b->quantity_kg, $avg, 4), '0.5', 0),
            ];
        }

        return $rows;
    }

    /**
     * New average = (stock held before × old average + cost) / (stock held before + kg).
     * The receipt itself is already in stock (gate / production book it first), so "held before"
     * is the current total minus the received kg.
     *
     * @param  array<string, mixed>  $data
     */
    private function blend(string $productId, string $kg, string $costPaise, string $sourceType, string $sourceId, array $data, ?string $alreadyInStockKg = null): void
    {
        $total = Weight::kg((string) (StockBalance::query()->where('product_id', $productId)->sum('quantity_kg') ?: '0'));
        $before = $total->minus(Weight::kg($alreadyInStockKg ?? $kg));
        $beforeKg = $before->isNegative() ? '0' : $before->kg;
        $oldAvg = $this->averageOf($productId);

        $basis = bcadd($beforeKg, $kg, 3);
        $avg = bccomp($basis, '0', 3) > 0
            ? bcdiv(bcadd(bcmul($beforeKg, $oldAvg, 4), $costPaise, 4), $basis, 4)
            : $oldAvg;

        ProductCost::query()->create([
            'product_id' => $productId, 'avg_paise_per_kg' => $avg, 'basis_kg' => $basis,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'data' => $data + ['previous_avg' => $oldAvg, 'held_before_kg' => $beforeKg],
            'created_at' => now(),
        ]);
    }
}

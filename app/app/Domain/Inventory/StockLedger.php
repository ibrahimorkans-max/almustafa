<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockEntry;
use App\Models\Masters\Godown;
use App\Models\Masters\Product;
use App\Support\Values\Weight;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only writer of stock. Each movement appends a ledger row and updates the
 * product × godown balance in the same transaction, under a row lock, so concurrent
 * issues cannot both spend the same stock and the balance never goes negative.
 * Corrections are reversal rows; nothing is ever edited.
 */
final class StockLedger
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  array{source_type?: string|null, source_id?: string|null, batch_id?: string|null, reverses_id?: string|null, note?: string|null}  $context
     */
    public function post(Product $product, Godown $godown, Weight $quantity, string $movement, array $context = [], ?CarbonInterface $occurredAt = null): StockEntry
    {
        if (bccomp($quantity->kg, '0', 3) === 0) {
            throw new BusinessRuleViolation('A stock movement cannot be zero.');
        }
        if (! $product->is_stock_item) {
            throw new BusinessRuleViolation("{$product->name} is not a stock item.");
        }

        return DB::transaction(function () use ($product, $godown, $quantity, $movement, $context, $occurredAt): StockEntry {
            $balance = $this->lockBalance($product, $godown);
            $after = Weight::kg($balance->quantity_kg)->plus($quantity);
            if ($after->isNegative()) {
                throw new BusinessRuleViolation(sprintf(
                    'Not enough %s in %s: %s kg available, %s kg requested.',
                    $product->name, $godown->name, $balance->quantity_kg, ltrim($quantity->kg, '-'),
                ));
            }

            $entry = StockEntry::query()->create([
                'product_id' => $product->id,
                'godown_id' => $godown->id,
                'quantity_kg' => $quantity->kg,
                'movement' => $movement,
                'source_type' => $context['source_type'] ?? null,
                'source_id' => $context['source_id'] ?? null,
                'batch_id' => $context['batch_id'] ?? null,
                'reverses_id' => $context['reverses_id'] ?? null,
                'note' => $context['note'] ?? null,
                'occurred_at' => $occurredAt ?? now(),
                'created_at' => now(),
            ]);

            $balance->quantity_kg = $after->kg;
            $balance->last_entry_id = $entry->id;
            $balance->save();

            $this->audit->record('stock.'.$movement, $entry, null, [
                'product' => $product->code, 'godown' => $godown->code, 'quantity_kg' => $quantity->kg, 'balance_kg' => $after->kg,
            ], array_filter($context));

            return $entry;
        });
    }

    /**
     * Godown → godown in one transaction (two ledger rows).
     *
     * @param  array{source_type?: string|null, source_id?: string|null, note?: string|null}  $context
     * @return array{0: StockEntry, 1: StockEntry} [out, in]
     */
    public function transfer(Product $product, Godown $from, Godown $to, Weight $quantity, array $context = []): array
    {
        if ($from->id === $to->id) {
            throw new BusinessRuleViolation('Source and destination godown are the same.');
        }

        return DB::transaction(fn () => [
            $this->post($product, $from, Weight::zero()->minus($quantity), StockEntry::TRANSFER_OUT, $context),
            $this->post($product, $to, $quantity, StockEntry::TRANSFER_IN, $context),
        ]);
    }

    /** Correction: posts the opposite quantity referencing the original row. */
    public function reverse(StockEntry $entry, string $reason): StockEntry
    {
        if (StockEntry::query()->where('reverses_id', $entry->id)->exists()) {
            throw new BusinessRuleViolation('This stock entry was already reversed.');
        }

        return $this->post(
            $entry->product,
            $entry->godown,
            Weight::zero()->minus(Weight::kg($entry->quantity_kg)),
            StockEntry::REVERSAL,
            ['source_type' => $entry->source_type, 'source_id' => $entry->source_id, 'batch_id' => $entry->batch_id, 'reverses_id' => $entry->id, 'note' => $reason],
        );
    }

    public function balance(Product $product, Godown $godown): Weight
    {
        $kg = StockBalance::query()->where('product_id', $product->id)->where('godown_id', $godown->id)->value('quantity_kg');

        return Weight::kg((string) ($kg ?? '0'));
    }

    /**
     * Recomputes every balance from the ledger.
     *
     * @return list<array{product_id: string, godown_id: string, balance_kg: string, ledger_kg: string}> mismatches
     */
    public function verify(): array
    {
        $ledger = StockEntry::query()->toBase()
            ->selectRaw('product_id, godown_id, SUM(quantity_kg) AS total')
            ->groupBy('product_id', 'godown_id')->get()
            ->mapWithKeys(fn (object $r) => [$r->product_id.'|'.$r->godown_id => Weight::kg(bcadd((string) $r->total, '0', 3))->kg]);

        $mismatches = [];
        $seen = [];
        foreach (StockBalance::query()->get() as $balance) {
            $key = $balance->product_id.'|'.$balance->godown_id;
            $seen[$key] = true;
            $expected = $ledger[$key] ?? '0.000';
            if (bccomp($expected, $balance->quantity_kg, 3) !== 0) {
                $mismatches[] = ['product_id' => $balance->product_id, 'godown_id' => $balance->godown_id, 'balance_kg' => $balance->quantity_kg, 'ledger_kg' => $expected];
            }
        }
        foreach ($ledger as $key => $total) {
            if (! isset($seen[$key]) && bccomp($total, '0', 3) !== 0) {
                [$p, $g] = explode('|', $key);
                $mismatches[] = ['product_id' => $p, 'godown_id' => $g, 'balance_kg' => '0.000', 'ledger_kg' => $total];
            }
        }

        return $mismatches;
    }

    private function lockBalance(Product $product, Godown $godown): StockBalance
    {
        DB::table('stock_balances')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'entity_id' => $this->tenant->requireEntityId(),
            'product_id' => $product->id,
            'godown_id' => $godown->id,
            'quantity_kg' => '0',
            'updated_at' => now(),
        ]);

        /** @var StockBalance */
        return StockBalance::query()->where('product_id', $product->id)->where('godown_id', $godown->id)->lockForUpdate()->firstOrFail();
    }
}

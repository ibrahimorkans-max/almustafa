<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Approvals\Actions\CreditOverride;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Inventory\StockLedger;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Entity;
use App\Models\Masters\Godown;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Procurement\Sauda;
use App\Models\Sales\DispatchOrder;
use App\Support\Values\Money;
use App\Support\Values\Weight;
use Illuminate\Support\Facades\DB;

/**
 * Dispatch orders against confirmed sales saudas. An order over the buyer's credit limit waits
 * ("pending credit") until a different person with `approval.decide` approves an override.
 */
final class DispatchService
{
    public function __construct(
        private readonly CreditControl $credit,
        private readonly SalesTaxCalculator $tax,
        private readonly StockLedger $stock,
        private readonly DocumentNumbers $numbers,
        private readonly ActorContext $actor,
    ) {}

    public function create(Sauda $sauda, Godown $godown, string $quantityKg, ?int $bagCount = null, ?string $bagWeightKg = null, ?string $note = null): DispatchOrder
    {
        $this->require('dispatch.manage');
        if ($sauda->direction !== 'sale') {
            throw new BusinessRuleViolation('Dispatch orders are for sales saudas.');
        }
        if (! $sauda->isOpenForDelivery()) {
            throw new BusinessRuleViolation("Sauda {$sauda->number} is {$sauda->status}.");
        }
        if (! preg_match('/^\d{1,12}(\.\d{1,3})?$/', $quantityKg) || bccomp($quantityKg, '0', 3) <= 0) {
            throw new BusinessRuleViolation('Quantity must be a positive weight in kg.');
        }
        if (($bagCount === null) !== ($bagWeightKg === null)) {
            throw new BusinessRuleViolation('Give both the number of bags and the bag size, or neither.');
        }
        $quantity = Weight::kg($quantityKg);

        return DB::transaction(function () use ($sauda, $godown, $quantity, $bagCount, $bagWeightKg, $note): DispatchOrder {
            $sauda = Sauda::query()->lockForUpdate()->findOrFail($sauda->id);
            $ordered = Weight::kg((string) DispatchOrder::query()->where('sauda_id', $sauda->id)->whereIn('status', DispatchOrder::EXPOSED)->sum('quantity_kg') ?: '0');
            $left = $sauda->maximumQuantity()->minus(Weight::kg($sauda->delivered_kg))->minus($ordered);
            if ($quantity->compare($left) > 0) {
                throw new BusinessRuleViolation(sprintf('Only %s kg of sauda %s is left to order (incl. tolerance).', $left->isNegative() ? '0' : $left->kg, $sauda->number));
            }
            $product = Product::query()->findOrFail($sauda->product_id);
            $available = $this->stock->balance($product, $godown);
            if ($quantity->compare($available) > 0) {
                throw new BusinessRuleViolation(sprintf('%s holds only %s kg of %s.', $godown->name, $available->kg, $product->name));
            }

            /** @var Party $party */
            $party = Party::query()->findOrFail($sauda->party_id);
            $estimate = $this->estimate($sauda, $party, $product, $quantity->kg);
            $order = DispatchOrder::query()->create([
                'number' => $this->numbers->next(DocumentNumbers::DISPATCH_ORDER),
                'sauda_id' => $sauda->id, 'party_id' => $party->id, 'product_id' => $product->id, 'godown_id' => $godown->id,
                'quantity_kg' => $quantity->kg, 'bag_count' => $bagCount, 'bag_weight_kg' => $bagWeightKg,
                'estimated_value_paise' => $estimate, 'note' => $note,
            ]);

            if (! $this->credit->allows($party, $estimate, $order->id)) {
                $order->forceFill(['status' => DispatchOrder::PENDING_CREDIT])->save();
                $request = app(ApprovalService::class)->submit(CreditOverride::KEY, ['dispatch_order_id' => $order->id],
                    sprintf('Credit override: %s for %s (limit %s, exposure %s)', Money::paise($estimate)->format(), $party->name,
                        Money::paise((int) $party->credit_limit_paise)->format(), Money::paise($this->credit->exposure($party, $order->id))->format()),
                    $note);
                $order->forceFill(['credit_override_request_id' => $request->id])->save();
            }

            return $order;
        });
    }

    public function cancel(DispatchOrder $order, string $reason): DispatchOrder
    {
        $this->require('dispatch.manage');

        return DB::transaction(function () use ($order, $reason): DispatchOrder {
            $order = DispatchOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($order->status, [DispatchOrder::PENDING_CREDIT, DispatchOrder::OPEN], true)) {
                throw new BusinessRuleViolation("Dispatch order {$order->number} is {$order->status}; it can no longer be cancelled.");
            }
            $order->forceFill(['status' => DispatchOrder::CANCELLED, 'note' => trim(($order->note ? $order->note."\n" : '')."Cancelled: {$reason}")])->save();

            return $order;
        });
    }

    /** Invoice value incl. sales tax for the credit check. */
    private function estimate(Sauda $sauda, Party $party, Product $product, string $kg): int
    {
        $terms = SalesTaxCalculator::terms(Entity::query()->find($sauda->entity_id), $product, $party);

        return $this->tax->calculate($kg, (int) $sauda->rate_paise_per_maund, $terms['tax_rate_bp'], $terms['further_tax_rate_bp'])['total_paise'];
    }

    private function require(string $permission): void
    {
        if (! ($this->actor->account()?->hasPermission($permission) ?? false)) {
            throw new BusinessRuleViolation("You need '{$permission}' for this.");
        }
    }
}

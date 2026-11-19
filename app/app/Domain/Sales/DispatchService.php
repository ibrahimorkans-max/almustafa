<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Approvals\Actions\CreditOverride;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Inventory\StockLedger;
use App\Domain\Pricing\PricingService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Entity;
use App\Models\Masters\Godown;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Procurement\Sauda;
use App\Models\Sales\DispatchOrder;
use App\Models\Sales\QuickBillLine;
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

    /**
     * A direct dispatch — no vehicle at the weighbridge: by hand, on a bike, a small quantity; the weights are typed.
     * Every product goes on one bill (QuickBills, kind sale, on the sauda: its weight counts against the deal), at the
     * sauda's rate unless changed, with any per-bag deduction, charges and money received now. The order is recorded
     * as dispatched at once, linked to the bill. Over the buyer's credit limit it is refused unless paid now (a gate
     * order can wait for a credit override instead).
     *
     * @param  array<string, mixed>  $data  billed_at, vehicle_type, vehicle_no, driver_name, lines[], charges[], payment{}, note
     */
    public function createDirect(Sauda $sauda, array $data): DispatchOrder
    {
        $this->require('dispatch.manage');
        if ($sauda->direction !== 'sale') {
            throw new BusinessRuleViolation('Dispatch orders are for sales saudas.');
        }
        if (! $sauda->isOpenForDelivery()) {
            throw new BusinessRuleViolation("Sauda {$sauda->number} is {$sauda->status}.");
        }
        /** @var Party $party */
        $party = Party::query()->findOrFail($sauda->party_id);
        $lines = array_values((array) ($data['lines'] ?? []));
        foreach ($lines as $i => $l) {
            $lines[$i]['direction'] = 'out';
            if (blank($l['rate'] ?? null)) {
                // The sauda's product at the sauda's rate; anything else at today's list rate.
                $own = ($l['product_id'] ?? null) === $sauda->product_id || blank($l['product_id'] ?? null);
                $rate = $own ? (int) $sauda->rate_paise_per_maund
                    : app(PricingService::class)->listRate(Product::query()->findOrFail($l['product_id']));
                $lines[$i]['rate'] = $rate === null ? null : Money::paise($rate)->toDecimal();
                $lines[$i]['product_id'] = $own ? $sauda->product_id : $l['product_id'];
            }
        }
        // The credit check on what stays unpaid.
        $estimate = 0;
        foreach ($lines as $l) {
            try {
                $estimate += QuickBills::lineAmount((string) ($l['gross_kg'] ?? '0'), (string) (($l['tare_kg'] ?? '') ?: '0'), filled($l['bags'] ?? null) ? (int) $l['bags'] : null,
                    (string) (($l['deduction_per_bag_kg'] ?? '') ?: '0'), (string) (($l['other_deduction_kg'] ?? '') ?: '0'), Money::rupees((string) $l['rate'])->paise)['amount_paise'];
            } catch (\Throwable) {
                // a bad line is reported by QuickBills
            }
        }
        $paidNow = 0;
        try {
            $paidNow = filled($data['payment']['amount'] ?? null) ? Money::rupees((string) $data['payment']['amount'])->paise : 0;
        } catch (\Throwable) {
        }
        if (! $this->credit->allows($party, max(0, $estimate - $paidNow))) {
            throw new BusinessRuleViolation(sprintf('Over %s\'s credit limit: take the payment now, or make a gate dispatch order (it waits for a credit override).', $party->name));
        }

        return DB::transaction(function () use ($sauda, $party, $data, $lines): DispatchOrder {
            $bill = app(QuickBills::class)->create([
                'kind' => 'sale', 'sauda_id' => $sauda->id, 'party_id' => $party->id,
                'billed_at' => $data['billed_at'] ?? null, 'vehicle_type' => $data['vehicle_type'] ?? null,
                'vehicle_no' => $data['vehicle_no'] ?? null, 'driver_name' => $data['driver_name'] ?? null,
                'lines' => $lines, 'charges' => $data['charges'] ?? [], 'payment' => $data['payment'] ?? null, 'note' => $data['note'] ?? null,
            ]);
            $own = QuickBillLine::query()->where('quick_bill_id', $bill->id)->where('product_id', $sauda->product_id)->orderBy('line_no')->get();
            $order = DispatchOrder::query()->create([
                'number' => $this->numbers->next(DocumentNumbers::DISPATCH_ORDER, $bill->billed_at),
                'sauda_id' => $sauda->id, 'party_id' => $party->id, 'product_id' => $sauda->product_id,
                'godown_id' => $own->first()->godown_id ?? QuickBillLine::query()->where('quick_bill_id', $bill->id)->whereNotNull('godown_id')->value('godown_id'),
                'quantity_kg' => (string) $bill->sauda_kg, 'bag_count' => $own->sum('bags') ?: null, 'bag_weight_kg' => null,
                'estimated_value_paise' => $bill->net_paise, 'note' => $data['note'] ?? null,
            ]);
            $order->forceFill(['status' => DispatchOrder::DISPATCHED, 'quick_bill_id' => $bill->id,
                'vehicle_type' => $bill->vehicle_type, 'dispatched_at' => $bill->billed_at])->save();

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

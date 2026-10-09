<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Domain\Auth\ActorContext;
use App\Domain\Claims\ClaimService;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\ReceiptAllocation;
use App\Models\Entity;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Masters\Party;
use App\Models\Masters\Product;
use App\Models\Procurement\Sauda;
use App\Models\Sales\DispatchOrder;
use App\Models\Sales\SalesBill;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sales tax invoices (or commercial invoices when the mill has no STRN) for outbound slips. A draft is
 * computed when the slip is issued (net kg × frozen sauda rate, sales tax and further tax by the buyer's
 * registration); accounts posts it, which assigns the gap-free
 * invoice number and posts the sales voucher. A posted invoice is corrected only by reversal.
 */
final class SalesBillService
{
    public function __construct(
        private readonly SalesTaxCalculator $tax,
        private readonly Books $books,
        private readonly ChartOfAccounts $chart,
        private readonly DocumentNumbers $numbers,
        private readonly ActorContext $actor,
    ) {}

    public function draftFromSlip(GateEntry $entry): ?SalesBill
    {
        $existing = SalesBill::query()->where('gate_entry_id', $entry->id)->first();
        if ($existing !== null) {
            return $existing;
        }
        $slip = GateEvent::query()->where('gate_entry_id', $entry->id)->where('event', 'slip_issued')->first();
        if ($slip === null || $entry->direction !== 'outbound' || $entry->dispatch_order_id === null) {
            return null;
        }
        /** @var DispatchOrder $order */
        $order = DispatchOrder::query()->findOrFail($entry->dispatch_order_id);
        /** @var Sauda $sauda */
        $sauda = Sauda::query()->findOrFail($order->sauda_id);
        /** @var Party $party */
        $party = Party::query()->findOrFail($order->party_id);
        /** @var Product $product */
        $product = Product::query()->with('taxRate')->findOrFail($order->product_id);

        $terms = SalesTaxCalculator::terms(Entity::query()->find($entry->entity_id), $product, $party);
        $netKg = (string) $slip->data['net_kg'];
        $amounts = $this->tax->calculate($netKg, (int) $sauda->rate_paise_per_maund, $terms['tax_rate_bp'], $terms['further_tax_rate_bp']);
        $billDate = CarbonImmutable::instance($slip->occurred_at)->setTimezone('Asia/Karachi')->startOfDay();

        return SalesBill::query()->create([
            'gate_entry_id' => $entry->id, 'dispatch_order_id' => $order->id, 'sauda_id' => $sauda->id,
            'party_id' => $party->id, 'product_id' => $product->id,
            'bill_date' => $billDate->toDateString(), 'due_date' => $billDate->addDays((int) $sauda->payment_days)->toDateString(),
            'quantity_kg' => $netKg, 'rate_paise_per_maund' => (int) $sauda->rate_paise_per_maund,
            'pct_code' => $product->pct_code, 'buyer_strn' => $party->strn, 'buyer_ntn' => $party->ntn ?? $party->cnic,
            'snapshot' => [
                'slip' => $slip->data, 'gate_entry_number' => $entry->number, 'registration_no' => $entry->registration_no,
                'sauda_number' => $sauda->number, 'dispatch_order_number' => $order->number,
                'buyer' => ['name' => $party->legal_name ?? $party->name, 'address' => $party->address, 'city' => $party->city, 'province' => $party->province,
                    'phone' => $party->phone, 'registered' => $party->isSalesTaxRegistered(), 'filer' => $party->isFiler()],
                'product' => ['name' => $product->name, 'pct' => $product->pct_code, 'unit' => $product->sale_unit, 'bag_weight_kg' => $product->bag_weight_kg],
                'bags' => $entry->bag_count,
            ],
        ] + $terms + $amounts);
    }

    public function post(SalesBill $bill, ?string $note = null): SalesBill
    {
        $this->require('ledger.post');

        return DB::transaction(function () use ($bill, $note): SalesBill {
            $bill = $this->lock($bill, SalesBill::DRAFT);
            /** @var Party $party */
            $party = Party::query()->findOrFail($bill->party_id);
            $product = Product::query()->findOrFail($bill->product_id);
            $number = $this->numbers->next(DocumentNumbers::SALES_INVOICE, $bill->bill_date);
            $memo = "{$number} · {$bill->snapshot['gate_entry_number']} · {$bill->quantity_kg} kg";

            $lines = [
                ['account' => $this->chart->partyLedger($party), 'dr' => $bill->total_paise, 'bill_id' => $bill->id, 'memo' => $memo],
                ['account' => $this->chart->system($product->category === 'by_product' ? 'sales_byproduct' : 'sales_rice'), 'cr' => $bill->taxable_paise, 'memo' => $memo],
                ['account' => $this->chart->system('output_sales_tax'), 'cr' => $bill->sales_tax_paise],
                ['account' => $this->chart->system('further_tax'), 'cr' => $bill->further_tax_paise],
            ];
            if ($bill->round_off_paise > 0) {
                $lines[] = ['account' => $this->chart->system('round_off'), 'cr' => $bill->round_off_paise];
            } elseif ($bill->round_off_paise < 0) {
                $lines[] = ['account' => $this->chart->system('round_off'), 'dr' => -$bill->round_off_paise];
            }

            $voucher = $this->books->post('sales', $bill->bill_date, $lines, ($bill->invoice_type === 'sales_tax' ? 'Sales tax invoice' : 'Invoice')." {$number} to {$party->name}", [
                'source_type' => 'sales_bill', 'source_id' => $bill->id,
            ]);
            $bill->forceFill([
                'status' => SalesBill::POSTED, 'number' => $number, 'voucher_id' => $voucher->id,
                'posted_by' => $this->actor->accountId(), 'posted_at' => now(), 'note' => $note ?? $bill->note,
            ])->save();

            return $bill;
        });
    }

    public function cancelDraft(SalesBill $bill, string $reason): SalesBill
    {
        $this->require('ledger.post');

        return DB::transaction(function () use ($bill, $reason): SalesBill {
            $bill = $this->lock($bill, SalesBill::DRAFT);
            $bill->forceFill(['status' => SalesBill::CANCELLED, 'note' => $reason])->save();

            return $bill;
        });
    }

    /** Called when the invoice's sales voucher is reversed through an approved request. */
    public function markReversed(SalesBill $bill, string $reason): void
    {
        if (ClaimService::settledOn($bill->id) > 0) {
            throw new BusinessRuleViolation('Claims are approved or settled against this document; reverse or withdraw them first.');
        }
        if (ReceiptAllocation::query()->where('sales_bill_id', $bill->id)->exists()) {
            throw new BusinessRuleViolation('Receipts are allocated to this invoice; reverse them first.');
        }
        $bill->forceFill(['status' => SalesBill::CANCELLED, 'note' => $reason])->save();
    }

    public function outstanding(SalesBill $bill): int
    {
        if ($bill->status !== SalesBill::POSTED) {
            return 0;
        }

        return $bill->total_paise - (int) ReceiptAllocation::query()->where('sales_bill_id', $bill->id)->sum('amount_paise')
            - ClaimService::notedOn($bill->id);
    }

    private function lock(SalesBill $bill, string $status): SalesBill
    {
        /** @var SalesBill $fresh */
        $fresh = SalesBill::query()->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->status !== $status) {
            throw new BusinessRuleViolation("This invoice is {$fresh->status}.");
        }

        return $fresh;
    }

    private function require(string $permission): void
    {
        if (! ($this->actor->account()?->hasPermission($permission) ?? false)) {
            throw new BusinessRuleViolation("You need '{$permission}' for this.");
        }
    }
}

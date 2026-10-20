<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Auth\ActorContext;
use App\Domain\Claims\ClaimService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\PaymentAllocation;
use App\Models\Accounts\PurchaseBill;
use App\Models\Gate\GateEntry;
use App\Models\Gate\GateEvent;
use App\Models\Masters\Party;
use App\Models\Procurement\Sauda;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Purchase bills from weighment slips. A draft is created when the slip is issued, from the
 * frozen slip and sauda terms; accounts reviews and posts it (purchase voucher). Amounts are
 * computed, never typed. A posted bill is corrected only by reversing its voucher.
 */
final class PurchaseBillService
{
    public function __construct(
        private readonly PurchaseBillCalculator $calculator,
        private readonly Books $books,
        private readonly ChartOfAccounts $chart,
        private readonly StockValuation $valuation,
        private readonly ActorContext $actor,
    ) {}

    /** Idempotent per gate entry. Returns null when the slip is not billable (no sauda, rejected lot). */
    public function draftFromSlip(GateEntry $entry): ?PurchaseBill
    {
        $existing = PurchaseBill::query()->where('gate_entry_id', $entry->id)->first();
        if ($existing !== null) {
            return $existing;
        }
        $slip = GateEvent::query()->where('gate_entry_id', $entry->id)->where('event', 'slip_issued')->first();
        if ($slip === null || $entry->direction !== 'inbound' || $entry->sauda_id === null || ! ($slip->data['lab_accepted'] ?? false)) {
            return null;
        }
        /** @var Sauda $sauda */
        $sauda = Sauda::query()->findOrFail($entry->sauda_id);
        if ($sauda->direction !== 'purchase') {
            return null;
        }

        $payableKg = (string) $slip->data['payable_kg'];
        // An approved settlement fixes the price (the last accepted one) and the rate cut that goes with it
        // (the lab's quality cut at the offered price; none at a negotiated price).
        $settlement = isset($slip->data['settlement']) && is_array($slip->data['settlement']) ? $slip->data['settlement'] : null;
        $rate = $settlement !== null ? (int) $settlement['price_paise_per_maund'] : (int) $sauda->rate_paise_per_maund;
        $cutPerMaund = $settlement !== null ? (int) ($settlement['rate_cut_paise_per_maund'] ?? 0) : (int) ($slip->data['rate_cut_paise_per_maund'] ?? 0);
        $brokerage = $sauda->broker_party_id !== null ? (int) $sauda->brokerage_paise_per_maund : 0;
        $amounts = $this->calculator->calculate(
            $payableKg, $rate, $cutPerMaund, $brokerage,
            (bool) config('ricemill.accounts.round_bills_to_rupee'),
        );
        $billDate = CarbonImmutable::instance($slip->occurred_at)->setTimezone('Asia/Karachi')->startOfDay();

        return PurchaseBill::query()->create([
            'gate_entry_id' => $entry->id,
            'sauda_id' => $sauda->id,
            'party_id' => $sauda->party_id,
            'broker_party_id' => $sauda->broker_party_id,
            'product_id' => $sauda->product_id,
            'bill_date' => $billDate->toDateString(),
            'due_date' => $billDate->addDays((int) $sauda->payment_days)->toDateString(),
            'payable_kg' => $payableKg,
            'rate_paise_per_maund' => $rate,
            'snapshot' => [
                'slip' => $slip->data, 'gate_entry_number' => $entry->number, 'registration_no' => $entry->registration_no, 'vehicle_label' => $entry->vehicleLabel(),
                'sauda_number' => $sauda->number, 'rate_paise_per_maund' => (int) $sauda->rate_paise_per_maund,
                'rate_cut_paise_per_maund' => $cutPerMaund, 'brokerage_paise_per_maund' => $brokerage,
                'sauda_rate_paise_per_maund' => (int) $sauda->rate_paise_per_maund, 'settlement' => $settlement,
            ],
        ] + $amounts);
    }

    public function post(PurchaseBill $bill, ?string $note = null): PurchaseBill
    {
        $this->require('ledger.post');

        return DB::transaction(function () use ($bill, $note): PurchaseBill {
            $bill = $this->lock($bill, PurchaseBill::DRAFT);
            /** @var Party $party */
            $party = Party::query()->findOrFail($bill->party_id);
            $memo = "{$bill->snapshot['gate_entry_number']} · {$bill->snapshot['sauda_number']} · {$bill->payable_kg} kg";

            // Withholding tax (s. 153) is fixed at posting, from the supplier's posted purchases of the tax year
            // so far and their filer status that day; it is withheld from what the supplier is paid.
            $wht = app(WithholdingTax::class)->forBill($bill, $party);
            $bill->forceFill(['wht_filer' => $wht['wht_filer']]);
            if ($wht['wht_paise'] > 0) {
                $bill->forceFill($wht + ['net_paise' => $bill->net_paise - $wht['wht_paise']]);
            }

            $lines = [
                ['account' => $this->chart->system('purchase_paddy'), 'dr' => $bill->gross_paise, 'memo' => $memo],
                ['account' => $this->chart->system('rate_cut'), 'cr' => $bill->rate_cut_paise, 'memo' => 'Quality rate cut'],
                ['account' => $this->chart->partyLedger($party), 'cr' => $bill->net_paise, 'bill_id' => $bill->id, 'memo' => $memo],
                ['account' => $this->chart->system('wht_payable'), 'cr' => $bill->wht_paise, 'memo' => 'Income tax withheld u/s 153'],
            ];
            if ($bill->round_off_paise > 0) {
                $lines[] = ['account' => $this->chart->system('round_off'), 'dr' => $bill->round_off_paise];
            } elseif ($bill->round_off_paise < 0) {
                $lines[] = ['account' => $this->chart->system('round_off'), 'cr' => -$bill->round_off_paise];
            }
            if ($bill->brokerage_paise > 0 && $bill->broker_party_id !== null) {
                $broker = Party::query()->findOrFail($bill->broker_party_id);
                $lines[] = ['account' => $this->chart->system('brokerage'), 'dr' => $bill->brokerage_paise, 'memo' => $memo];
                $lines[] = ['account' => $this->chart->partyLedger($broker), 'cr' => $bill->brokerage_paise, 'memo' => "Brokerage · {$memo}"];
            }

            $voucher = $this->books->post('purchase', $bill->bill_date, $lines, "Purchase of paddy from {$party->name} ({$memo})", [
                'source_type' => 'purchase_bill', 'source_id' => $bill->id,
            ]);
            $bill->forceFill([
                'status' => PurchaseBill::POSTED, 'number' => $voucher->number, 'voucher_id' => $voucher->id,
                'posted_by' => $this->actor->accountId(), 'posted_at' => now(), 'note' => $note ?? $bill->note,
            ])->save();

            $this->valuation->recordPurchase($bill);

            return $bill;
        });
    }

    /** A draft that should never be booked (e.g. slip issued against the wrong sauda). */
    public function cancelDraft(PurchaseBill $bill, string $reason): PurchaseBill
    {
        $this->require('ledger.post');
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleViolation('Give a reason.');
        }

        return DB::transaction(function () use ($bill, $reason): PurchaseBill {
            $bill = $this->lock($bill, PurchaseBill::DRAFT);
            $bill->forceFill(['status' => PurchaseBill::CANCELLED, 'note' => $reason])->save();

            return $bill;
        });
    }

    /** Called when the bill's purchase voucher is reversed through an approved request. */
    public function markReversed(PurchaseBill $bill, string $reason): void
    {
        if (ClaimService::settledOn($bill->id) > 0) {
            throw new BusinessRuleViolation('Claims are approved or settled against this document; reverse or withdraw them first.');
        }
        if (PaymentAllocation::query()->where('purchase_bill_id', $bill->id)
            ->whereHas('payment', fn ($q) => $q->whereIn('status', ['pending', 'first_approved', 'approved']))->exists()) {
            throw new BusinessRuleViolation('Payments are allocated to this bill; reverse or cancel them first.');
        }
        $bill->forceFill(['status' => PurchaseBill::CANCELLED, 'note' => $reason])->save();
    }

    /** Amount still owed on a posted bill (net − allocations of released payments). */
    public function outstanding(PurchaseBill $bill): int
    {
        if ($bill->status !== PurchaseBill::POSTED) {
            return 0;
        }
        $paid = (int) PaymentAllocation::query()->where('purchase_bill_id', $bill->id)
            ->whereHas('payment', fn ($q) => $q->whereIn('status', ['pending', 'first_approved', 'approved']))
            ->sum('amount_paise');

        return $bill->net_paise - $paid - ClaimService::notedOn($bill->id);
    }

    private function lock(PurchaseBill $bill, string $status): PurchaseBill
    {
        /** @var PurchaseBill $fresh */
        $fresh = PurchaseBill::query()->whereKey($bill->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->status !== $status) {
            throw new BusinessRuleViolation("This bill is {$fresh->status}.");
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

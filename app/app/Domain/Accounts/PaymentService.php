<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Approvals\Actions\PaymentRelease;
use App\Domain\Approvals\ApprovalService;
use App\Domain\Auth\ActorContext;
use App\Domain\Documents\DocumentNumbers;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\Payment;
use App\Models\Accounts\PaymentAllocation;
use App\Models\Accounts\PurchaseBill;
use App\Models\Masters\Party;
use App\Models\Masters\PartyBankAccount;
use App\Support\Values\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Payments to parties. A request freezes the payee (only the party's approved bank account),
 * the amount and the bills it settles; it is released by a different person holding
 * `payment.approve` (two different people above the configured amount). Release posts the
 * payment voucher; bank-mode payments then go into a bank file for upload.
 */
final class PaymentService
{
    public function __construct(
        private readonly Books $books,
        private readonly ChartOfAccounts $chart,
        private readonly PurchaseBillService $bills,
        private readonly DocumentNumbers $numbers,
        private readonly ActorContext $actor,
    ) {}

    /**
     * @param  array<string, int>  $allocations  purchase bill id => paise
     */
    public function request(
        Party $party,
        string $mode,
        LedgerAccount $from,
        int $amountPaise,
        CarbonInterface $date,
        array $allocations = [],
        ?string $narration = null,
        ?PartyBankAccount $bankAccount = null,
    ): Payment {
        $this->require('payment.create');
        if (! isset(Payment::MODES[$mode])) {
            throw new BusinessRuleViolation('Unknown payment mode.');
        }
        if ($amountPaise <= 0) {
            throw new BusinessRuleViolation('Amount must be more than zero.');
        }
        if ($mode === 'cash' ? $from->kind !== 'cash' : $from->kind !== 'bank') {
            throw new BusinessRuleViolation($mode === 'cash' ? 'Cash is paid from a cash ledger.' : 'Pay from one of the mill bank accounts.');
        }

        return DB::transaction(function () use ($party, $mode, $from, $amountPaise, $date, $allocations, $narration, $bankAccount): Payment {
            Party::query()->whereKey($party->id)->lockForUpdate()->first(); // serialise requests per party

            $payee = null;
            $payeeNumber = null;
            if (in_array($mode, Payment::BANK_MODES, true)) {
                $account = $bankAccount ?? PartyBankAccount::query()->where('party_id', $party->id)->where('status', 'active')
                    ->orderByDesc('is_primary')->first();
                if ($account === null || $account->party_id !== $party->id || $account->status !== 'active') {
                    throw new BusinessRuleViolation("{$party->name} has no approved bank account. Add one (it needs a second person's approval) before paying by bank.");
                }
                $payee = ['holder' => $account->account_holder, 'ifsc' => $account->ifsc, 'last4' => $account->account_last4, 'bank_name' => $account->bank_name];
                $payeeNumber = $account->account_number;
                $bankAccount = $account;
            } elseif ($mode === 'cash') {
                $this->assertCashLimit($party, $amountPaise, $date);
            }

            $allocated = 0;
            foreach ($allocations as $billId => $paise) {
                $bill = PurchaseBill::query()->lockForUpdate()->find($billId);
                if ($bill === null || $bill->party_id !== $party->id || $bill->status !== PurchaseBill::POSTED) {
                    throw new BusinessRuleViolation('A selected bill is not a posted bill of this party.');
                }
                if ($paise <= 0 || $paise > $this->bills->outstanding($bill)) {
                    throw new BusinessRuleViolation(sprintf('Bill %s has only %s outstanding.', $bill->number, Money::paise($this->bills->outstanding($bill))->format()));
                }
                $allocated += $paise;
            }
            if ($allocated > $amountPaise) {
                throw new BusinessRuleViolation('Bills selected add up to more than the payment.');
            }

            $payment = Payment::query()->create([
                'number' => $this->numbers->next(DocumentNumbers::PAYMENT_REQUEST, $date),
                'party_id' => $party->id,
                'mode' => $mode,
                'from_ledger_id' => $from->id,
                'amount_paise' => $amountPaise,
                'payment_date' => CarbonImmutable::instance($date)->setTimezone('Asia/Kolkata')->toDateString(),
                'party_bank_account_id' => $bankAccount?->id,
                'payee' => $payee,
                'payee_account_number' => $payeeNumber,
                'narration' => $narration,
            ]);
            foreach ($allocations as $billId => $paise) {
                PaymentAllocation::query()->create(['payment_id' => $payment->id, 'purchase_bill_id' => $billId, 'amount_paise' => $paise]);
            }

            $request = app(ApprovalService::class)->submit(
                PaymentRelease::KEY,
                ['payment_id' => $payment->id, 'stage' => 1],
                sprintf('Pay %s to %s (%s)', Money::paise($amountPaise)->format(), $party->name, Payment::MODES[$mode]),
                $narration,
            );
            $payment->forceFill(['approval_request_id' => $request->id])->save();

            return $payment;
        });
    }

    /** Posts the payment voucher. Called only by an approved PaymentRelease. */
    public function release(Payment $payment): Payment
    {
        $party = Party::query()->findOrFail($payment->party_id);
        $partyLedger = $this->chart->partyLedger($party);
        $from = LedgerAccount::query()->findOrFail($payment->from_ledger_id);

        $lines = [];
        $allocated = 0;
        foreach (PaymentAllocation::query()->where('payment_id', $payment->id)->with('bill')->get() as $a) {
            $lines[] = ['account' => $partyLedger, 'dr' => $a->amount_paise, 'bill_id' => $a->purchase_bill_id, 'memo' => "Against {$a->bill->number}"];
            $allocated += $a->amount_paise;
        }
        if ($payment->amount_paise > $allocated) {
            $lines[] = ['account' => $partyLedger, 'dr' => $payment->amount_paise - $allocated, 'memo' => $allocated > 0 ? 'On account' : 'Advance / on account'];
        }
        $lines[] = ['account' => $from, 'cr' => $payment->amount_paise, 'memo' => $payment->number];

        $date = CarbonImmutable::parse($payment->payment_date->toDateString(), 'Asia/Kolkata');
        if ($date->lessThan(now('Asia/Kolkata')->startOfDay())) {
            $date = now('Asia/Kolkata')->startOfDay(); // released later than planned: book on the release day
        }
        $voucher = $this->books->post('payment', $date, $lines,
            sprintf('%s payment to %s%s', Payment::MODES[$payment->mode], $party->name, $payment->narration ? " — {$payment->narration}" : ''),
            ['source_type' => 'payment', 'source_id' => $payment->id],
        );
        $payment->forceFill(['status' => Payment::APPROVED, 'voucher_id' => $voucher->id])->save();

        return $payment;
    }

    /** UTR / cheque number once the bank has executed the transfer. */
    public function recordReference(Payment $payment, string $reference): Payment
    {
        $this->require('payment.create');
        if ($payment->status !== Payment::APPROVED) {
            throw new BusinessRuleViolation('Only a released payment has a bank reference.');
        }
        if (! preg_match('/^[A-Za-z0-9\/-]{4,60}$/', $reference)) {
            throw new BusinessRuleViolation('Reference must be 4–60 letters, digits, / or -.');
        }
        $payment->forceFill(['reference' => strtoupper($reference)])->save();

        return $payment;
    }

    private function assertCashLimit(Party $party, int $amountPaise, CarbonInterface $date): void
    {
        $day = CarbonImmutable::instance($date)->setTimezone('Asia/Kolkata')->toDateString();
        $already = (int) Payment::query()->where('party_id', $party->id)->where('mode', 'cash')->where('payment_date', $day)
            ->whereIn('status', [Payment::PENDING, Payment::FIRST_APPROVED, Payment::APPROVED])->sum('amount_paise');
        $limit = (int) config('ricemill.accounts.cash_limit_per_day_paise');
        if ($already + $amountPaise > $limit) {
            throw new BusinessRuleViolation(sprintf(
                'Cash to one person in one day is limited to %s (Income-tax s. 40A(3)); %s already requested today. Pay by bank.',
                Money::paise($limit)->format(), Money::paise($already)->format(),
            ));
        }
    }

    private function require(string $permission): void
    {
        if (! ($this->actor->account()?->hasPermission($permission) ?? false)) {
            throw new BusinessRuleViolation("You need '{$permission}' for this.");
        }
    }
}

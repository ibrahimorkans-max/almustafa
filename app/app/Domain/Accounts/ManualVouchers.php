<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Auth\ActorContext;
use App\Domain\Sales\SalesBillService;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\ReceiptAllocation;
use App\Models\Accounts\Voucher;
use App\Models\Masters\Party;
use App\Models\Sales\SalesBill;
use App\Support\Values\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Vouchers accountants enter by hand (`ledger.post`): receipts, journals and contra entries.
 * Money leaving the mill to a party is never a manual voucher — it goes through PaymentService.
 */
final class ManualVouchers
{
    public function __construct(
        private readonly Books $books,
        private readonly ChartOfAccounts $chart,
        private readonly ActorContext $actor,
    ) {}

    /**
     * Money received from a party into a bank or cash ledger, optionally against posted sales
     * invoices (never more than an invoice's outstanding amount); the rest is on account.
     *
     * @param  array<string, int>  $allocations  sales bill id => paise
     */
    public function receipt(Party $party, LedgerAccount $into, int $amountPaise, CarbonInterface $date, ?string $narration = null, array $allocations = []): Voucher
    {
        $this->require();
        if (! in_array($into->kind, ['bank', 'cash'], true)) {
            throw new BusinessRuleViolation('Receive into a bank or cash ledger.');
        }
        if ($amountPaise <= 0) {
            throw new BusinessRuleViolation('Amount must be more than zero.');
        }

        return DB::transaction(function () use ($party, $into, $amountPaise, $date, $narration, $allocations): Voucher {
            $partyLedger = $this->chart->partyLedger($party);
            $lines = [['account' => $into, 'dr' => $amountPaise]];
            $allocated = 0;
            foreach ($allocations as $billId => $paise) {
                $bill = SalesBill::query()->lockForUpdate()->find($billId);
                if ($bill === null || $bill->party_id !== $party->id || $bill->status !== SalesBill::POSTED) {
                    throw new BusinessRuleViolation('A selected invoice is not a posted invoice of this party.');
                }
                $outstanding = app(SalesBillService::class)->outstanding($bill);
                if ($paise <= 0 || $paise > $outstanding) {
                    throw new BusinessRuleViolation(sprintf('Invoice %s has only %s outstanding.', $bill->number, Money::paise($outstanding)->format()));
                }
                $lines[] = ['account' => $partyLedger, 'cr' => $paise, 'bill_id' => $bill->id, 'memo' => "Against {$bill->number}"];
                $allocated += $paise;
            }
            if ($allocated > $amountPaise) {
                throw new BusinessRuleViolation('Invoices selected add up to more than the amount received.');
            }
            if ($amountPaise > $allocated) {
                $lines[] = ['account' => $partyLedger, 'cr' => $amountPaise - $allocated, 'memo' => 'On account'];
            }

            $voucher = $this->books->post('receipt', $date, $lines, $narration ?? "Received from {$party->name}");
            foreach ($allocations as $billId => $paise) {
                ReceiptAllocation::query()->create(['voucher_id' => $voucher->id, 'sales_bill_id' => $billId, 'amount_paise' => $paise]);
            }

            return $voucher;
        });
    }

    /** Cash ↔ bank, bank ↔ bank. */
    public function contra(LedgerAccount $from, LedgerAccount $to, int $amountPaise, CarbonInterface $date, ?string $narration = null): Voucher
    {
        $this->require();
        foreach ([$from, $to] as $account) {
            if (! in_array($account->kind, ['bank', 'cash'], true)) {
                throw new BusinessRuleViolation('Contra entries move money between cash and bank ledgers only.');
            }
        }
        if ($from->id === $to->id || $amountPaise <= 0) {
            throw new BusinessRuleViolation('Choose two different ledgers and a positive amount.');
        }

        return $this->books->post('contra', $date, [
            ['account' => $to, 'dr' => $amountPaise],
            ['account' => $from, 'cr' => $amountPaise],
        ], $narration);
    }

    /**
     * Adjustments between ledgers. Bank and cash ledgers are excluded: money moves only through
     * payments, receipts and contra entries, so a journal cannot hide a withdrawal.
     *
     * @param  list<array{account: LedgerAccount, dr?: int, cr?: int, memo?: string|null}>  $lines
     */
    public function journal(CarbonInterface $date, array $lines, string $narration): Voucher
    {
        $this->require();
        if (mb_strlen(trim($narration)) < 5) {
            throw new BusinessRuleViolation('A journal needs a narration explaining it.');
        }
        foreach ($lines as $line) {
            if (in_array($line['account']->kind, ['bank', 'cash'], true)) {
                throw new BusinessRuleViolation('Bank and cash ledgers cannot be used in a journal; use a payment, receipt or contra.');
            }
        }

        return $this->books->post('journal', $date, $lines, $narration);
    }

    private function require(): void
    {
        if (! ($this->actor->account()?->hasPermission('ledger.post') ?? false)) {
            throw new BusinessRuleViolation("You need 'ledger.post' for this.");
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Auth\ActorContext;
use App\Domain\Shared\BusinessRuleViolation;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\Voucher;
use App\Models\Masters\Party;
use Carbon\CarbonInterface;

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

    /** Money received from a party into a bank or cash ledger. */
    public function receipt(Party $party, LedgerAccount $into, int $amountPaise, CarbonInterface $date, ?string $narration = null): Voucher
    {
        $this->require();
        if (! in_array($into->kind, ['bank', 'cash'], true)) {
            throw new BusinessRuleViolation('Receive into a bank or cash ledger.');
        }
        if ($amountPaise <= 0) {
            throw new BusinessRuleViolation('Amount must be more than zero.');
        }

        return $this->books->post('receipt', $date, [
            ['account' => $into, 'dr' => $amountPaise],
            ['account' => $this->chart->partyLedger($party), 'cr' => $amountPaise],
        ], $narration ?? "Received from {$party->name}");
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

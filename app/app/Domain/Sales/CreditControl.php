<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Accounts\Books;
use App\Domain\Accounts\ChartOfAccounts;
use App\Models\Masters\Party;
use App\Models\Sales\DispatchOrder;
use App\Models\Sales\SalesBill;

/**
 * A buyer's credit exposure: what they owe in the books (debit balance of their ledger, so
 * advances count in their favour) + invoices drafted but not yet posted + goods ordered for
 * dispatch but not yet billed. A party without a credit limit is not checked.
 */
final class CreditControl
{
    public function __construct(
        private readonly Books $books,
        private readonly ChartOfAccounts $chart,
    ) {}

    public function exposure(Party $party, ?string $exceptOrderId = null): int
    {
        $ledger = max(0, $this->books->balance($this->chart->partyLedger($party)));
        $drafts = (int) SalesBill::query()->where('party_id', $party->id)->where('status', SalesBill::DRAFT)->sum('total_paise');
        $orders = (int) DispatchOrder::query()->where('party_id', $party->id)->whereIn('status', DispatchOrder::EXPOSED)
            ->when($exceptOrderId, fn ($q) => $q->whereKeyNot($exceptOrderId))
            ->sum('estimated_value_paise');

        return $ledger + $drafts + $orders;
    }

    /** Null when the party has no limit (not checked). */
    public function headroom(Party $party, ?string $exceptOrderId = null): ?int
    {
        return $party->credit_limit_paise === null ? null : $party->credit_limit_paise - $this->exposure($party, $exceptOrderId);
    }

    public function allows(Party $party, int $additionalPaise, ?string $exceptOrderId = null): bool
    {
        $headroom = $this->headroom($party, $exceptOrderId);

        return $headroom === null || $additionalPaise <= $headroom;
    }
}

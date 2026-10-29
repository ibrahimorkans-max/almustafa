<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Claims\ClaimService;
use App\Domain\Saas\DataVisibility;
use App\Models\Accounts\AccountGroup;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\Payment;
use App\Models\Accounts\PaymentAllocation;
use App\Models\Accounts\PurchaseBill;
use App\Models\Accounts\ReceiptAllocation;
use App\Models\Accounts\VoucherLine;
use App\Models\Sales\SalesBill;
use Carbon\CarbonInterface;

/**
 * Read-only books from voucher lines. Amounts in paise; a balance is debit-positive.
 */
final class AccountReports
{
    /**
     * @return array{opening: int, closing: int, lines: list<array{date: string, number: string, type: string, narration: string|null, memo: string|null, dr: int, cr: int, balance: int}>}
     */
    public function ledgerStatement(LedgerAccount $account, CarbonInterface $from, CarbonInterface $to): array
    {
        // Before the customer's window only the opening balance shows (the books themselves are complete).
        $from = DataVisibility::clampDate($from, 'accounts');
        $opening = (int) VoucherLine::query()->where('ledger_account_id', $account->id)
            ->where('voucher_date', '<', $from->toDateString())
            ->selectRaw('COALESCE(SUM(dr_paise) - SUM(cr_paise), 0) AS b')->value('b');

        $balance = $opening;
        $lines = [];
        $rows = VoucherLine::query()->where('ledger_account_id', $account->id)
            ->whereBetween('voucher_date', [$from->toDateString(), $to->toDateString()])
            ->with('voucher')->orderBy('voucher_date')->orderBy('voucher_id')->orderBy('line_no')->get();
        foreach ($rows as $l) {
            $balance += $l->dr_paise - $l->cr_paise;
            $lines[] = [
                'date' => $l->voucher_date->toDateString(), 'number' => $l->voucher->number, 'type' => $l->voucher->type,
                'narration' => $l->voucher->narration, 'memo' => $l->memo, 'dr' => $l->dr_paise, 'cr' => $l->cr_paise, 'balance' => $balance,
            ];
        }

        return ['opening' => $opening, 'closing' => $balance, 'lines' => $lines];
    }

    /**
     * @return array{rows: list<array{code: string, name: string, group: string, nature: string, dr: int, cr: int}>, total_dr: int, total_cr: int}
     */
    public function trialBalance(CarbonInterface $asOf): array
    {
        $sums = VoucherLine::query()->toBase()->where('voucher_date', '<=', $asOf->toDateString())
            ->groupBy('ledger_account_id')->selectRaw('ledger_account_id, SUM(dr_paise) AS dr, SUM(cr_paise) AS cr')
            ->get()->keyBy('ledger_account_id');
        $groups = AccountGroup::query()->get()->keyBy('id');

        $rows = [];
        $totalDr = 0;
        $totalCr = 0;
        foreach (LedgerAccount::query()->whereIn('id', $sums->keys())->orderBy('code')->get() as $account) {
            $net = (int) $sums[$account->id]->dr - (int) $sums[$account->id]->cr;
            if ($net === 0) {
                continue;
            }
            $group = $groups[$account->group_id];
            $rows[] = [
                'code' => $account->code, 'name' => $account->displayName(), 'group' => $group->name, 'nature' => $group->nature,
                'dr' => max($net, 0), 'cr' => max(-$net, 0),
            ];
            $totalDr += max($net, 0);
            $totalCr += max(-$net, 0);
        }

        return ['rows' => $rows, 'total_dr' => $totalDr, 'total_cr' => $totalCr];
    }

    /** @return list<array{number: string, type: string, narration: string|null, total: int, lines: list<array{account: string, dr: int, cr: int}>}> */
    public function dayBook(CarbonInterface $date): array
    {
        $out = [];
        $lines = VoucherLine::query()->where('voucher_date', $date->toDateString())->with(['voucher', 'account'])
            ->orderBy('voucher_id')->orderBy('line_no')->get()->groupBy('voucher_id');
        foreach ($lines as $group) {
            $v = $group->first()->voucher;
            $out[] = [
                'number' => $v->number, 'type' => $v->type, 'narration' => $v->narration, 'total' => $v->total_paise,
                'lines' => $group->map(fn (VoucherLine $l) => ['account' => $l->account->displayName(), 'dr' => $l->dr_paise, 'cr' => $l->cr_paise])->values()->all(),
            ];
        }

        return $out;
    }

    /**
     * Unpaid purchase bills by age of the due date. "Paid" counts only released payments; money
     * requested but not yet released is shown separately.
     *
     * @return list<array{bill_id: string, number: string, party: string, bill_date: string, due_date: string, net: int, paid: int, requested: int, outstanding: int, days_overdue: int, bucket: string}>
     */
    public function payablesAgeing(CarbonInterface $asOf): array
    {
        $rows = [];
        $bills = PurchaseBill::query()->where('status', PurchaseBill::POSTED)->with('party')->orderBy('due_date')->get();
        $alloc = PaymentAllocation::query()->toBase()
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->whereIn('payment_allocations.purchase_bill_id', $bills->pluck('id'))
            ->groupBy('payment_allocations.purchase_bill_id', 'payments.status')
            ->selectRaw('payment_allocations.purchase_bill_id AS bill_id, payments.status AS status, SUM(payment_allocations.amount_paise) AS amount')
            ->get();

        foreach ($bills as $bill) {
            $mine = $alloc->where('bill_id', $bill->id);
            $paid = (int) $mine->where('status', Payment::APPROVED)->sum('amount');
            $requested = (int) $mine->whereIn('status', [Payment::PENDING, Payment::FIRST_APPROVED])->sum('amount');
            $outstanding = $bill->net_paise - $paid - ClaimService::notedOn($bill->id);
            if ($outstanding <= 0) {
                continue;
            }
            $days = (int) max(0, $bill->due_date->diffInDays($asOf, false));
            $rows[] = [
                'bill_id' => $bill->id, 'number' => (string) $bill->number, 'party' => $bill->party->name,
                'bill_date' => $bill->bill_date->toDateString(), 'due_date' => $bill->due_date->toDateString(),
                'net' => $bill->net_paise, 'paid' => $paid, 'requested' => $requested, 'outstanding' => $outstanding,
                'days_overdue' => $days, 'bucket' => self::bucket($days, $bill->due_date->greaterThan($asOf)),
            ];
        }

        return $rows;
    }

    /**
     * Unpaid sales invoices by age of the due date.
     *
     * @return list<array{bill_id: string, number: string, party: string, bill_date: string, due_date: string, total: int, received: int, outstanding: int, days_overdue: int, bucket: string}>
     */
    public function receivablesAgeing(CarbonInterface $asOf): array
    {
        $rows = [];
        $bills = SalesBill::query()->where('status', SalesBill::POSTED)->with('party')->orderBy('due_date')->get();
        $received = ReceiptAllocation::query()->toBase()->whereIn('sales_bill_id', $bills->pluck('id'))
            ->groupBy('sales_bill_id')->selectRaw('sales_bill_id, SUM(amount_paise) AS amount')->pluck('amount', 'sales_bill_id');

        foreach ($bills as $bill) {
            $got = (int) ($received[$bill->id] ?? 0);
            $outstanding = $bill->total_paise - $got - ClaimService::notedOn($bill->id);
            if ($outstanding <= 0) {
                continue;
            }
            $days = (int) max(0, $bill->due_date->diffInDays($asOf, false));
            $rows[] = [
                'bill_id' => $bill->id, 'number' => (string) $bill->number, 'party' => $bill->party->name,
                'bill_date' => $bill->bill_date->toDateString(), 'due_date' => $bill->due_date->toDateString(),
                'total' => $bill->total_paise, 'received' => $got, 'outstanding' => $outstanding,
                'days_overdue' => $days, 'bucket' => self::bucket($days, $bill->due_date->greaterThan($asOf)),
            ];
        }

        return $rows;
    }

    public static function bucket(int $daysOverdue, bool $notYetDue): string
    {
        return match (true) {
            $notYetDue => 'not due',
            $daysOverdue <= 30 => '0–30',
            $daysOverdue <= 60 => '31–60',
            $daysOverdue <= 90 => '61–90',
            default => '90+',
        };
    }
}

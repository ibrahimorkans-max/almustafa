<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Saas\DataVisibility;
use App\Models\Accounts\LedgerAccount;
use App\Models\Accounts\VoucherLine;
use App\Support\Values\Money;
use Carbon\CarbonImmutable;

/**
 * The cash book and the bank book, from the posted vouchers (the books are the only source):
 *   for each cash (or bank) account: a daily summary — opening, receipts, payments, closing — and the
 *   entries line by line, each with the account on the other side, the voucher and its narration.
 * Receipts are debits to the cash/bank account, payments credits; transfers between two cash or bank
 * accounts show in both, as they move money between them.
 */
final class CashBankBooks
{
    /**
     * @param  'cash'|'bank'  $kind
     * @return list<array{title: string, subtitle: string, columns: list<string>, rows: list<list<string>>, right: list<int>, lines: list<string>}>
     */
    public function build(string $kind, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = DataVisibility::clampDate($from, 'accounts');
        $span = $from->isSameDay($to) ? $from->format('d M Y') : $from->format('d M Y').' – '.$to->format('d M Y');
        $book = $kind === 'cash' ? 'Cash book' : 'Bank book';
        $parts = [];
        $accounts = LedgerAccount::query()->where('kind', $kind)->orderBy('code')->get();
        foreach ($accounts as $account) {
            $parts = array_merge($parts, $this->account($account, $book, $span, $from, $to));
        }
        if ($parts === []) {
            $parts[] = ['title' => $book, 'subtitle' => $span, 'columns' => [], 'rows' => [], 'right' => [],
                'lines' => ['No '.($kind === 'cash' ? 'cash' : 'bank').' account in the books yet.']];
        }

        return $parts;
    }

    /** @return list<array<string, mixed>> the daily summary and the entries of one account */
    private function account(LedgerAccount $account, string $book, string $span, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rs = fn (int $p) => Money::paise($p)->grouped();
        $opening = (int) VoucherLine::query()->where('ledger_account_id', $account->id)->where('voucher_date', '<', $from->toDateString())
            ->selectRaw('COALESCE(SUM(dr_paise) - SUM(cr_paise), 0) AS b')->value('b');
        $lines = VoucherLine::query()->with('voucher')->where('ledger_account_id', $account->id)
            ->whereBetween('voucher_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('voucher_date')->orderBy('voucher_id')->orderBy('line_no')->get();

        // The other side of each voucher, for "received from / paid to".
        $others = VoucherLine::query()->with('account')->whereIn('voucher_id', $lines->pluck('voucher_id')->unique())
            ->where('ledger_account_id', '<>', $account->id)->get()->groupBy('voucher_id');

        $balance = $opening;
        $entries = [];
        $days = [];
        foreach ($lines as $l) {
            $day = $l->voucher_date->toDateString();
            $days[$day] ??= ['opening' => $balance, 'in' => 0, 'out' => 0];
            $balance += $l->dr_paise - $l->cr_paise;
            $days[$day]['in'] += $l->dr_paise;
            $days[$day]['out'] += $l->cr_paise;
            $days[$day]['closing'] = $balance;
            $other = ($others[$l->voucher_id] ?? collect())->map(fn (VoucherLine $o) => $o->account->name ?? '')->unique()->implode(', ');
            $entries[] = [$l->voucher_date->format('d M Y'), (string) $l->voucher->number, $other, (string) ($l->memo ?: $l->voucher->narration),
                $l->dr_paise ? $rs($l->dr_paise) : '', $l->cr_paise ? $rs($l->cr_paise) : '', $rs($balance)];
        }
        $totalIn = (int) $lines->sum('dr_paise');
        $totalOut = (int) $lines->sum('cr_paise');

        $summary = [];
        foreach ($days as $day => $d) {
            $summary[] = [CarbonImmutable::parse($day)->format('d M Y'), $rs($d['opening']), $rs($d['in']), $rs($d['out']), $rs($d['closing'])];
        }
        $summary[] = ['TOTAL', $rs($opening), $rs($totalIn), $rs($totalOut), $rs($balance)];
        array_unshift($entries, ['', '', 'Opening balance', '', '', '', $rs($opening)]);
        $entries[] = ['TOTAL', '', '', 'Closing balance', $rs($totalIn), $rs($totalOut), $rs($balance)];

        $name = $book.': '.$account->name;

        return [
            ['title' => $name.' — daily closing', 'subtitle' => $span, 'columns' => ['Day', 'Opening', 'Received', 'Paid', 'Closing'],
                'rows' => $summary, 'right' => [1, 2, 3, 4], 'lines' => $lines->isEmpty() ? ['No entries in this period.'] : []],
            ['title' => $name, 'subtitle' => $span, 'columns' => ['Date', 'Voucher', 'Received from / paid to', 'Particulars', 'Received', 'Paid', 'Balance'],
                'rows' => $entries, 'right' => [4, 5, 6], 'lines' => []],
        ];
    }
}

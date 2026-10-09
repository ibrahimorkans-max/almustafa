<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Accounts\AccountReports;
use App\Domain\Approvals\ApprovalService;
use App\Models\Alert;
use App\Models\ApprovalRequest;
use App\Models\Gate\GateEntry;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockEntry;
use App\Models\LoginAccount;
use Illuminate\Support\Facades\DB;

/** Figures for the dashboard, read from the records (same numbers for every role that may see them). */
final class DashboardMetrics
{
    public function __construct(
        private readonly AccountReports $reports,
        private readonly ApprovalService $approvals,
    ) {}

    /** @return array{inside: int, arrived_today: int, exited_today: int, received_today_kg: string, dispatched_today_kg: string, received_month_kg: string, dispatched_month_kg: string} */
    public function operations(): array
    {
        // PKT day / month boundaries, as UTC instants (timestamps are stored in UTC and the query
        // builder does not convert a Carbon's timezone).
        $today = now('Asia/Karachi')->startOfDay()->utc();
        $month = now('Asia/Karachi')->startOfMonth()->utc();
        $sum = fn (string $movement, $from) => number_format(abs((float) StockEntry::query()->where('movement', $movement)
            ->where('occurred_at', '>=', $from)->sum('quantity_kg')), 0, '.', '');

        return [
            'inside' => GateEntry::query()->whereNotIn('status', ['exited', 'cancelled'])->whereNull('archived_at')->count(),
            'arrived_today' => GateEntry::query()->where('arrived_at', '>=', $today)->count(),
            'exited_today' => GateEntry::query()->where('exited_at', '>=', $today)->count(),
            'received_today_kg' => $sum(StockEntry::RECEIPT, $today),
            'dispatched_today_kg' => $sum(StockEntry::DISPATCH, $today),
            'received_month_kg' => $sum(StockEntry::RECEIPT, $month),
            'dispatched_month_kg' => $sum(StockEntry::DISPATCH, $month),
        ];
    }

    /** @return array<string, string> stock in kg per product category */
    public function stockByCategory(): array
    {
        return StockBalance::query()->toBase()
            ->join('products', 'products.id', '=', 'stock_balances.product_id')
            ->groupBy('products.category')
            ->select('products.category', DB::raw('SUM(stock_balances.quantity_kg) AS kg'))
            ->pluck('kg', 'category')->map(fn ($kg) => number_format((float) $kg, 0, '.', ''))->all();
    }

    /** @return array{receivable: int, receivable_overdue: int, payable: int, payable_due_7d: int} */
    public function money(): array
    {
        $now = now('Asia/Karachi');
        $in = $this->reports->receivablesAgeing($now);
        $out = $this->reports->payablesAgeing($now);
        $week = $now->copy()->addDays(7)->toDateString();

        return [
            'receivable' => array_sum(array_column($in, 'outstanding')),
            'receivable_overdue' => array_sum(array_column(array_filter($in, fn ($r) => $r['bucket'] !== 'not due'), 'outstanding')),
            'payable' => array_sum(array_column($out, 'outstanding')),
            'payable_due_7d' => array_sum(array_column(array_filter($out, fn ($r) => $r['due_date'] <= $week), 'outstanding')),
        ];
    }

    /** @return array{open_alerts: int, approvals_for_me: int} */
    public function attention(LoginAccount $me): array
    {
        $pending = ApprovalRequest::query()->where('status', ApprovalRequest::STATUS_PENDING)->get()
            ->filter(fn (ApprovalRequest $r) => $this->approvals->canDecide($me, $r))->count();

        return ['open_alerts' => Alert::query()->where('status', 'open')->count(), 'approvals_for_me' => $pending];
    }
}

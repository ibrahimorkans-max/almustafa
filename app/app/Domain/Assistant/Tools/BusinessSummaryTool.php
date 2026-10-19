<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tools;

use App\Domain\Accounts\FinancialReports;
use App\Domain\Assistant\Tool;
use App\Domain\Assistant\ToolResult;
use App\Filament\Pages\BusinessReportPage;
use App\Models\DailyWages\DailyWageSheet;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;
use App\Support\Values\Money;
use Carbon\CarbonImmutable;

/** Income, expenses, profit or loss and vehicles for today, this month, last month or this year. */
final class BusinessSummaryTool extends Tool
{
    public function name(): string
    {
        return 'business_summary';
    }

    public function description(): string
    {
        return 'Income (sales), purchases and expenses, gross and net profit or loss, and vehicles in and out, for a period of the working mill.';
    }

    public function properties(): array
    {
        return ['period' => ['type' => 'string', 'enum' => ['today', 'yesterday', 'this_month', 'last_month', 'this_year'], 'description' => 'Default this_month.']];
    }

    public function permission(): string
    {
        return 'ledger.view';
    }

    public function patterns(): array
    {
        return [
            '/\b(profit|loss|income|expenses?|summary|kamai|munafa|nuqsan)\b.*\b(?<period>today|yesterday|this month|last month|this year)\b/i',
            '/\b(profit|loss|income|expenses?|summary|kamai|munafa|nuqsan)\b/i',
        ];
    }

    public function examples(): array
    {
        return ['profit this month', 'income today'];
    }

    public function run(array $input, LoginAccount $me): ToolResult
    {
        $period = str_replace(' ', '_', strtolower((string) ($input['period'] ?? 'this_month')));
        $today = CarbonImmutable::now('Asia/Karachi')->startOfDay();
        [$from, $to, $label] = match ($period) {
            'today' => [$today, $today, 'Today'],
            'yesterday' => [$today->subDay(), $today->subDay(), 'Yesterday'],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay(), 'Last month'],
            'this_year' => [$today->month >= 7 ? $today->setDate($today->year, 7, 1) : $today->setDate($today->year - 1, 7, 1), $today, 'This financial year'],
            default => [$today->startOfMonth(), $today, 'This month'],
        };
        $pl = app(FinancialReports::class)->profitAndLoss($from, $to);
        $start = $from->utc();
        $end = $to->endOfDay()->utc();
        $in = GateEntry::query()->whereNull('archived_at')->where('direction', 'inbound')->whereBetween('arrived_at', [$start, $end])->count();
        $out = GateEntry::query()->whereNull('archived_at')->where('direction', 'outbound')->whereBetween('arrived_at', [$start, $end])->count();
        $rs = fn (int $p) => Money::paise($p)->format();
        $net = $pl['net_profit'];

        return new ToolResult("{$label} ({$from->format('d M')} – {$to->format('d M Y')}):", [
            ['label' => 'Complete business report (PDF / Excel)', 'url' => BusinessReportPage::getUrl()],
        ], ['columns' => ['Figure', 'Amount'], 'rows' => [
            ['Sales', $rs($pl['sales']['total'])],
            ['Other income', $rs($pl['direct_incomes']['total'] + $pl['indirect_incomes']['total'])],
            ['Purchases', $rs($pl['purchases']['total'])],
            ['Daily wages (casual labour)', $rs((int) DailyWageSheet::query()->whereIn('status', [DailyWageSheet::POSTED, DailyWageSheet::PAID])
                ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->sum('net_paise'))],
            ['Expenses', $rs($pl['direct_expenses']['total'] + $pl['indirect_expenses']['total'])],
            ['Gross profit', $rs($pl['gross_profit'])],
            [$net >= 0 ? 'Net PROFIT' : 'Net LOSS', $rs(abs($net))],
            ['Vehicles in / out', "{$in} / {$out}"],
        ]]);
    }
}

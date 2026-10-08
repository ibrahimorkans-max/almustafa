<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Reports\DashboardMetrics;
use App\Filament\Support\Units;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Receivables and payables — only for roles that see the books. */
class MoneyOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('dashboard.view') && $user->hasPermission('ledger.view');
    }

    protected function getStats(): array
    {
        $m = app(DashboardMetrics::class)->money();

        return [
            Stat::make('Receivable', Units::paiseToRupees($m['receivable']))->description('Overdue: '.Units::paiseToRupees($m['receivable_overdue']))
                ->color($m['receivable_overdue'] > 0 ? 'warning' : 'gray'),
            Stat::make('Payable', Units::paiseToRupees($m['payable']))->description('Due in 7 days: '.Units::paiseToRupees($m['payable_due_7d'])),
        ];
    }
}

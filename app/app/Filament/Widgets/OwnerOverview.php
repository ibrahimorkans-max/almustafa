<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Reports\HrDashboardMetrics;
use App\Filament\Pages\FinancialReportsPage;
use App\Filament\Resources\DailyWages\DailyWageSheetResource;
use App\Filament\Support\Units;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** The month so far from the books: sales, profit, payroll cost and milling cost per maund. */
class OwnerOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected static ?string $pollingInterval = null;

    protected ?string $heading = 'This month from the books';

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('dashboard.view') && $user->hasPermission('ledger.view');
    }

    protected function getStats(): array
    {
        $o = app(HrDashboardMetrics::class)->owner();
        $pl = FinancialReportsPage::getUrl(['report' => 'pl']);

        return [
            Stat::make('Sales', Units::paiseToRupees($o['sales']))->description($o['month'])->url($pl),
            Stat::make($o['net_profit'] >= 0 ? 'Net profit so far' : 'Net loss so far', Units::paiseToRupees(abs($o['net_profit'])))
                ->color($o['net_profit'] >= 0 ? 'success' : 'danger')->url($pl),
            Stat::make('Payroll cost', Units::paiseToRupees($o['payroll_cost']))->description('Posted payroll this month'),
            Stat::make('Daily wages', Units::paiseToRupees($o['daily_wages']))
                ->description($o['daily_wages_owed'] > 0 ? 'Still owed to groups: '.Units::paiseToRupees($o['daily_wages_owed']) : 'Casual labour this month')
                ->color($o['daily_wages_owed'] > 0 ? 'warning' : 'gray')->url(DailyWageSheetResource::getUrl()),
            Stat::make('Milling cost per maund', $o['cost_per_maund'] === null ? '—' : Units::paiseToRupees($o['cost_per_maund']))
                ->description($o['cost_per_maund'] === null ? 'No paddy milled yet this month' : "{$o['maunds']} maunds milled"),
        ];
    }
}

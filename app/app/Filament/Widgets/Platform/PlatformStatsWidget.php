<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Platform;

use App\Domain\Saas\PlatformStats;
use App\Filament\Resources\Platform\CustomerResource;
use App\Filament\Resources\Platform\SaasInvoiceResource;
use App\Filament\Support\Units;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Platform → Overview: the money and the customers at a glance, with six-month trend lines. */
class PlatformStatsWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    /** Drawn with the page (not loaded afterwards), so the cards are never left empty. */
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->isPlatformAdmin();
    }

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $stats = app(PlatformStats::class);
        $s = $stats->summary();
        $income = $stats->income();
        $collected = array_map(fn ($m) => $m['collected'] / 100, $income);
        $invoiced = array_map(fn ($m) => $m['invoiced'] / 100, $income);
        $c = $s['customers'];
        $invoices = SaasInvoiceResource::getUrl();

        return [
            Stat::make('Monthly recurring income', Units::paiseToRupees($s['mrr_paise']))
                ->description('Paying customers at their plan price')->descriptionIcon('heroicon-m-arrow-trending-up')
                ->chart($invoiced)->color('primary'),
            Stat::make('Collected this month', Units::paiseToRupees($s['collected_paise']))
                ->description('Invoiced: '.Units::paiseToRupees($s['invoiced_paise']))->descriptionIcon('heroicon-m-banknotes')
                ->chart($collected)->color('success'),
            Stat::make('Overdue', Units::paiseToRupees($s['overdue_paise']))
                ->description('Outstanding in all: '.Units::paiseToRupees($s['outstanding_paise']))
                ->descriptionIcon($s['overdue_paise'] > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($s['overdue_paise'] > 0 ? 'danger' : 'success')
                ->url($invoices.'?'.http_build_query(['tableFilters' => ['overdue' => ['isActive' => true]]])),
            Stat::make('Active customers', (string) ($c['active'] + $c['past_due']))
                ->description("{$c['lifetime']} lifetime · {$c['past_due']} read-only")->descriptionIcon('heroicon-m-building-office-2')
                ->color('success')->url(CustomerResource::getUrl()),
            Stat::make('On free trial', (string) $c['trial'])
                ->description("{$c['trial_ended']} ended, waiting to pay · {$s['new_this_month']} new this month")->descriptionIcon('heroicon-m-clock')
                ->color('info')->url(CustomerResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'trial']]])),
            Stat::make('Payments to check', (string) $s['reported'])
                ->description($s['reported'] > 0 ? 'Customers say they have paid' : 'Nothing waiting')->descriptionIcon('heroicon-m-inbox-arrow-down')
                ->color($s['reported'] > 0 ? 'warning' : 'gray')
                ->url($invoices.'?'.http_build_query(['tableFilters' => ['reported' => ['isActive' => true]]])),
        ];
    }
}

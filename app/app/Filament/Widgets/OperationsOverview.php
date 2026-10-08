<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Reports\DashboardMetrics;
use App\Filament\Resources\AlertResource;
use App\Filament\Resources\ApprovalRequestResource;
use App\Filament\Resources\Gate\GateEntryResource;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OperationsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return Filament::auth()->user()?->can('dashboard.view') ?? false;
    }

    protected function getStats(): array
    {
        /** @var LoginAccount $me */
        $me = Filament::auth()->user();
        $metrics = app(DashboardMetrics::class);
        $ops = $metrics->operations();
        $attention = $metrics->attention($me);
        $q = fn (string $kg) => number_format(((float) $kg) / 100, 1).' qtl';

        return [
            Stat::make('Vehicles inside', (string) $ops['inside'])
                ->description("{$ops['arrived_today']} arrived · {$ops['exited_today']} left today")
                ->url(GateEntryResource::getUrl('index')),
            Stat::make('Paddy received today', $q($ops['received_today_kg']))->description('Month: '.$q($ops['received_month_kg'])),
            Stat::make('Dispatched today', $q($ops['dispatched_today_kg']))->description('Month: '.$q($ops['dispatched_month_kg'])),
            Stat::make('Waiting for my approval', (string) $attention['approvals_for_me'])
                ->color($attention['approvals_for_me'] > 0 ? 'warning' : 'gray')->url(ApprovalRequestResource::getUrl('index')),
            Stat::make('Open alerts', (string) $attention['open_alerts'])
                ->color($attention['open_alerts'] > 0 ? 'danger' : 'success')
                ->url(($me->hasPermission('stock.view') || $me->hasPermission('production.view')) ? AlertResource::getUrl('index') : null),
        ];
    }
}

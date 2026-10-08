<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Reports\DashboardMetrics;
use App\Filament\Resources\Inventory\StockBalanceResource;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StockOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->hasPermission('dashboard.view') && $user->hasPermission('stock.view');
    }

    protected function getStats(): array
    {
        $stock = app(DashboardMetrics::class)->stockByCategory();
        $stats = [];
        foreach (['paddy' => 'Paddy in stock', 'rice' => 'Rice in stock', 'by_product' => 'By-products in stock'] as $category => $label) {
            $stats[] = Stat::make($label, number_format(((float) ($stock[$category] ?? 0)) / 100, 1).' qtl')->url(StockBalanceResource::getUrl('index'));
        }

        return $stats;
    }
}

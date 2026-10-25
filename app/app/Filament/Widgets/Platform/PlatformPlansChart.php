<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Platform;

use App\Domain\Saas\PlatformStats;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/** Platform → Overview: how many customers are on each plan. */
class PlatformPlansChart extends ChartWidget
{
    protected static ?string $heading = 'Customers per plan';

    protected static ?string $pollingInterval = null;

    /** Drawn with the page (not loaded afterwards), so the cards are never left empty. */
    protected static bool $isLazy = false;

    protected static ?string $maxHeight = '220px';

    private const COLOURS = ['rgb(16, 185, 129)', 'rgb(59, 130, 246)', 'rgb(245, 158, 11)', 'rgb(99, 102, 241)', 'rgb(236, 72, 153)', 'rgb(148, 163, 184)'];

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->isPlatformAdmin();
    }

    protected function getType(): string
    {
        return 'pie';
    }

    protected function getData(): array
    {
        $mix = app(PlatformStats::class)->planMix();

        return [
            'labels' => array_keys($mix),
            'datasets' => [[
                'data' => array_values($mix),
                'backgroundColor' => array_slice(array_merge(self::COLOURS, self::COLOURS), 0, count($mix)),
                'borderWidth' => 0,
            ]],
        ];
    }

    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['position' => 'bottom']], 'scales' => ['x' => ['display' => false], 'y' => ['display' => false]]];
    }
}

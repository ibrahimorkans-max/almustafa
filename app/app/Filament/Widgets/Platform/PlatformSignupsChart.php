<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Platform;

use App\Domain\Saas\PlatformStats;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/** Platform → Overview: new customers a month. */
class PlatformSignupsChart extends ChartWidget
{
    protected static ?string $heading = 'New customers';

    protected static ?string $pollingInterval = null;

    /** Drawn with the page (not loaded afterwards), so the cards are never left empty. */
    protected static bool $isLazy = false;

    protected static ?string $maxHeight = '275px';

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->isPlatformAdmin();
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $rows = app(PlatformStats::class)->signups();

        return [
            'labels' => array_column($rows, 'month'),
            'datasets' => [
                ['label' => 'New customers', 'data' => array_map(fn ($r) => $r['signup'] + $r['platform'], $rows), 'borderColor' => 'rgb(16, 185, 129)',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.15)', 'fill' => true, 'tension' => 0.35],
            ],
        ];
    }

    protected function getOptions(): array
    {
        // The same height as the two round charts beside it.
        return ['aspectRatio' => 1.35, 'plugins' => ['legend' => ['position' => 'bottom']], 'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]]];
    }
}

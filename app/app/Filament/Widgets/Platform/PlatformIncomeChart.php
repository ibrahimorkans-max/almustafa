<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Platform;

use App\Domain\Saas\PlatformStats;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/** Platform → Overview: invoiced and collected, month by month (Rs). */
class PlatformIncomeChart extends ChartWidget
{
    protected static ?string $heading = 'Income, last six months';

    protected static ?string $pollingInterval = null;

    /** Drawn with the page (not loaded afterwards), so the cards are never left empty. */
    protected static bool $isLazy = false;

    protected static ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = ['md' => 2];

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->isPlatformAdmin();
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $income = app(PlatformStats::class)->income();

        return [
            'labels' => array_column($income, 'month'),
            'datasets' => [
                ['label' => 'Invoiced (Rs)', 'data' => array_map(fn ($m) => round($m['invoiced'] / 100), $income),
                    'backgroundColor' => 'rgba(59, 130, 246, 0.35)', 'borderColor' => 'rgb(59, 130, 246)', 'borderWidth' => 1, 'borderRadius' => 6],
                ['label' => 'Collected (Rs)', 'data' => array_map(fn ($m) => round($m['collected'] / 100), $income),
                    'backgroundColor' => 'rgba(16, 185, 129, 0.8)', 'borderColor' => 'rgb(16, 185, 129)', 'borderWidth' => 1, 'borderRadius' => 6],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['position' => 'bottom']], 'scales' => ['y' => ['beginAtZero' => true]]];
    }
}

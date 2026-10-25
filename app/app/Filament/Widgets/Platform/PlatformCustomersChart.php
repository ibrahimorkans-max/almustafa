<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Platform;

use App\Domain\Saas\PlatformStats;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/** Platform → Overview: customers by state. */
class PlatformCustomersChart extends ChartWidget
{
    protected static ?string $heading = 'Customers by state';

    protected static ?string $pollingInterval = null;

    /** Drawn with the page (not loaded afterwards), so the cards are never left empty. */
    protected static bool $isLazy = false;

    protected static ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = ['md' => 1];

    private const STATES = [
        'active' => ['Active', 'rgb(16, 185, 129)'],
        'lifetime' => ['Lifetime', 'rgb(99, 102, 241)'],
        'trial' => ['Free trial', 'rgb(59, 130, 246)'],
        'trial_ended' => ['Trial ended', 'rgb(148, 163, 184)'],
        'past_due' => ['Read-only', 'rgb(245, 158, 11)'],
        'suspended' => ['Blocked', 'rgb(239, 68, 68)'],
        'cancelled' => ['Closed', 'rgb(71, 85, 105)'],
    ];

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->isPlatformAdmin();
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $counts = app(PlatformStats::class)->summary()['customers'];
        $states = array_filter(self::STATES, fn (string $key) => ($counts[$key] ?? 0) > 0, ARRAY_FILTER_USE_KEY);

        return [
            'labels' => array_column($states, 0),
            'datasets' => [[
                'data' => array_values(array_map(fn (string $key) => $counts[$key], array_keys($states))),
                'backgroundColor' => array_column($states, 1),
                'borderWidth' => 0,
            ]],
        ];
    }

    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['position' => 'bottom']], 'cutout' => '65%', 'scales' => ['x' => ['display' => false], 'y' => ['display' => false]]];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Platform;

use App\Domain\Saas\PlatformStats;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/** Platform → Overview: the last twelve months' invoices by state (Rs): paid, not yet due, overdue, cancelled. */
class PlatformInvoicesChart extends ChartWidget
{
    protected static ?string $heading = 'Invoices, last 12 months (Rs)';

    protected static ?string $pollingInterval = null;

    /** Drawn with the page (not loaded afterwards), so the cards are never left empty. */
    protected static bool $isLazy = false;

    protected static ?string $maxHeight = '220px';

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
        $s = app(PlatformStats::class)->invoiceStates();

        return [
            'labels' => ['Paid', 'Not yet due', 'Overdue', 'Cancelled'],
            'datasets' => [[
                'data' => [round($s['paid'] / 100), round($s['open'] / 100), round($s['overdue'] / 100), round($s['void'] / 100)],
                'backgroundColor' => ['rgb(16, 185, 129)', 'rgb(59, 130, 246)', 'rgb(239, 68, 68)', 'rgb(148, 163, 184)'],
                'borderWidth' => 0,
            ]],
        ];
    }

    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['position' => 'bottom']], 'cutout' => '60%', 'scales' => ['x' => ['display' => false], 'y' => ['display' => false]]];
    }
}

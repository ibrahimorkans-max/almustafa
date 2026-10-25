<?php

declare(strict_types=1);

namespace App\Filament\Pages\Platform;

use App\Domain\Saas\PlatformStats;
use App\Filament\Resources\Platform\CustomerResource;
use App\Filament\Resources\Platform\PaymentMethodResource;
use App\Filament\Resources\Platform\SaasInvoiceResource;
use App\Filament\Widgets\Platform\PlatformCustomersChart;
use App\Filament\Widgets\Platform\PlatformIncomeChart;
use App\Filament\Widgets\Platform\PlatformInvoicesChart;
use App\Filament\Widgets\Platform\PlatformPlansChart;
use App\Filament\Widgets\Platform\PlatformSignupsChart;
use App\Filament\Widgets\Platform\PlatformStatsWidget;
use App\Models\LoginAccount;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * Platform → Overview (platform admin only): the business at a glance. Money and customers with trend
 * lines, income and customers charts, quick actions, and what needs attention: payments to check,
 * overdue invoices, trials ending, customers near a plan limit, the newest customers.
 */
class PlatformOverview extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'Platform overview';

    protected static ?string $slug = 'platform/overview';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.platform.overview';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->isPlatformAdmin();
    }

    public function getSubheading(): ?string
    {
        return now('Asia/Karachi')->format('l, d F Y');
    }

    public function stats(): PlatformStats
    {
        return app(PlatformStats::class);
    }

    protected function getHeaderWidgets(): array
    {
        return [PlatformStatsWidget::class, PlatformIncomeChart::class, PlatformCustomersChart::class,
            PlatformSignupsChart::class, PlatformPlansChart::class, PlatformInvoicesChart::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return ['md' => 3];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('customers')->label('New customer')->icon('heroicon-o-plus')->url(CustomerResource::getUrl()),
            Action::make('invoices')->label('Invoices')->icon('heroicon-o-document-currency-rupee')->color('gray')->url(SaasInvoiceResource::getUrl()),
            Action::make('methods')->label('Payment methods')->icon('heroicon-o-qr-code')->color('gray')->url(PaymentMethodResource::getUrl()),
        ];
    }
}

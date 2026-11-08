<?php

declare(strict_types=1);

namespace App\Filament\Pages\Platform;

use App\Filament\Pages\Concerns\ShowsUsage;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * Platform → API & communication costs (SaaS owner): every API call, SMS, WhatsApp message and e-mail of every
 * company and mill, what it was estimated to cost, what the provider reported and billed, and what each company is
 * charged — by company (click one for its records), mill, provider, category, module and day.
 */
class UsageCostsPage extends Page
{
    use ShowsUsage;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'API & message costs';

    protected static ?string $title = 'API & communication cost control';

    protected static ?string $slug = 'platform/usage';

    protected static ?int $navigationSort = 13;

    protected static string $view = 'filament.pages.usage';

    public static function canAccess(): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && $me->isPlatformAdmin();
    }

    public function forPlatform(): bool
    {
        return true;
    }

    protected function getHeaderActions(): array
    {
        return [$this->exportExcelAction(), $this->exportPdfAction()];
    }
}

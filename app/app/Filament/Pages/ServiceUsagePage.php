<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\ShowsUsage;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * Administration → Service usage (the Owner and Super Admins, or whoever is given "usage.view"): the API calls,
 * SMS, WhatsApp messages and e-mails of this company's mills only — when, by whom or which automatic process, for
 * what, and the estimated and actual cost. Export to Excel or PDF.
 */
class ServiceUsagePage extends Page
{
    use ShowsUsage;

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Service usage';

    protected static ?string $title = 'API, SMS, WhatsApp & e-mail usage';

    protected static ?string $slug = 'service-usage';

    protected static ?int $navigationSort = 92;

    protected static string $view = 'filament.pages.usage';

    public static function canAccess(): bool
    {
        $me = Filament::auth()->user();

        return $me instanceof LoginAccount && ! $me->isPlatformAdmin() && $me->hasPermission('usage.view');
    }

    public function forPlatform(): bool
    {
        return false;
    }

    protected function getHeaderActions(): array
    {
        return [$this->exportExcelAction(), $this->exportPdfAction()];
    }
}

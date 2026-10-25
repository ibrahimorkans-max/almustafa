<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Platform\PlatformOverview;
use App\Models\LoginAccount;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;

/**
 * The start page. An account whose only rights are the archive (the Office Coordinator role) has nothing
 * else to see, so it is taken straight to the Archive screen.
 */
class Dashboard extends BaseDashboard
{
    public function mount(): void
    {
        $user = Filament::auth()->user();
        // The product owner starts on the platform overview.
        if ($user instanceof LoginAccount && $user->isPlatformAdmin()) {
            throw new HttpResponseException(new RedirectResponse(PlatformOverview::getUrl()));
        }
        // A party signing in with its own login sees only its own account.
        if ($user instanceof LoginAccount && PartyPortalPage::canAccess() && ! $user->hasPermission('dashboard.view')) {
            throw new HttpResponseException(new RedirectResponse(PartyPortalPage::getUrl()));
        }
        if ($user instanceof LoginAccount && self::archiveOnly($user)) {
            // A plain HTTP redirect: the page itself is never rendered for this account.
            throw new HttpResponseException(new RedirectResponse(ArchiveSearch::getUrl()));
        }
    }

    /** The product owner's menu starts at Platform → Overview instead. */
    public static function shouldRegisterNavigation(): bool
    {
        $user = Filament::auth()->user();

        return ! ($user instanceof LoginAccount && $user->isPlatformAdmin());
    }

    public static function archiveOnly(LoginAccount $user): bool
    {
        $codes = array_values(array_diff($user->permissionCodes(), ['hr.attendance.punch', 'hr.leave.request']));

        return $codes !== [] && array_diff($codes, ['office.view', 'office.restore']) === [] && $user->hasPermission('office.view');
    }
}

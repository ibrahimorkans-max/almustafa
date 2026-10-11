<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\ChangePassword;
use App\Filament\Pages\DuressPin;
use App\Filament\Pages\SwitchContext;
use App\Filament\Pages\TwoFactor;
use App\Filament\Widgets\HrOverview;
use App\Filament\Widgets\MoneyOverview;
use App\Filament\Widgets\OperationsOverview;
use App\Filament\Widgets\OwnerOverview;
use App\Filament\Widgets\StockOverview;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Http\Middleware\RequireWorkingEntityForCreate;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName(config('ricemill.brand.name'))
            ->brandLogo(asset(config('ricemill.brand.logo_wide')))
            ->darkModeBrandLogo(asset(config('ricemill.brand.logo_wide_dark')))
            ->brandLogoHeight('3rem')
            ->favicon(asset(config('ricemill.brand.favicon')))
            // Narrower than Filament's 20rem default; can be collapsed to icons on desktop.
            ->sidebarWidth('15rem')
            ->collapsedSidebarWidth('4.5rem')
            ->sidebarCollapsibleOnDesktop()
            ->login(Login::class)
            ->authGuard('web')
            ->databaseNotifications()
            ->colors(['primary' => Color::hex(config('ricemill.brand.green')), 'warning' => Color::hex(config('ricemill.brand.gold'))])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([Dashboard::class])
            ->widgets([AccountWidget::class, OperationsOverview::class, MoneyOverview::class, OwnerOverview::class, HrOverview::class, StockOverview::class])
            ->navigationGroups(['Gate & weighbridge', 'Sales & dispatch', 'Stock & production', 'Accounts', 'Claims & pricing', 'Procurement', 'Masters', 'Administration', 'Security'])
            ->userMenuItems([
                MenuItem::make()->label('Switch entity / account')->icon('heroicon-o-arrows-right-left')
                    ->url(fn () => SwitchContext::getUrl()),
                MenuItem::make()->label('Two-factor authentication')->icon('heroicon-o-shield-check')
                    ->url(fn () => TwoFactor::getUrl()),
                MenuItem::make()->label('Change password')->icon('heroicon-o-key')
                    ->url(fn () => ChangePassword::getUrl()),
                MenuItem::make()->label('Duress PIN')->icon('heroicon-o-exclamation-triangle')
                    ->url(fn () => DuressPin::getUrl())->visible(fn () => DuressPin::canAccess()),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureWebAuthSession::class,
                RequireWorkingEntityForCreate::class,
            ], isPersistent: true);
    }
}

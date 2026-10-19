<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\ChangePassword;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\DuressPin;
use App\Filament\Pages\MyPasskeys;
use App\Filament\Pages\SwitchContext;
use App\Filament\Pages\TwoFactor;
use App\Filament\Widgets\HrOverview;
use App\Filament\Widgets\MoneyOverview;
use App\Filament\Widgets\OperationsOverview;
use App\Filament\Widgets\OwnerOverview;
use App\Filament\Widgets\StockOverview;
use App\Http\Controllers\PrintAnyRecordController;
use App\Http\Controllers\PrintRecordController;
use App\Http\Controllers\ReportPrintController;
use App\Http\Controllers\SessionLocationController;
use App\Http\Controllers\VehicleRegisterPrintController;
use App\Http\Middleware\EnsureWebAuthSession;
use App\Http\Middleware\RequireWorkingEntityForCreate;
use App\Livewire\PrintScreenHook;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // No font from the internet (fonts.bunny.net): pages load at once on a mill server without internet.
            ->font('Inter', provider: LocalFontProvider::class)
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
            ->navigationGroups(['Gate & weighbridge', 'Sales & dispatch', 'Stock & production', 'Accounts', 'Daily wages', 'Claims & pricing', 'Procurement', 'Masters', 'Administration', 'Security'])
            ->userMenuItems([
                MenuItem::make()->label('Switch entity / account')->icon('heroicon-o-arrows-right-left')
                    ->url(fn () => SwitchContext::getUrl()),
                MenuItem::make()->label('Two-factor authentication')->icon('heroicon-o-shield-check')
                    ->url(fn () => TwoFactor::getUrl()),
                MenuItem::make()->label('Change password')->icon('heroicon-o-key')
                    ->url(fn () => ChangePassword::getUrl()),
                MenuItem::make()->label('Passkeys')->icon('heroicon-o-finger-print')
                    ->url(fn () => MyPasskeys::getUrl()),
                MenuItem::make()->label('Duress PIN')->icon('heroicon-o-exclamation-triangle')
                    ->url(fn () => DuressPin::getUrl())->visible(fn () => DuressPin::canAccess()),
            ])
            // Passkeys on the sign-in page and "My passkeys"; the browser's position once after sign-in.
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('filament.hooks.security-scripts'))
            // "Print" on every list and record screen (answered by App\Livewire\PrintScreenHook).
            ->renderHook(PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE, fn (array $scopes = []) => PrintScreenHook::supports((string) ($scopes[0] ?? ''))
                ? view('filament.hooks.print-button') : '')
            ->authenticatedRoutes(function (): void {
                Route::post('session-location', SessionLocationController::class)->middleware('throttle:10,1')->name('session-location');
                Route::get('print/{type}/{id}', PrintRecordController::class)->whereUuid('id')->middleware('throttle:60,1')->name('print');
                Route::get('print-record/{resource}/{id}', PrintAnyRecordController::class)->whereUuid('id')->middleware('throttle:60,1')->name('print-record');
                Route::get('reports/print', ReportPrintController::class)->middleware('throttle:20,1')->name('reports.print');
                Route::middleware('throttle:60,1')->prefix('vehicle-register')->name('vehicle-register.')->group(function (): void {
                    Route::get('vehicle/{id}', [VehicleRegisterPrintController::class, 'vehicle'])->whereUuid('id')->name('vehicle');
                    Route::get('weighment/{id}', [VehicleRegisterPrintController::class, 'weighment'])->whereUuid('id')->name('weighment');
                    Route::get('day/{date}', [VehicleRegisterPrintController::class, 'day'])->name('day');
                });
            })
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

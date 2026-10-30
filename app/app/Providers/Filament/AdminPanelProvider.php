<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\ChangePassword;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\DuressPin;
use App\Filament\Pages\MyDevicesPage;
use App\Filament\Pages\MyPasskeys;
use App\Filament\Pages\SwitchContext;
use App\Filament\Pages\TwoFactor;
use App\Filament\Resources\Hr\HrScope;
use App\Filament\Widgets\HrOverview;
use App\Filament\Widgets\LiveWeightBoard;
use App\Filament\Widgets\MoneyOverview;
use App\Filament\Widgets\OperationsOverview;
use App\Filament\Widgets\OwnerOverview;
use App\Filament\Widgets\ProcurementOverview;
use App\Filament\Widgets\StockOverview;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\BankEmailCallbackController;
use App\Http\Controllers\BrandingPreviewController;
use App\Http\Controllers\ClaimEvidenceController;
use App\Http\Controllers\LiveWeightReadingController;
use App\Http\Controllers\PaperSizeController;
use App\Http\Controllers\PrintAnyRecordController;
use App\Http\Controllers\PrintJobController;
use App\Http\Controllers\PrintRecordController;
use App\Http\Controllers\PushTokenController;
use App\Http\Controllers\ReportPrintController;
use App\Http\Controllers\RoznamchaScanController;
use App\Http\Controllers\SaasInvoiceController;
use App\Http\Controllers\SessionLocationController;
use App\Http\Controllers\TokenSlipController;
use App\Http\Controllers\VehicleRegisterPrintController;
use App\Http\Middleware\ApplyDataVisibility;
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
            // The product (MillNexa); each mill's own name and logo are on its slips and invoices.
            ->brandName(config('ricemill.product.name'))
            ->brandLogo(asset(config('ricemill.product.logo')))
            ->darkModeBrandLogo(asset(config('ricemill.product.logo_dark')))
            ->brandLogoHeight('2.6rem')
            ->favicon(asset(config('ricemill.product.favicon')))
            // Narrower than Filament's 20rem default; can be collapsed to icons on desktop.
            ->sidebarWidth('15rem')
            ->collapsedSidebarWidth('4.5rem')
            ->sidebarCollapsibleOnDesktop()
            ->login(Login::class)
            ->authGuard('web')
            ->databaseNotifications()
            ->colors(['primary' => Color::hex(config('ricemill.product.green')), 'warning' => Color::hex(config('ricemill.product.gold'))])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([Dashboard::class])
            ->widgets([AccountWidget::class, OperationsOverview::class, ProcurementOverview::class, MoneyOverview::class, OwnerOverview::class, HrOverview::class, StockOverview::class])
            // Shown on Live weight and Vehicles at gate, not on the dashboard: registered by name so its clicks and typing
            // reach it (an unknown component is refused as "page expired" and the page reloads).
            ->livewireComponents([LiveWeightBoard::class])
            ->navigationGroups(['Platform', 'Procurement', 'Gate & weighbridge', 'Stock & production', 'Sales & dispatch', 'Claims & pricing', 'Accounts', HrScope::GROUP, 'Daily wages', 'Reports', 'Masters', 'Administration', 'Security'])
            ->userMenuItems([
                MenuItem::make()->label('Switch entity / account')->icon('heroicon-o-arrows-right-left')
                    ->url(fn () => SwitchContext::getUrl()),
                MenuItem::make()->label('Two-factor authentication')->icon('heroicon-o-shield-check')
                    ->url(fn () => TwoFactor::getUrl()),
                MenuItem::make()->label('Change password')->icon('heroicon-o-key')
                    ->url(fn () => ChangePassword::getUrl()),
                MenuItem::make()->label('Passkeys')->icon('heroicon-o-finger-print')
                    ->url(fn () => MyPasskeys::getUrl()),
                MenuItem::make()->label('My devices')->icon('heroicon-o-computer-desktop')
                    ->url(fn () => MyDevicesPage::getUrl()),
                MenuItem::make()->label('Duress PIN')->icon('heroicon-o-exclamation-triangle')
                    ->url(fn () => DuressPin::getUrl())->visible(fn () => DuressPin::canAccess()),
            ])
            // Passkeys on the sign-in page and "My passkeys"; the browser's position once after sign-in.
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('filament.hooks.security-scripts'))
            // "Print" on every list and record screen (answered by App\Livewire\PrintScreenHook).
            ->renderHook(PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE, fn (array $scopes = []) => PrintScreenHook::supports((string) ($scopes[0] ?? ''))
                ? view('filament.hooks.print-button', ['pageClass' => (string) ($scopes[0] ?? '')]) : '')
            // Paper for printing on this device (A4, A6, Letter, small roll): every slip and report follows it.
            // Top-bar search across all modules: Ctrl+K / Cmd+K, and every result on one page.
            ->globalSearchKeyBindings(['ctrl+k', 'command+k'])
            ->globalSearchDebounce('350ms')
            ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER, fn () => view('filament.hooks.search-all'))
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn () => view('filament.hooks.paper-size'))
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.hooks.product-head'))
            // SaaS: read-only for an overdue payment, an invoice due, the trial ending.
            ->renderHook(PanelsRenderHook::CONTENT_START, fn () => view('filament.hooks.billing-banner'))
            ->renderHook(PanelsRenderHook::CONTENT_START, fn () => view('filament.hooks.visibility-banner'))
            ->authenticatedRoutes(function (): void {
                // SaaS: an invoice to the customer (PDF), and the receipt it uploaded.
                Route::get('billing/invoices/{id}', [SaasInvoiceController::class, 'pdf'])->whereUuid('id')->middleware('throttle:30,1')->name('saas.invoice');
                Route::get('billing/invoices/{id}/receipt', [SaasInvoiceController::class, 'receipt'])->whereUuid('id')->middleware('throttle:30,1')->name('saas.receipt');
                Route::get('branding/preview', BrandingPreviewController::class)->middleware('throttle:20,1')->name('branding.preview');
                Route::get('gate/live-weight/{scale}', LiveWeightReadingController::class)->whereUuid('scale')->middleware('throttle:300,1')->name('gate.live-weight');
                Route::get('gate/token/{id}', TokenSlipController::class)->whereUuid('id')->middleware('throttle:60,1')->name('gate.token');
                // The current security token, for a page whose token went stale (signed in again in another tab).
                Route::get('csrf-token', fn () => response()->json(['token' => csrf_token()])->header('Cache-Control', 'no-store'))
                    ->middleware('throttle:30,1')->name('csrf-token');
                Route::post('push-token', PushTokenController::class)->middleware('throttle:20,1')->name('push-token');
                Route::post('print-paper', PaperSizeController::class)->middleware('throttle:30,1')->name('print-paper');
                Route::post('session-location', SessionLocationController::class)->middleware('throttle:10,1')->name('session-location');
                Route::get('print-job/{key}', PrintJobController::class)->where('key', '[A-Za-z0-9]{40}')->middleware('throttle:60,1')->name('print-job');
                Route::get('print/{type}/{id}', PrintRecordController::class)->whereUuid('id')->middleware('throttle:60,1')->name('print');
                Route::get('print-record/{resource}/{id}', PrintAnyRecordController::class)->whereUuid('id')->middleware('throttle:60,1')->name('print-record');
                // The answer of Google / Microsoft after a mailbox was signed in (Accounts → Bank e-mail).
                Route::get('bank-email/callback', BankEmailCallbackController::class)->middleware('throttle:20,1')->name('bank-email.callback');
                Route::get('roznamcha/{id}/scan', RoznamchaScanController::class)->whereUuid('id')->middleware('throttle:60,1')->name('roznamcha.scan');
                Route::get('attachments/{id}', AttachmentController::class)->whereUuid('id')->middleware('throttle:60,1')->name('attachment');
                Route::get('claims/{id}/evidence/{sha256}', ClaimEvidenceController::class)->whereUuid('id')->where('sha256', '[0-9a-f]{64}')
                    ->middleware('throttle:60,1')->name('claim-evidence');
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
                // SaaS: the customer sees only its visibility window (Platform → Data visibility).
                ApplyDataVisibility::class,
            ], isPersistent: true);
    }
}

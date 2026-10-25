<?php

declare(strict_types=1);

namespace App\Domain\Saas;

use App\Filament\Pages\ChangePassword;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\MyPasskeys;
use App\Filament\Pages\PaymentRequiredPage;
use App\Filament\Pages\SubscriptionPage;
use App\Filament\Pages\TwoFactor;
use Filament\Widgets\AccountWidget;
use Illuminate\Http\Request;
use Livewire\Mechanisms\ComponentRegistry;

/**
 * What a stopped customer may still open; everything else, pages and Livewire calls alike, is refused, so
 * an old browser tab cannot keep working either. Never the notification bell: its notices hold data.
 *  - Free trial over: the payment page (with its invoice PDFs and receipts), signing out, its own
 *    two-step sign-in and password.
 *  - Blocked: the start page (empty: no rights), a notice, My subscription for its Owners, its own
 *    sign-in settings and signing out; none of the company's data.
 */
final class PaymentGate
{
    private const COMMON_ROUTES = [
        'filament.admin.auth.logout',
        'filament.admin.pages.two-factor',
        'filament.admin.pages.change-password',
        'filament.admin.saas.invoice',
        'filament.admin.saas.receipt',
        'filament.admin.csrf-token',
    ];

    private const TRIAL_ROUTES = ['filament.admin.pages.payment-required'];

    private const BLOCKED_ROUTES = ['filament.admin.pages.dashboard', 'filament.admin.pages.subscription', 'filament.admin.pages.passkeys'];

    /** Free trial over: the payment page and nothing else. */
    public static function allows(Request $request): bool
    {
        return self::check($request, [...self::COMMON_ROUTES, ...self::TRIAL_ROUTES], [PaymentRequiredPage::class]);
    }

    /** Blocked: the empty start page, My subscription and the person's own sign-in settings. */
    public static function allowsBlocked(Request $request): bool
    {
        return self::check($request, [...self::COMMON_ROUTES, ...self::BLOCKED_ROUTES], [Dashboard::class, SubscriptionPage::class, MyPasskeys::class, AccountWidget::class]);
    }

    /**
     * @param  list<string>  $routes
     * @param  list<class-string>  $pages
     */
    private static function check(Request $request, array $routes, array $pages): bool
    {
        if (! $request->is('livewire/*')) {
            return in_array($request->route()?->getName(), $routes, true);
        }
        if (! $request->is('livewire/update')) {
            return true;   // Livewire's own scripts and file previews
        }
        $registry = app(ComponentRegistry::class);
        $allowed = [
            ...array_map(fn (string $class) => $registry->getName($class), $pages),
            $registry->getName(TwoFactor::class),
            $registry->getName(ChangePassword::class),
            'filament.livewire.notifications',   // the pop-up messages of the page itself (not the bell: its notices hold data)
        ];
        $components = (array) $request->input('components', []);
        foreach ($components as $component) {
            $snapshot = json_decode((string) ($component['snapshot'] ?? ''), true);
            $name = is_array($snapshot) ? ($snapshot['memo']['name'] ?? null) : null;
            if (! in_array($name, $allowed, true)) {
                return false;
            }
        }

        return $components !== [];
    }
}

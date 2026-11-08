<?php

declare(strict_types=1);

namespace App\Domain\Saas;

use App\Domain\Settings\SettingsService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;

/**
 * Who sees "My subscription" (the payment page: plan, price, invoices, ways to pay), set by the product owner
 * (Platform → Customers → Payment page):
 *  - all       every customer's Owner (the default);
 *  - none      nobody (billing is handled outside the system);
 *  - selected  only the customers ticked "Payment page" (customers.payment_page).
 * A blocked or read-only company is still told so on every page, without the link when the page is hidden.
 */
final class PaymentPage
{
    public const SETTING = 'saas.payment_page';

    public const MODES = [
        'all' => 'Show it to every customer',
        'none' => 'Hide it from every customer',
        'selected' => 'Show it only to the customers I tick',
    ];

    public static function mode(): string
    {
        $mode = (string) app(SettingsService::class)->get(self::SETTING);

        return isset(self::MODES[$mode]) ? $mode : 'all';
    }

    public static function setMode(string $mode): void
    {
        app(SettingsService::class)->put(self::SETTING, $mode);
    }

    /** Whether the Owners of this customer see the payment page. */
    public static function shownTo(?string $customerId): bool
    {
        return match (self::mode()) {
            'none' => false,
            'selected' => $customerId !== null
                && (bool) app(TenantContext::class)->runAsSystem(fn () => Customer::query()->whereKey($customerId)->value('payment_page')),
            default => true,
        };
    }
}

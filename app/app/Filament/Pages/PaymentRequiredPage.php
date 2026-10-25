<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Saas\Billing;
use App\Domain\Saas\CustomerAccess;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Pages\Concerns\PaysSubscription;
use App\Models\LoginAccount;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;

/**
 * The only screen a customer sees while its free trial is over or its access is paused: why, what is
 * owed (invoice PDFs), the ways to pay with their QR codes, and "I've paid" for its Owner. Every other
 * screen sends here (see PaymentGate); once the payment is marked received, everything opens again.
 */
class PaymentRequiredPage extends Page
{
    use PaysSubscription;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $title = 'Payment needed';

    protected static ?string $slug = 'payment-required';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.payment-required';

    /** No menu, no top bar: this page and nothing else. */
    protected static string $layout = 'filament-panels::components.layout.simple';

    protected function getLayoutData(): array
    {
        return ['hasTopbar' => false, 'maxWidth' => MaxWidth::FiveExtraLarge];
    }

    public function hasLogo(): bool
    {
        return true;
    }

    /** Only while the company must pay; anyone else gets the plain "no access" page. */
    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && app(CustomerAccess::class)->paymentDue($user) !== null;
    }

    public function mount(): void
    {
        // A trial that ended before the daily billing run: its first invoice now, so it can be paid.
        $customer = $this->customer();
        if ($customer !== null && $customer->trialEnded()) {
            app(TenantContext::class)->runAsSystem(fn () => app(Billing::class)->issueMonthly($customer, CarbonImmutable::now('Asia/Karachi'), afterTrial: true));
        }
    }

    /** trial_ended | suspended, or null. */
    public function reason(): ?string
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount ? app(CustomerAccess::class)->paymentDue($user) : null;
    }

    public function contact(): string
    {
        return trim((string) config('ricemill.saas.contact'));
    }
}

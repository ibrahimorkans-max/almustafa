<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Saas\Billing;
use App\Domain\Saas\CustomerAccess;
use App\Domain\Saas\Modules;
use App\Domain\Saas\PlanLimits;
use App\Filament\Pages\Concerns\PaysSubscription;
use App\Filament\Support\Units;
use App\Models\Customer;
use App\Models\LoginAccount;
use App\Models\Plan;
use App\Models\SaasInvoice;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * "My subscription" for a customer's Owner: its plan and price (or lifetime access), the modules it
 * includes, how much of each limit is used (mills, staff users, vehicles this month), its invoices, the
 * ways to pay with their QR codes, and "I've paid" to report a payment.
 */
class SubscriptionPage extends Page
{
    use PaysSubscription;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'My subscription';

    protected static ?string $title = 'My subscription';

    protected static ?string $slug = 'subscription';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.subscription';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof LoginAccount && $user->isOwner() && ! $user->isPlatformAdmin() && $user->customerId() !== null;
    }

    /**
     * @return array{customer: Customer, plan: Plan|null, usage: array{mills: int, users: int, vehicles: int},
     *               limits: list<array{label: string, used: int, max: int|null}>, modules: array<string, bool>}
     */
    public function subscription(): array
    {
        $customer = $this->customer();
        abort_if($customer === null, 403);
        $limits = app(PlanLimits::class);
        $plan = $limits->planOf($customer->id);
        $usage = $limits->usage($customer->id);
        $included = $limits->modules($customer->id);

        return [
            'customer' => $customer,
            'plan' => $plan,
            'usage' => $usage,
            'limits' => [
                ['label' => 'Mills', 'used' => $usage['mills'], 'max' => $plan?->max_mills],
                ['label' => 'Staff users', 'used' => $usage['users'], 'max' => $plan?->max_users],
                ['label' => 'Vehicles this month', 'used' => $usage['vehicles'], 'max' => $plan?->max_vehicles_month],
            ],
            'modules' => collect(Modules::labels())
                ->mapWithKeys(fn (string $label, string $module) => [$label => $included === null || in_array($module, $included, true)])->all(),
        ];
    }

    /** @return array<string, string> label => value, the filled ones only (the .env bank details, when no payment method is set up) */
    public static function bankDetails(): array
    {
        $b = (array) config('ricemill.saas.billing');

        return array_filter([
            'Account title' => (string) ($b['bank_title'] ?? ''),
            'Bank' => (string) ($b['bank_name'] ?? ''),
            'Account number' => (string) ($b['bank_account'] ?? ''),
            'IBAN' => (string) ($b['bank_iban'] ?? ''),
        ], fn (string $v) => $v !== '');
    }

    /**
     * The notice at the top of every page: read-only for everyone in the company; an invoice due or the
     * trial ending, for its Owners.
     *
     * @return array{title: string, body: string, color: string, link: string|null}|null
     */
    public static function banner(LoginAccount $user): ?array
    {
        if ($user->isPlatformAdmin() || $user->customerId() === null) {
            return null;
        }
        $owner = $user->isOwner();
        $link = $owner ? static::getUrl() : null;
        if (app(CustomerAccess::class)->blocked($user)) {
            $contact = trim((string) config('ricemill.saas.contact'));

            return ['title' => 'Your account is blocked.', 'color' => 'danger', 'link' => $link,
                'body' => 'All of your company\'s data is hidden until it is unblocked.'.($contact === '' ? ' Please contact us.' : " Please contact us: {$contact}.")];
        }
        if (app(CustomerAccess::class)->readOnly($user)) {
            return ['title' => 'Read-only: payment overdue.', 'color' => 'danger', 'link' => $link,
                'body' => $owner ? 'You can see everything but not record or change anything until the overdue invoice is paid.'
                    : 'Records cannot be added or changed until the company\'s overdue invoice is paid. Please tell the owner.'];
        }
        if (! $owner) {
            return null;
        }
        $standing = app(Billing::class)->standing((string) $user->customerId());
        $first = $standing['overdue']->first() ?? $standing['open']->first(fn (SaasInvoice $i) => $i->due_at->lte(now()->addDays(3)));
        if ($first instanceof SaasInvoice && $first->reported_at === null) {
            return ['title' => $first->isOverdue() ? "Invoice {$first->number} is overdue." : "Invoice {$first->number} is due on {$first->due_at->timezone('Asia/Karachi')->format('d M Y')}.",
                'body' => Units::paiseToRupees($first->amount_paise).'. Pay with one of the ways shown and report it.', 'color' => $first->isOverdue() ? 'danger' : 'warning', 'link' => $link];
        }
        $customer = Customer::query()->find($user->customerId());
        $days = $customer?->trialDaysLeft();
        if ($days !== null && $days <= 3) {
            return ['title' => $days === 0 ? 'Your free trial ends today.' : "Your free trial ends in {$days} ".($days === 1 ? 'day.' : 'days.'),
                'body' => 'Choose how to pay on My subscription to keep working without a break.', 'color' => 'info', 'link' => $link];
        }

        return null;
    }
}

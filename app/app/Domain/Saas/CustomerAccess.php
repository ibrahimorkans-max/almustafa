<?php

declare(strict_types=1);

namespace App\Domain\Saas;

use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\LoginAccount;

/**
 * Whether a customer's people may use the system.
 *  - Account closed (cancelled): no sign-in at all.
 *  - Free trial over, or access paused (suspended): they sign in, but see only the payment page (its open
 *    invoices, the ways to pay with their QR codes, "I've paid"); every other screen, button and the app
 *    are closed until it is paid.
 *  - Payment overdue past the grace days: read-only.
 * Checked at sign-in, on every web request and on every API call. The platform's own accounts never.
 */
final class CustomerAccess
{
    /** @var array<string, Customer|null> */
    private array $customers = [];

    public function __construct(private readonly TenantContext $tenant) {}

    /** The app and the API: closed while anything stops normal work. */
    public function blocks(LoginAccount $account): bool
    {
        return $this->reason($account) !== null;
    }

    /** trial_ended | suspended | cancelled, or null when the account may work normally. */
    public function reason(LoginAccount $account): ?string
    {
        if ($account->isPlatformAdmin()) {
            return null;
        }
        $customer = $this->customer($account->customerId());
        if ($customer === null) {
            return null;
        }

        return match (true) {
            $customer->isShutOut() => Customer::CANCELLED,
            $customer->trialEnded() => 'trial_ended',
            $customer->status === Customer::SUSPENDED => Customer::SUSPENDED,
            default => null,
        };
    }

    /**
     * Whether a mill's company is stopped (closed, paused, or its free trial over): nothing of it is shown to
     * anyone, not through shared document links, the public verify page or messages to its parties.
     */
    public function millBlocked(?string $entityId): bool
    {
        if ($entityId === null) {
            return false;
        }
        $customerId = $this->tenant->runAsSystem(fn () => Entity::query()->whereKey($entityId)->value('customer_id'));
        $customer = $this->customer(is_string($customerId) ? $customerId : null);

        return $customer !== null && ($customer->isShutOut() || $customer->mustPay());
    }

    /** No sign-in at all (the account is closed). */
    public function signInBlocked(LoginAccount $account): bool
    {
        return $this->reason($account) === Customer::CANCELLED;
    }

    /** trial_ended: signed in, but only the payment page opens. */
    public function paymentDue(LoginAccount $account): ?string
    {
        return $this->reason($account) === 'trial_ended' ? 'trial_ended' : null;
    }

    /**
     * Blocked by the product owner: its people still sign in, but every right is gone and every screen with
     * data is closed (BlockedGate); they see a notice, their own sign-in settings, and (Owners) My subscription.
     */
    public function blocked(LoginAccount $account): bool
    {
        return $this->reason($account) === Customer::SUSPENDED;
    }

    /** Trial over or blocked: no rights at all. */
    public function noRights(LoginAccount $account): bool
    {
        return in_array($this->reason($account), ['trial_ended', Customer::SUSPENDED], true);
    }

    /** Payment overdue past the grace days: its people can look at everything and change nothing. */
    public function readOnly(LoginAccount $account): bool
    {
        return ! $account->isPlatformAdmin() && $this->customer($account->customerId())?->status === Customer::PAST_DUE;
    }

    /**
     * The rights that only show things (… .view, … .view_own, … .view_all, tax reports); every right that
     * records or changes something is dropped, so its buttons, screens and API calls go away together.
     *
     * @param  list<string>  $codes
     * @return list<string>
     */
    public static function viewOnly(array $codes): array
    {
        return array_values(array_filter($codes, fn (string $code) => str_ends_with($code, '.view')
            || str_ends_with($code, '.view_own') || str_ends_with($code, '.view_all') || $code === 'report.tax'));
    }

    public function message(?string $reason): string
    {
        $contact = trim((string) config('ricemill.saas.contact'));
        $contact = $contact === '' ? 'Please contact us to continue.' : "Please contact us to continue: {$contact}.";

        return match ($reason) {
            'trial_ended' => "Your free trial has ended. Sign in to pay and continue. {$contact}",
            'cancelled' => "Your company's account is closed. {$contact}",
            default => "Your company's account is blocked. {$contact}",
        };
    }

    public function forget(): void
    {
        $this->customers = [];
    }

    private function customer(?string $id): ?Customer
    {
        if ($id === null) {
            return null;
        }

        return $this->customers[$id] ??= $this->tenant->runAsSystem(fn () => Customer::query()->find($id));
    }
}

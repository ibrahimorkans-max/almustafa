<?php

declare(strict_types=1);

namespace App\Domain\Saas;

use App\Domain\Tenancy\TenantContext;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\LoginAccount;
use App\Models\Plan;
use App\Models\Role;
use App\Models\SaasInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The platform console's figures: customers by state, the monthly recurring income, money invoiced,
 * collected, outstanding and overdue, what needs attention (payments to check, trials ending, customers
 * near a limit) and one customer's full picture. Read as the system: it looks across every customer.
 */
final class PlatformStats
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PlanLimits $limits,
    ) {}

    /**
     * @return array{customers: array<string, int>, mrr_paise: int, invoiced_paise: int, collected_paise: int,
     *               outstanding_paise: int, overdue_paise: int, reported: int, new_this_month: int}
     */
    public function summary(): array
    {
        return $this->tenant->runAsSystem(function (): array {
            $monthStart = CarbonImmutable::now('Asia/Karachi')->startOfMonth()->utc();
            $customers = Customer::query()->get();
            $counts = ['trial' => 0, 'trial_ended' => 0, 'active' => 0, 'lifetime' => 0, 'past_due' => 0, 'suspended' => 0, 'cancelled' => 0];
            foreach ($customers as $c) {
                $state = match (true) {
                    $c->trialEnded() => 'trial_ended',
                    $c->isLifetime() && $c->status === Customer::ACTIVE => 'lifetime',
                    default => $c->status,
                };
                $counts[$state] = ($counts[$state] ?? 0) + 1;
            }
            $prices = Plan::query()->pluck('price_paise_monthly', 'id');
            $mrr = $customers->whereIn('status', [Customer::ACTIVE, Customer::PAST_DUE])->where('lifetime', false)
                ->sum(fn (Customer $c) => (int) ($c->plan_id !== null ? ($prices[$c->plan_id] ?? 0) : 0));
            $open = SaasInvoice::query()->where('status', SaasInvoice::OPEN);

            return [
                'customers' => $counts,
                'mrr_paise' => (int) $mrr,
                'invoiced_paise' => (int) SaasInvoice::query()->where('status', '!=', SaasInvoice::VOID)->where('issued_at', '>=', $monthStart)->sum('amount_paise'),
                'collected_paise' => (int) SaasInvoice::query()->where('status', SaasInvoice::PAID)->where('paid_at', '>=', $monthStart)->sum('paid_amount_paise'),
                'outstanding_paise' => (int) (clone $open)->sum('amount_paise'),
                'overdue_paise' => (int) (clone $open)->where('due_at', '<', now())->sum('amount_paise'),
                'reported' => (clone $open)->whereNotNull('reported_at')->count(),
                'new_this_month' => $customers->filter(fn (Customer $c) => $c->created_at->gte($monthStart))->count(),
            ];
        });
    }

    /** @return list<array{month: string, invoiced: int, collected: int}> the last six months, oldest first */
    public function income(int $months = 6): array
    {
        return $this->tenant->runAsSystem(function () use ($months): array {
            $rows = [];
            for ($i = $months - 1; $i >= 0; $i--) {
                $start = CarbonImmutable::now('Asia/Karachi')->startOfMonth()->subMonths($i);
                $from = $start->utc();
                $to = $start->endOfMonth()->utc();
                $rows[] = [
                    'month' => $start->format('M Y'),
                    'invoiced' => (int) SaasInvoice::query()->where('status', '!=', SaasInvoice::VOID)->whereBetween('issued_at', [$from, $to])->sum('amount_paise'),
                    'collected' => (int) SaasInvoice::query()->where('status', SaasInvoice::PAID)->whereBetween('paid_at', [$from, $to])->sum('paid_amount_paise'),
                ];
            }

            return $rows;
        });
    }

    /** @return list<array{month: string, signup: int, platform: int}> new customers a month (public sign-up and added by the platform), oldest first */
    public function signups(int $months = 6): array
    {
        return $this->tenant->runAsSystem(function () use ($months): array {
            $rows = [];
            for ($i = $months - 1; $i >= 0; $i--) {
                $start = CarbonImmutable::now('Asia/Karachi')->startOfMonth()->subMonths($i);
                $in = Customer::query()->whereBetween('created_at', [$start->utc(), $start->endOfMonth()->utc()]);
                $rows[] = ['month' => $start->format('M Y'), 'signup' => (clone $in)->where('source', 'signup')->count(),
                    'platform' => (clone $in)->where(fn ($q) => $q->where('source', '!=', 'signup')->orWhereNull('source'))->count()];
            }

            return $rows;
        });
    }

    /** @return array<string, int> plan name => customers on it (lifetime customers apart; closed ones left out) */
    public function planMix(): array
    {
        return $this->tenant->runAsSystem(function (): array {
            $names = Plan::query()->pluck('name', 'id');
            $mix = [];
            foreach (Customer::query()->where('status', '!=', Customer::CANCELLED)->get() as $c) {
                $label = $c->isLifetime() ? 'Lifetime' : ($c->plan_id !== null ? (string) ($names[$c->plan_id] ?? 'Other') : 'No plan');
                $mix[$label] = ($mix[$label] ?? 0) + 1;
            }
            arsort($mix);

            return $mix;
        });
    }

    /** @return array{paid: int, open: int, overdue: int, void: int} invoices of the last twelve months by state (amounts in paise) */
    public function invoiceStates(): array
    {
        return $this->tenant->runAsSystem(function (): array {
            $since = CarbonImmutable::now('Asia/Karachi')->startOfMonth()->subMonths(11)->utc();
            $all = SaasInvoice::query()->where('issued_at', '>=', $since)->get(['status', 'amount_paise', 'due_at']);

            return [
                'paid' => (int) $all->where('status', SaasInvoice::PAID)->sum('amount_paise'),
                'open' => (int) $all->where('status', SaasInvoice::OPEN)->filter(fn ($i) => ! $i->due_at->isPast())->sum('amount_paise'),
                'overdue' => (int) $all->where('status', SaasInvoice::OPEN)->filter(fn ($i) => $i->due_at->isPast())->sum('amount_paise'),
                'void' => (int) $all->where('status', SaasInvoice::VOID)->sum('amount_paise'),
            ];
        });
    }

    /** @return Collection<int, Customer> trials ending within the days given (soonest first), ended ones included */
    public function trialsEnding(int $days = 7): Collection
    {
        return $this->tenant->runAsSystem(fn () => Customer::query()->where('status', Customer::TRIAL)
            ->whereNotNull('trial_ends_at')->where('trial_ends_at', '<=', now()->addDays($days))->orderBy('trial_ends_at')->limit(20)->get());
    }

    /** @return Collection<int, SaasInvoice> open invoices past their due date, oldest first */
    public function overdue(): Collection
    {
        return $this->tenant->runAsSystem(fn () => SaasInvoice::query()->with('customer')->where('status', SaasInvoice::OPEN)
            ->where('due_at', '<', now())->orderBy('due_at')->limit(20)->get());
    }

    /** @return Collection<int, SaasInvoice> payments customers reported that still need checking */
    public function reported(): Collection
    {
        return $this->tenant->runAsSystem(fn () => SaasInvoice::query()->with('customer')->where('status', SaasInvoice::OPEN)
            ->whereNotNull('reported_at')->orderBy('reported_at')->limit(20)->get());
    }

    /**
     * Customers using 80 % or more of a limit of their plan (a reason to offer a bigger one).
     *
     * @return list<array{customer: Customer, label: string, used: int, max: int}>
     */
    public function nearLimits(int $percent = 80): array
    {
        return $this->tenant->runAsSystem(function () use ($percent): array {
            $rows = [];
            $customers = Customer::query()->whereIn('status', [Customer::TRIAL, Customer::ACTIVE, Customer::PAST_DUE])->whereNotNull('plan_id')->get();
            foreach ($customers as $customer) {
                $plan = $this->limits->planOf($customer->id);
                if ($plan === null) {
                    continue;
                }
                $usage = $this->limits->usage($customer->id);
                foreach (['mills' => ['Mills', $plan->max_mills], 'users' => ['Staff users', $plan->max_users], 'vehicles' => ['Vehicles this month', $plan->max_vehicles_month]] as $key => [$label, $max]) {
                    if ($max !== null && $max > 0 && $max * $percent <= $usage[$key] * 100) {
                        $rows[] = ['customer' => $customer, 'label' => $label, 'used' => $usage[$key], 'max' => $max];
                    }
                }
            }

            return $rows;
        });
    }

    /** @return Collection<int, Customer> the newest customers */
    public function newest(int $count = 8): Collection
    {
        return $this->tenant->runAsSystem(fn () => Customer::query()->with('plan')->orderByDesc('created_at')->limit($count)->get());
    }

    /**
     * One customer's full picture for its console page.
     *
     * @return array{plan: Plan|null, usage: array{mills: int, users: int, vehicles: int}, mills: Collection<int, Entity>,
     *               owners: Collection<int, LoginAccount>, invoices: Collection<int, SaasInvoice>, history: Collection<int, AuditLog>,
     *               billed_paise: int, paid_paise: int, open_paise: int}
     */
    public function customer(Customer $customer): array
    {
        return $this->tenant->runAsSystem(function () use ($customer): array {
            $millIds = Entity::query()->where('customer_id', $customer->id)->pluck('id');
            $invoices = SaasInvoice::query()->where('customer_id', $customer->id)->orderByDesc('issued_at')->get();

            return [
                'plan' => $this->limits->planOf($customer->id),
                'usage' => $this->limits->usage($customer->id),
                'mills' => Entity::query()->whereIn('id', $millIds)->orderBy('code')->get()
                    ->each(fn (Entity $e) => $e->setAttribute('staff_count', LoginAccount::query()->where('entity_id', $e->id)->where('status', LoginAccount::STATUS_ACTIVE)->count())),
                'owners' => LoginAccount::query()->with('person')->whereIn('entity_id', $millIds)
                    ->whereIn('role_id', Role::query()->where('is_super_admin', true)->select('id'))->orderBy('username')->get(),
                'invoices' => $invoices,
                // What happened to the customer and its invoices: opened, plan or state changed, paused, paid, cancelled.
                'history' => AuditLog::query()->whereIn('subject_id', $invoices->pluck('id')->push($customer->id))
                    ->orderByDesc('occurred_at')->limit(30)->get(),
                'billed_paise' => (int) $invoices->where('status', '!=', SaasInvoice::VOID)->sum('amount_paise'),
                'paid_paise' => (int) $invoices->where('status', SaasInvoice::PAID)->sum('paid_amount_paise'),
                'open_paise' => (int) $invoices->where('status', SaasInvoice::OPEN)->sum('amount_paise'),
            ];
        });
    }
}

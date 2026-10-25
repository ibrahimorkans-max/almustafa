<?php

declare(strict_types=1);

namespace App\Domain\Saas;

use App\Domain\Access\RoleTemplates;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\Gate\GateEntry;
use App\Models\LoginAccount;
use App\Models\Plan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What a customer's plan allows and how much of it is used: mills, staff users (party logins and
 * platform admins are not counted), vehicles this month (Pakistan time) and modules. A customer with no
 * plan, and a limit left empty, are unlimited. Each limit is checked where the thing is made: a new mill,
 * a new login, a vehicle arriving at the gate.
 */
final class PlanLimits
{
    /** @var array<string, Plan|null> */
    private array $plans = [];

    public function __construct(private readonly TenantContext $tenant) {}

    public function planOf(?string $customerId): ?Plan
    {
        if ($customerId === null) {
            return null;
        }
        if (! array_key_exists($customerId, $this->plans)) {
            $this->plans[$customerId] = $this->tenant->runAsSystem(function () use ($customerId): ?Plan {
                $planId = Customer::query()->whereKey($customerId)->value('plan_id');

                return $planId === null ? null : Plan::query()->find($planId);
            });
        }

        return $this->plans[$customerId];
    }

    /** @return list<string>|null the modules included (null: all) */
    public function modules(?string $customerId): ?array
    {
        $plan = $this->planOf($customerId);

        return $plan === null ? null : array_values((array) $plan->modules);
    }

    public function forget(?string $customerId = null): void
    {
        $customerId === null ? $this->plans = [] : $this->plans = array_diff_key($this->plans, [$customerId => true]);
    }

    /** @return array{mills: int, users: int, vehicles: int} */
    public function usage(string $customerId): array
    {
        return $this->tenant->runAsSystem(function () use ($customerId): array {
            $mills = Entity::query()->where('customer_id', $customerId)->pluck('id');
            $monthStart = CarbonImmutable::now('Asia/Karachi')->startOfMonth()->utc();

            return [
                'mills' => $mills->count(),
                'users' => LoginAccount::query()->whereIn('entity_id', $mills)->where('status', LoginAccount::STATUS_ACTIVE)
                    ->where('is_platform_admin', false)
                    ->whereHas('role', fn ($q) => $q->where('code', '!=', RoleTemplates::PARTY)->orWhereNull('code'))
                    ->count(),
                'vehicles' => GateEntry::query()->whereIn('entity_id', $mills)->where('arrived_at', '>=', $monthStart)->count(),
            ];
        });
    }

    /**
     * Two creations at the same moment must not both pass a limit with one place left: the customer's row
     * is locked until the caller's transaction ends, so the second one counts after the first is saved.
     * Every caller checks inside the transaction that creates the thing (mill, login, vehicle).
     */
    private function lockCustomer(?string $customerId): void
    {
        if ($customerId !== null && DB::transactionLevel() > 0) {
            $this->tenant->runAsSystem(fn () => Customer::query()->whereKey($customerId)->lockForUpdate()->first());
        }
    }

    public function assertCanAddMill(?string $customerId): void
    {
        $plan = $this->planOf($customerId);
        if ($plan?->max_mills !== null) {
            $this->lockCustomer($customerId);
        }
        if ($plan?->max_mills !== null && $this->usage((string) $customerId)['mills'] >= $plan->max_mills) {
            throw new BusinessRuleViolation("Your {$plan->name} plan allows {$plan->max_mills} mill(s). Move to a bigger plan to add another.");
        }
    }

    public function assertCanAddUser(?string $customerId): void
    {
        $plan = $this->planOf($customerId);
        if ($plan?->max_users !== null) {
            $this->lockCustomer($customerId);
        }
        if ($plan?->max_users !== null && $this->usage((string) $customerId)['users'] >= $plan->max_users) {
            throw new BusinessRuleViolation("Your {$plan->name} plan allows {$plan->max_users} users. Move to a bigger plan, or switch off a user you no longer need.");
        }
    }

    public function assertCanAddVehicle(?string $entityId): void
    {
        $customerId = $entityId === null ? null : $this->tenant->runAsSystem(fn () => Entity::query()->whereKey($entityId)->value('customer_id'));
        $plan = $this->planOf($customerId);
        if ($plan?->max_vehicles_month !== null) {
            $this->lockCustomer($customerId);
        }
        if ($plan?->max_vehicles_month !== null && $this->usage((string) $customerId)['vehicles'] >= $plan->max_vehicles_month) {
            throw new BusinessRuleViolation("Your {$plan->name} plan allows {$plan->max_vehicles_month} vehicles a month, and this month's are used. Move to a bigger plan to register more.");
        }
    }
}

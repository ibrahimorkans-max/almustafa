<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Tenancy\TenantContext;
use App\Models\AuthSession;
use App\Models\Entity;
use App\Models\LoginAccount;

/**
 * Establishes tenant + actor context for an authenticated account.
 * A customer's Owner works in its home mill, a chosen one, or across all mills of its own customer —
 * never another customer's (SaaS). Only the platform admin (the provider) bypasses isolation.
 */
final class AuthContextBinder
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ActorContext $actor,
    ) {}

    /** Session value meaning "Owner works across all entities". */
    public const ALL_ENTITIES = 'all';

    public function bind(LoginAccount $account, ?AuthSession $session = null, ?string $deviceId = null, ?string $workingEntityId = null): void
    {
        $working = match ($workingEntityId) {
            null => $account->entity_id,
            self::ALL_ENTITIES => null,
            default => $workingEntityId,
        };
        if ($account->isPlatformAdmin()) {
            $this->tenant->enterSuperAdmin($working);
        } elseif ($account->isOwner() && ($customer = $account->customerId()) !== null) {
            // A working mill of another customer is never honoured: back to the home mill.
            if ($working !== null && $this->tenant->runAsSystem(fn () => Entity::query()->whereKey($working)->value('customer_id')) !== $customer) {
                $working = $account->entity_id;
            }
            $this->tenant->enterCustomer($customer, $working);
        } elseif ($account->isOwner()) {
            // An Owner of a mill with no customer yet (before the SaaS step): its own mill only.
            $this->tenant->enterEntity($account->entity_id);
        } else {
            $this->tenant->enterEntity($account->entity_id);
        }

        $this->actor->set($account, $session, $deviceId);
    }
}

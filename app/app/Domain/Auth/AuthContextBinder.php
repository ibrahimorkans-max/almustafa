<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Tenancy\TenantContext;
use App\Models\AuthSession;
use App\Models\LoginAccount;

/**
 * Establishes tenant + actor context for an authenticated account.
 * Super Admin gets isolation bypass with its home entity (or a chosen working entity).
 */
final class AuthContextBinder
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ActorContext $actor,
    ) {}

    /** Session value meaning "Super Admin works across all entities". */
    public const ALL_ENTITIES = 'all';

    public function bind(LoginAccount $account, ?AuthSession $session = null, ?string $deviceId = null, ?string $workingEntityId = null): void
    {
        if ($account->isSuperAdmin()) {
            $this->tenant->enterSuperAdmin(match ($workingEntityId) {
                null => $account->entity_id,
                self::ALL_ENTITIES => null,
                default => $workingEntityId,
            });
        } else {
            $this->tenant->enterEntity($account->entity_id);
        }

        $this->actor->set($account, $session, $deviceId);
    }
}

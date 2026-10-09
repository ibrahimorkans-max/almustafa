<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Security\Lockdown;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\LoginAccount;
use Illuminate\Http\Request;

/**
 * Resolves `Authorization: Bearer rma_…` + `X-Device-Id` into a login account
 * (registered with Auth::viaRequest as the "ricemill-token" driver).
 *
 * Optional `X-Entity-Id`: Super Admin picks the working entity; for everyone else
 * it must equal their own entity.
 */
final class ApiTokenGuard
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly AuthContextBinder $binder,
        private readonly SessionService $sessions,
        private readonly IpAllowlistPolicy $ipAllowlist,
        private readonly TenantContext $tenant,
    ) {}

    public function __invoke(Request $request): ?LoginAccount
    {
        $plain = $request->bearerToken();
        if ($plain === null || ! str_starts_with($plain, 'rma_')) {
            return null;
        }

        $resolved = $this->tokens->authenticate($plain, $request->header('X-Device-Id'));
        if ($resolved === null) {
            $request->attributes->set('auth_failure', $this->tokens->failureReason($plain));

            return null;
        }
        [$account, $session, $device] = $resolved;
        if (app(Lockdown::class)->blocks($account)) {
            $request->attributes->set('auth_failure', 'system_locked');

            return null;
        }

        $working = $request->header('X-Entity-Id');
        if ($working !== null) {
            if ($account->isSuperAdmin()) {
                $exists = $this->tenant->runAsSystem(fn () => Entity::query()->whereKey($working)->exists());
                if (! $exists) {
                    return null;
                }
            } elseif ($working !== $account->entity_id) {
                return null;
            }
        }

        $this->binder->bind($account, $session, $device->id, $account->isSuperAdmin() ? $working : null);

        if (! $this->ipAllowlist->allows($account, $request->ip())) {
            return null;
        }

        $request->attributes->set('auth_session', $session);
        $request->attributes->set('auth_device', $device);
        $this->sessions->touch($session);

        return $account;
    }
}

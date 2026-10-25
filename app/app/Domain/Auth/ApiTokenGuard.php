<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Auth\Exceptions\AuthenticationFailed;
use App\Domain\Saas\CustomerAccess;
use App\Domain\Security\Lockdown;
use App\Domain\Security\SignInArea;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\LoginAccount;
use Illuminate\Http\Request;

/**
 * Resolves `Authorization: Bearer rma_…` + `X-Device-Id` into a login account
 * (registered with Auth::viaRequest as the "ricemill-token" driver).
 *
 * Optional `X-Entity-Id`: Owner picks the working entity; for everyone else
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
        $shutOut = app(CustomerAccess::class)->reason($account);
        if ($shutOut !== null) {
            $request->attributes->set('auth_failure', AuthenticationFailed::SUBSCRIPTION_ENDED);
            $request->attributes->set('auth_failure_detail', $shutOut);

            return null;
        }
        // "Only inside the mill": every call carries the phone's position (X-Location: lat,lng[,accuracy]);
        // the session's last one must be recent and inside the mill. Gate agents (fixed computers) are not held.
        $area = app(SignInArea::class);
        if ($device->platform !== 'agent' && $area->enforced($account)) {
            $parts = array_map('trim', explode(',', (string) $request->header('X-Location')));
            if (count($parts) >= 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                $this->tenant->runAsSystem(fn () => $session->forceFill(['latitude' => round((float) $parts[0], 6), 'longitude' => round((float) $parts[1], 6),
                    'location_accuracy_m' => is_numeric($parts[2] ?? null) ? (int) $parts[2] : null, 'location_at' => now(), 'location_source' => 'app'])->save());
            }
            $fresh = $session->location_at !== null && $session->location_at->gt(now()->subMinutes(SignInArea::STALE_MINUTES));
            if (! $fresh || ! $area->inside($area->mills($account), (float) $session->latitude, (float) $session->longitude, $session->location_accuracy_m)) {
                $request->attributes->set('auth_failure', AuthenticationFailed::OUTSIDE_AREA);
                $request->attributes->set('auth_failure_detail', $fresh ? SignInArea::OUTSIDE : SignInArea::LOCATION_NEEDED);

                return null;
            }
        }

        $working = $request->header('X-Entity-Id');
        if ($working !== null) {
            if ($account->isOwner()) {
                // A mill of the Owner's own customer (the platform admin: any mill).
                $customer = $this->tenant->runAsSystem(fn () => Entity::query()->whereKey($working)->value('customer_id'));
                if ($customer === null || (! $account->isPlatformAdmin() && $customer !== $account->customerId())) {
                    return null;
                }
            } elseif ($working !== $account->entity_id) {
                return null;
            }
        }

        $this->binder->bind($account, $session, $device->id, $account->isOwner() ? $working : null);

        if (! $this->ipAllowlist->allows($account, $request->ip())) {
            return null;
        }

        $request->attributes->set('auth_session', $session);
        $request->attributes->set('auth_device', $device);
        $this->sessions->touch($session);

        return $account;
    }
}

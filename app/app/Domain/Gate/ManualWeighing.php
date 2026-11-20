<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Domain\Access\RoleTemplates;
use App\Domain\Settings\SettingsService;
use App\Domain\Tenancy\TenantContext;
use App\Models\LoginAccount;

/**
 * The Owner's choice of who may type a vehicle's weight by hand (Gate → Manual weighing), for a mill:
 *  - on / off            manual entries at all;
 *  - both weights        whether both weights of a vehicle may be typed (the weighbridge is down), or only one of
 *                        them while the other comes from this weighbridge (the default);
 *  - the people ticked   only they may type weights; nobody ticked (the default): everyone on the mill's staff.
 * A reason is optional. Every typed weight is flagged "typed by hand" (and "no reason" without one) and goes to the
 * weight checks.
 */
final class ManualWeighing
{
    public const ENABLED = 'gate.manual.enabled';

    public const BOTH = 'gate.manual.both';

    public const USERS = 'gate.manual.users';

    public static function enabled(?string $entityId = null): bool
    {
        return self::get(self::ENABLED, $entityId) !== 'off';
    }

    public static function bothAllowed(?string $entityId = null): bool
    {
        return self::enabled($entityId) && self::get(self::BOTH, $entityId) === 'on';
    }

    /** @return list<string> the login accounts ticked (empty: by role) */
    public static function users(?string $entityId = null): array
    {
        $ids = json_decode((string) self::get(self::USERS, $entityId), true);

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }

    /** Whether this person may type a weight by hand now. */
    public static function allowed(?LoginAccount $user): bool
    {
        if (! $user instanceof LoginAccount) {
            return false;
        }
        $entityId = app(TenantContext::class)->entityId();
        if (! self::enabled($entityId)) {
            return false;
        }
        $ticked = self::users($entityId);

        return $ticked === [] ? self::staff($user) : in_array($user->id, $ticked, true);
    }

    /** Everyone working at the mill: not a party's or broker's own sign-in, nor a machine (gate agent). */
    private static function staff(LoginAccount $user): bool
    {
        return ! $user->is_machine && $user->status === LoginAccount::STATUS_ACTIVE
            && ! in_array($user->role?->code, [RoleTemplates::PARTY, RoleTemplates::BROKER], true);
    }

    /**
     * Saves the Owner's choice.
     *
     * @param  list<string>  $users
     */
    public static function save(string $entityId, bool $enabled, bool $both, array $users): void
    {
        $settings = app(SettingsService::class);
        $settings->put(self::ENABLED, $enabled ? 'on' : 'off', $entityId);
        $settings->put(self::BOTH, $both ? 'on' : 'off', $entityId);
        $settings->put(self::USERS, json_encode(array_values(array_unique($users))), $entityId);
    }

    private static function get(string $key, ?string $entityId): mixed
    {
        $entityId = filled($entityId) ? $entityId : app(TenantContext::class)->entityId();

        // No single mill ("All firms"): the all-mills value.
        return app(SettingsService::class)->get($key, filled($entityId) ? $entityId : null);
    }
}

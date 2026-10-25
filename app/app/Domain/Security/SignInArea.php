<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Domain\Hr\Attendance\Geo;
use App\Domain\Settings\SettingsService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Entity;
use App\Models\Hr\Site;
use App\Models\LoginAccount;
use Illuminate\Support\Collection;

/**
 * "Only inside the mill": when a mill switches it on (Security → Sign-in rules), nobody of that mill signs
 * in, or stays signed in, unless the device's own position (GPS / browser location) is inside one of the
 * mill's sites (HR → Sites: a centre and a radius). No position, no sign-in. An Owner is held to it when
 * any of its company's mills has it on, and may be at any of those mills. The platform's own accounts
 * and the registered gate agents (computers fixed at the weighbridge, no GPS) are not.
 */
final class SignInArea
{
    public const LOCATION_NEEDED = 'location_needed';

    public const OUTSIDE = 'outside_area';

    /** How old a signed-in session's last position may be before it is ended (the page reports every few minutes). */
    public const STALE_MINUTES = 20;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SettingsService $settings,
    ) {}

    /** @return list<string> the mills whose area holds this account (empty: no limit) */
    public function mills(LoginAccount $account): array
    {
        if ($account->isPlatformAdmin()) {
            return [];
        }
        $candidates = $account->isOwner() && $account->customerId() !== null
            ? $this->tenant->runAsSystem(fn () => Entity::query()->where('customer_id', $account->customerId())->pluck('id')->all())
            : [$account->entity_id];

        return array_values(array_filter($candidates, fn (string $id) => $this->isOn($id)));
    }

    public function isOn(string $entityId): bool
    {
        return $this->settings->get(SettingsService::SIGN_IN_AREA, $entityId) === 'on';
    }

    public function enforced(LoginAccount $account): bool
    {
        return $this->mills($account) !== [];
    }

    /**
     * @param  list<string>  $entityIds
     * @return Collection<int, Site> the active sites of the given mills that have a position
     */
    public function sites(array $entityIds): Collection
    {
        return $this->tenant->runAsSystem(fn () => Site::query()->whereIn('entity_id', $entityIds)->where('status', 'active')
            ->whereNotNull('latitude')->whereNotNull('longitude')->get());
    }

    /** null: may sign in; else location_needed | outside_area. */
    public function check(LoginAccount $account, mixed $latitude, mixed $longitude, mixed $accuracy = null): ?string
    {
        $mills = $this->mills($account);
        if ($mills === []) {
            return null;
        }
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return self::LOCATION_NEEDED;
        }

        return $this->inside($mills, (float) $latitude, (float) $longitude, is_numeric($accuracy) ? (int) $accuracy : null) ? null : self::OUTSIDE;
    }

    /** @param  list<string>  $mills */
    public function inside(array $mills, float $lat, float $lng, ?int $accuracy): bool
    {
        // A little allowance for the device's own uncertainty, never more than the attendance cap.
        $slack = min(max(0, (int) $accuracy), (int) config('ricemill.attendance.max_accuracy_m', 100));
        foreach ($this->sites($mills) as $site) {
            if (Geo::distanceMeters($lat, $lng, (float) $site->latitude, (float) $site->longitude) <= $site->radius_m + $slack) {
                return true;
            }
        }

        return false;
    }

    public static function message(string $reason): string
    {
        return $reason === self::LOCATION_NEEDED
            ? 'Sign-in needs your location: this mill allows it only inside its premises. Allow location for this site in your browser or phone, then try again.'
            : 'You are outside the mill. Sign-in is allowed only inside the mill\'s premises.';
    }
}

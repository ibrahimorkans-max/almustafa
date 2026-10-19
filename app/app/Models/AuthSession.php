<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToEntity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One login (web or API) on one device. Revoking it ends the web session
 * and invalidates every API token issued under it.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $login_account_id
 * @property string|null $device_id
 * @property string $channel
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon|null $mfa_passed_at
 * @property Carbon $expires_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_by
 * @property string|null $revoked_reason
 * @property Carbon $created_at
 * @property string|null $browser
 * @property string|null $os
 * @property string|null $country
 * @property string|null $region
 * @property string|null $city
 * @property float|null $latitude
 * @property float|null $longitude
 * @property int|null $location_accuracy_m
 * @property string|null $location_source ip | browser | local
 * @property string|null $sign_in_method password | passkey
 * @property string|null $area neighbourhood, village or road of the browser's position
 * @property string|null $address full address of the browser's position
 * @property string|null $country_name
 * @property string|null $ip_city
 * @property string|null $ip_region
 * @property string|null $ip_country
 * @property Carbon|null $location_at when the browser's position was last taken
 * @property-read LoginAccount $loginAccount
 * @property-read Device|null $device
 */
class AuthSession extends Model
{
    use BelongsToEntity;
    use HasUuids;

    public const CHANNEL_WEB = 'web';

    public const CHANNEL_API = 'api';

    protected $fillable = [
        'entity_id', 'login_account_id', 'device_id', 'channel', 'ip', 'user_agent', 'mfa_passed_at', 'expires_at', 'last_seen_at',
        'browser', 'os', 'country', 'region', 'city', 'latitude', 'longitude', 'location_accuracy_m', 'location_source', 'sign_in_method',
        'area', 'address', 'country_name', 'ip_city', 'ip_region', 'ip_country', 'location_at',
    ];

    protected function casts(): array
    {
        return [
            'mfa_passed_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'location_accuracy_m' => 'integer',
            'location_at' => 'datetime',
        ];
    }

    /** "Lodhran, Punjab, PK", "Mill network" or null when unknown. */
    public function placeLabel(): ?string
    {
        if ($this->location_source === 'local') {
            return 'Mill network (local address)';
        }
        $place = implode(', ', array_unique(array_filter([$this->area, $this->city, $this->region, $this->country_name ?? $this->country])));

        return $place !== '' ? $place : null;
    }

    /** "Lahore, Punjab, PK" from the IP address alone (kept apart from the browser's exact place). */
    public function ipPlaceLabel(): ?string
    {
        $place = implode(', ', array_filter([$this->ip_city, $this->ip_region, $this->ip_country]));

        return $place !== '' ? $place : null;
    }

    /** A map link to the browser's own position (street level), or the IP's rough one (city level). */
    public function mapUrl(): ?string
    {
        $zoom = $this->location_source === 'browser' ? 17 : 11;

        return $this->latitude !== null && $this->longitude !== null
            ? sprintf('https://www.openstreetmap.org/?mlat=%.6F&mlon=%.6F#map=%d/%.6F/%.6F', $this->latitude, $this->longitude, $zoom, $this->latitude, $this->longitude)
            : null;
    }

    /** @return BelongsTo<LoginAccount, $this> */
    public function loginAccount(): BelongsTo
    {
        return $this->belongsTo(LoginAccount::class);
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}

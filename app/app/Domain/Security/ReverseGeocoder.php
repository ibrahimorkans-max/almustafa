<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Domain\Hr\Attendance\Geo;
use App\Domain\Tenancy\TenantContext;
use App\Models\Hr\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Locale;
use Throwable;

/**
 * Turns a position (latitude, longitude) into a place people can read: area (neighbourhood,
 * village or road), city, region, country and the full address.
 *
 *  1. OpenStreetMap's Nominatim (or the mill's own Nominatim server, RICEMILL_GEOCODER_URL), at most
 *     one request a second as its rules ask, answers cached for 30 days per ~10 m square;
 *  2. without internet (mill server) or when it fails: the nearest of the mill's own sites (Hr\Site,
 *     e.g. "Main mill") within 3 km — "At Main mill (120 m)";
 *  3. otherwise nothing: the screen shows the coordinates and the IP's city.
 * RICEMILL_GEOCODER=off turns step 1 off.
 */
final class ReverseGeocoder
{
    private const NEAR_SITE_M = 3000;

    public function __construct(private readonly TenantContext $tenant) {}

    /** @return array{area: ?string, city: ?string, region: ?string, country: ?string, country_name: ?string, address: ?string, source: string}|null */
    public function lookup(float $latitude, float $longitude, ?string $entityId = null): ?array
    {
        $online = $this->online($latitude, $longitude);
        $site = $this->nearestSite($latitude, $longitude, $entityId);

        if ($online !== null) {
            if ($site !== null && $online['area'] === null) {
                $online['area'] = $site;
            }

            return $online;
        }

        return $site === null ? null
            : ['area' => $site, 'city' => null, 'region' => null, 'country' => null, 'country_name' => null, 'address' => null, 'source' => 'site'];
    }

    /** @return array{area: ?string, city: ?string, region: ?string, country: ?string, country_name: ?string, address: ?string, source: string}|null */
    private function online(float $latitude, float $longitude): ?array
    {
        if (config('ricemill.geocoder.driver') !== 'nominatim') {
            return null;
        }
        $key = sprintf('revgeo:%.4F,%.4F', $latitude, $longitude);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        // Nominatim allows one request a second per application.
        if (! Cache::add('revgeo:lock', 1, 1)) {
            usleep(1_100_000);
        }
        try {
            $response = Http::timeout((int) config('ricemill.geocoder.timeout', 4))
                ->withHeaders(['User-Agent' => (string) config('ricemill.geocoder.user_agent'), 'Accept-Language' => 'en'])
                ->get((string) config('ricemill.geocoder.url'), [
                    'format' => 'jsonv2', 'lat' => round($latitude, 6), 'lon' => round($longitude, 6), 'zoom' => 18, 'addressdetails' => 1,
                    'email' => config('ricemill.geocoder.email') ?: null,
                ]);
            if (! $response->successful() || ! is_array($response->json('address'))) {
                return null;
            }
            $place = self::fromNominatim((array) $response->json('address'), (string) $response->json('display_name', ''));
        } catch (Throwable) {
            return null;
        }
        Cache::put($key, $place, now()->addDays(30));

        return $place;
    }

    /**
     * @param  array<string, mixed>  $a  Nominatim "address" block
     * @return array{area: ?string, city: ?string, region: ?string, country: ?string, country_name: ?string, address: ?string, source: string}
     */
    public static function fromNominatim(array $a, string $displayName = ''): array
    {
        $first = function (array $keys) use ($a): ?string {
            foreach ($keys as $k) {
                if (isset($a[$k]) && is_string($a[$k]) && trim($a[$k]) !== '') {
                    return mb_substr(trim($a[$k]), 0, 100);
                }
            }

            return null;
        };
        $city = $first(['city', 'town', 'municipality', 'village', 'county', 'state_district']);
        $area = $first(['neighbourhood', 'suburb', 'quarter', 'residential', 'hamlet', 'city_block', 'village', 'industrial', 'commercial', 'road', 'city_district']);
        if ($area !== null && $area === $city) {
            $area = $first(['road', 'neighbourhood', 'hamlet']);
            $area = $area === $city ? null : $area;
        }
        $road = $first(['road']);
        if ($area !== null && $road !== null && $road !== $area) {
            $area = mb_substr($road.', '.$area, 0, 150);
        }
        $code = isset($a['country_code']) && is_string($a['country_code']) ? strtoupper(mb_substr($a['country_code'], 0, 2)) : null;

        return [
            'area' => $area,
            'city' => $city,
            'region' => $first(['state', 'province', 'region']),
            'country' => $code,
            'country_name' => $first(['country']) ?? self::countryName($code),
            'address' => $displayName !== '' ? mb_substr($displayName, 0, 255) : null,
            'source' => 'map',
        ];
    }

    public static function countryName(?string $code): ?string
    {
        if ($code === null || strlen($code) !== 2) {
            return null;
        }
        if (class_exists(Locale::class)) {
            $name = Locale::getDisplayRegion('-'.$code, 'en');
            if ($name !== '' && $name !== $code) {
                return $name;
            }
        }

        return $code;
    }

    /** "At Main mill (120 m)" for the nearest of the mill's sites within 3 km, or null. */
    private function nearestSite(float $latitude, float $longitude, ?string $entityId): ?string
    {
        try {
            $sites = $this->tenant->runAsSystem(fn () => Site::query()
                ->when($entityId !== null, fn ($q) => $q->where('entity_id', $entityId))
                ->where('status', 'active')->get(['name', 'latitude', 'longitude']));
        } catch (Throwable) {
            return null;
        }
        $best = null;
        foreach ($sites as $s) {
            $d = Geo::distanceMeters($latitude, $longitude, (float) $s->latitude, (float) $s->longitude);
            if ($d <= self::NEAR_SITE_M && ($best === null || $d < $best[1])) {
                $best = [$s->name, $d];
            }
        }

        return $best === null ? null : 'At '.$best[0].' ('.($best[1] < 1000 ? round($best[1]).' m' : number_format($best[1] / 1000, 1).' km').')';
    }
}

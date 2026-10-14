<?php

declare(strict_types=1);

namespace App\Domain\Security;

use MaxMind\Db\Reader;
use Throwable;

/**
 * City and country of an IP address from an offline database file (DB-IP "IP to City Lite", free
 * under CC BY 4.0, refreshed monthly by `geoip:update`). Nothing is sent to any outside service.
 * Addresses inside the mill's own network have no public location and are reported as "local".
 * Without the file every lookup simply returns nothing.
 */
final class GeoIp
{
    private ?Reader $reader = null;

    private bool $opened = false;

    /** @return array{country: ?string, region: ?string, city: ?string, latitude: ?float, longitude: ?float, source: string}|null */
    public function lookup(?string $ip): ?array
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return ['country' => null, 'region' => null, 'city' => null, 'latitude' => null, 'longitude' => null, 'source' => 'local'];
        }
        $reader = $this->reader();
        if ($reader === null) {
            return null;
        }
        try {
            $r = $reader->get($ip);
        } catch (Throwable) {
            return null;
        }
        if (! is_array($r)) {
            return null;
        }

        return [
            'country' => isset($r['country']['iso_code']) ? mb_substr((string) $r['country']['iso_code'], 0, 2) : null,
            'region' => isset($r['subdivisions'][0]['names']['en']) ? mb_substr((string) $r['subdivisions'][0]['names']['en'], 0, 100) : null,
            'city' => isset($r['city']['names']['en']) ? mb_substr((string) $r['city']['names']['en'], 0, 100) : null,
            'latitude' => isset($r['location']['latitude']) ? (float) $r['location']['latitude'] : null,
            'longitude' => isset($r['location']['longitude']) ? (float) $r['location']['longitude'] : null,
            'source' => 'ip',
        ];
    }

    public static function path(): string
    {
        return (string) config('ricemill.geoip.path');
    }

    public function available(): bool
    {
        return $this->reader() !== null;
    }

    private function reader(): ?Reader
    {
        if (! $this->opened) {
            $this->opened = true;
            try {
                $this->reader = is_file(self::path()) ? new Reader(self::path()) : null;
            } catch (Throwable) {
                $this->reader = null;
            }
        }

        return $this->reader;
    }
}

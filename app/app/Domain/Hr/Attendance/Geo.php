<?php

declare(strict_types=1);

namespace App\Domain\Hr\Attendance;

final class Geo
{
    private const EARTH_RADIUS_M = 6_371_008.8;

    /** Great-circle (haversine) distance in whole metres. */
    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) round(2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a))));
    }
}

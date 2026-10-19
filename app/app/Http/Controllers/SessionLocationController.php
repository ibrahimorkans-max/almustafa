<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\ActorContext;
use App\Domain\Security\ReverseGeocoder;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The browser's own position for the current session (public/js/session-location.js), given only
 * when the person allowed it in the browser's prompt; sent after sign-in and again every 15 minutes
 * while the person works. The position is turned into area, city, region and country
 * (ReverseGeocoder); the IP's own city stays recorded apart (ip_*).
 */
final class SessionLocationController
{
    public function __invoke(Request $request, ActorContext $actor, TenantContext $tenant, ReverseGeocoder $geocoder): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);
        $session = $actor->session();
        if ($session === null) {
            return response()->json(['ok' => false], 401);
        }
        $lat = round((float) $data['latitude'], 6);
        $lng = round((float) $data['longitude'], 6);
        $place = $geocoder->lookup($lat, $lng, $session->entity_id);

        $fill = [
            'latitude' => $lat, 'longitude' => $lng, 'location_at' => now(),
            'location_accuracy_m' => isset($data['accuracy']) ? (int) $data['accuracy'] : null, 'location_source' => 'browser',
            'area' => $place['area'] ?? null, 'address' => $place['address'] ?? null,
        ];
        // City / region / country of the exact position when it was found; otherwise the IP's stay.
        if (($place['city'] ?? null) !== null || ($place['country'] ?? null) !== null) {
            $fill += ['city' => $place['city'] ?? null, 'region' => $place['region'] ?? null,
                'country' => $place['country'] ?? null, 'country_name' => $place['country_name'] ?? null];
        }
        $tenant->runAsSystem(fn () => $session->forceFill($fill)->save());

        return response()->json(['ok' => true, 'place' => $session->placeLabel()]);
    }
}

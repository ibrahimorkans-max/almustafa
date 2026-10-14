<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\ActorContext;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The browser's own position for the current session (public/js/session-location.js), given only
 * when the person allowed it in the browser's prompt. It replaces the rough IP position on the map;
 * the IP city stays as recorded.
 */
final class SessionLocationController
{
    public function __invoke(Request $request, ActorContext $actor, TenantContext $tenant): JsonResponse
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
        $tenant->runAsSystem(fn () => $session->forceFill([
            'latitude' => round((float) $data['latitude'], 6), 'longitude' => round((float) $data['longitude'], 6),
            'location_accuracy_m' => isset($data['accuracy']) ? (int) $data['accuracy'] : null, 'location_source' => 'browser',
        ])->save());

        return response()->json(['ok' => true]);
    }
}

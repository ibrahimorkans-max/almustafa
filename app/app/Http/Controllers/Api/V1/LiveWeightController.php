<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Gate\LiveWeight;
use App\Domain\Shared\BusinessRuleViolation;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/v1/gate/live-weight: the gate agent's current indicator reading (display only). */
final class LiveWeightController extends Controller
{
    public function store(Request $request, LiveWeight $live): JsonResponse
    {
        $data = $request->validate([
            'scale_id' => ['required', 'uuid'],
            'kg' => ['required', 'numeric'],
            'stable' => ['required', 'boolean'],
            'at' => ['nullable', 'date'],
            // Every reading since the last send (about 10 a second), oldest first; age_ms is how long before the newest.
            'readings' => ['nullable', 'array', 'max:60'],
            'readings.*.kg' => ['required', 'numeric'],
            'readings.*.stable' => ['required', 'boolean'],
            'readings.*.age_ms' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);
        try {
            $live->record($data);
        } catch (BusinessRuleViolation $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }
}

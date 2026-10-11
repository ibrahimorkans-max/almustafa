<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Gate\GateDevices;
use App\Domain\Shared\BusinessRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\Gate\GateEntry;
use App\Support\Values\PakistanIds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Online barrier decision for the gate agent. The agent opens the barrier only on `open: true`;
 * without an answer (offline, error) it stays closed.
 */
final class GateBarrierController extends Controller
{
    public function decide(Request $request, GateDevices $devices): JsonResponse
    {
        $data = $request->validate([
            'side' => ['required', 'in:in,out'],
            'gate_entry_id' => ['nullable', 'uuid'],
            'plate' => ['nullable', 'string', 'max:20'],
        ]);
        $entry = isset($data['gate_entry_id']) ? GateEntry::query()->find($data['gate_entry_id']) : null;
        if ($entry === null && isset($data['plate'])) {
            $entry = $devices->openEntryFor(PakistanIds::normalizeVehicleNumber((string) $data['plate']));
        }

        try {
            return response()->json($devices->barrier($entry, (string) $data['side'], now()));
        } catch (BusinessRuleViolation $e) {
            return response()->json(['open' => false, 'reason' => 'forbidden', 'message' => $e->getMessage()], 403);
        }
    }
}

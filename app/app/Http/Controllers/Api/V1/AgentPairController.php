<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Gate\AgentPairing;
use App\Domain\Shared\BusinessRuleViolation;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/agent/pair {code}: the weighbridge PC trades a one-time pairing code (made under
 * Security -> Devices -> Pair a weighbridge PC) for its login, device_uid, signing secret and weighbridge.
 * No sign-in: the code is the proof. Rate-limited per address; a code works once, for 30 minutes.
 */
class AgentPairController extends Controller
{
    public function pair(Request $request, AgentPairing $pairing): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);
        try {
            $settings = $pairing->redeem($request->string('code')->toString(), $request->ip());
        } catch (BusinessRuleViolation $e) {
            return response()->json(['error' => 'pairing_code_invalid', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'ok', ...$settings]);
    }
}

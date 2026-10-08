<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Ops\HealthCheck;
use Illuminate\Http\JsonResponse;

/** GET /health — 200 when every check passes, 503 otherwise (for an uptime monitor). */
final class HealthController extends Controller
{
    public function __invoke(HealthCheck $health): JsonResponse
    {
        $result = $health->run();

        return response()->json($result, $result['status'] === 'ok' ? 200 : 503, ['Cache-Control' => 'no-store']);
    }
}

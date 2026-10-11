<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Security\MaintenanceService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * During a maintenance window: 503 with Retry-After for everybody without the bypass cookie. The
 * secret bypass link sets the cookie. Health checks and the public verification page stay up.
 */
final class MaintenanceWindow
{
    private const ALWAYS_OPEN = ['health', 'up', 'verify', 'verify/*', 'maintenance/bypass/*'];

    public function __construct(private readonly MaintenanceService $maintenance) {}

    public function handle(Request $request, Closure $next): Response
    {
        $window = $this->maintenance->active();
        if ($window === null || $request->is(...self::ALWAYS_OPEN)
            || $this->maintenance->bypassValid($request->cookie(MaintenanceService::BYPASS_COOKIE), $window)) {
            return $next($request);
        }

        $until = CarbonImmutable::parse($window->ends_at, 'UTC');
        $retryAfter = max(60, (int) now()->diffInSeconds($until, true));
        $back = $until->timezone('Asia/Karachi');
        $headers = ['Retry-After' => (string) $retryAfter];

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'error' => 'maintenance', 'message' => $window->message,
                'until' => $until->toIso8601String(), 'retry_after_seconds' => $retryAfter,
            ], 503, $headers);
        }

        return response()->view('maintenance', ['message' => $window->message, 'back' => $back], 503, $headers);
    }
}

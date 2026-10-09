<?php

use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Auth\Exceptions\AuthenticationFailed;
use App\Domain\Security\MaintenanceService;
use App\Domain\Tenancy\Exceptions\CrossEntityWrite;
use App\Domain\Tenancy\Exceptions\MissingTenantContext;
use App\Http\Middleware\CaptureClientContext;
use App\Http\Middleware\MaintenanceWindow;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Eloquent\StaleModelException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(CaptureClientContext::class);
        $middleware->append(MaintenanceWindow::class);
        // The maintenance bypass cookie holds a random token checked against its hash before the web stack runs.
        $middleware->encryptCookies(except: [MaintenanceService::BYPASS_COOKIE]);
        // API: plain 401, never a redirect. Web: the back-office sign-in page.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/admin/login');
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([AuthenticationFailed::class, ApprovalDenied::class]);

        // The API always answers in JSON (devices may omit Accept), never with a login redirect.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $json = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->render(fn (AuthenticationFailed $e, Request $request) => $json($request)
            ? response()->json(['error' => $e->publicCode, 'message' => $e->publicMessage()], $e->status)
            : null);

        // A device whose token stopped working is told why, so it can show it honestly and stop retrying.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $reason = $request->attributes->get('auth_failure');
            $message = match ($reason) {
                'access_disabled' => 'Access disabled, contact your administrator.',
                'system_locked' => 'System locked by administrator.',
                'device_revoked' => 'This device is no longer allowed. Contact your administrator.',
                default => 'Your session has ended. Please sign in again.',
            };

            return response()->json(['error' => 'unauthenticated', 'reason' => $reason ?? 'session_ended', 'message' => $message], 401);
        });

        $exceptions->render(fn (ApprovalDenied $e, Request $request) => $json($request)
            ? response()->json(['error' => 'approval_denied', 'message' => $e->getMessage()], 422)
            : null);

        $exceptions->render(fn (StaleModelException $e, Request $request) => $json($request)
            ? response()->json(['error' => 'stale_record', 'message' => $e->getMessage()], 409)
            : null);

        $exceptions->render(fn (CrossEntityWrite|MissingTenantContext $e, Request $request) => $json($request)
            ? response()->json(['error' => 'forbidden', 'message' => 'This action is not allowed.'], 403)
            : abort(403));
    })->create();

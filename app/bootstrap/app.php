<?php

use App\Domain\Approvals\Exceptions\ApprovalDenied;
use App\Domain\Auth\Exceptions\AuthenticationFailed;
use App\Domain\Tenancy\Exceptions\CrossEntityWrite;
use App\Domain\Tenancy\Exceptions\MissingTenantContext;
use App\Http\Middleware\CaptureClientContext;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Eloquent\StaleModelException;
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
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([AuthenticationFailed::class, ApprovalDenied::class]);

        $json = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->render(fn (AuthenticationFailed $e, Request $request) => $json($request)
            ? response()->json(['error' => $e->publicCode, 'message' => $e->publicMessage()], $e->status)
            : null);

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

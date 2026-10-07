<?php

use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\SessionController;
use Illuminate\Support\Facades\Route;

/*
| Versioned JSON API used by the PWA, the Flutter app and the gate agent.
| Authenticated routes need `Authorization: Bearer <access token>` and `X-Device-Id`.
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::middleware('throttle:api-login')->group(function (): void {
        Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
        Route::post('auth/refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
    });

    Route::middleware(['auth:api', 'throttle:api'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/switch', [AuthController::class, 'switchAccount'])->name('auth.switch');

        Route::get('sessions', [SessionController::class, 'index'])->name('sessions.index');
        Route::delete('sessions/{session}', [SessionController::class, 'destroy'])->whereUuid('session')->name('sessions.destroy');

        Route::get('audit', [AuditController::class, 'index'])->name('audit.index');
        Route::get('audit/verify', [AuditController::class, 'verify'])->name('audit.verify');
    });
});

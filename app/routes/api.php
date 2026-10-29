<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\GateBarrierController;
use App\Http\Controllers\Api\V1\LeaveController;
use App\Http\Controllers\Api\V1\LiveWeightController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Middleware\ApplyDataVisibility;
use App\Http\Middleware\EnforceRemoteWipe;
use App\Http\Middleware\VerifyAgentSignature;
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

    Route::middleware(['auth:api', VerifyAgentSignature::class, EnforceRemoteWipe::class, ApplyDataVisibility::class, 'throttle:api'])->group(function (): void {
        Route::get('sync/bootstrap', [SyncController::class, 'bootstrap'])->name('sync.bootstrap');
        Route::post('sync/push', [SyncController::class, 'push'])->name('sync.push');
        Route::get('sync/pull', [SyncController::class, 'pull'])->name('sync.pull');
        Route::post('sync/wipe-ack', [SyncController::class, 'wipeAck'])->name('sync.wipe-ack');
        Route::post('files', [FileController::class, 'store'])->name('files.store');
        Route::post('gate/barrier', [GateBarrierController::class, 'decide'])->name('gate.barrier');
        // About one reading a second from each weighbridge PC; its own limit so it never eats the sync budget.
        Route::post('gate/live-weight', [LiveWeightController::class, 'store'])->withoutMiddleware('throttle:api')->middleware('throttle:120,1')->name('gate.live_weight');

        Route::get('attendance/me', [AttendanceController::class, 'me'])->name('attendance.me');
        Route::post('attendance/consent', [AttendanceController::class, 'consent'])->name('attendance.consent');
        Route::post('attendance/device', [AttendanceController::class, 'requestDevice'])->name('attendance.device');

        Route::get('documents/me', [DocumentController::class, 'me'])->name('documents.me');
        Route::get('documents/{document}/pdf', [DocumentController::class, 'pdf'])->whereUuid('document')->name('documents.pdf');
        Route::get('leave/me', [LeaveController::class, 'me'])->name('leave.me');
        Route::post('leave/requests', [LeaveController::class, 'store'])->name('leave.store');
        Route::post('leave/requests/{leaveRequest}/withdraw', [LeaveController::class, 'withdraw'])->whereUuid('leaveRequest')->name('leave.withdraw');

        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/switch', [AuthController::class, 'switchAccount'])->name('auth.switch');

        Route::get('sessions', [SessionController::class, 'index'])->name('sessions.index');
        Route::delete('sessions/{session}', [SessionController::class, 'destroy'])->whereUuid('session')->name('sessions.destroy');

        Route::get('audit', [AuditController::class, 'index'])->name('audit.index');
        Route::get('audit/verify', [AuditController::class, 'verify'])->name('audit.verify');
    });
});

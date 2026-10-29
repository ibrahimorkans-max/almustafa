<?php

use App\Domain\Security\MaintenanceService;
use App\Http\Controllers\FirebaseServiceWorkerController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\RoznamchaCaptureController;
use App\Http\Controllers\SharedDocumentController;
use App\Http\Controllers\SharedStatementController;
use App\Http\Controllers\VerifyDocumentController;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Public check of printed slips, invoices and gate passes (number + printed code; minimal facts only).
Route::get('/verify', VerifyDocumentController::class)->middleware('throttle:30,1')->name('verify');

// Shared HR documents (WhatsApp / SMS / e-mail links): random token, expiring, revocable, logged.
Route::get('/d/{token}', SharedDocumentController::class)->middleware('throttle:30,1')->name('documents.shared');

// Uptime monitoring: states and ages only, no secrets or versions.
// Firebase notifications for parties: the service worker must be served from the site root.
Route::get('/firebase-messaging-sw.js', FirebaseServiceWorkerController::class)->middleware('throttle:60,1')->name('firebase.sw');

// A phone's camera for a roznamcha page being entered on a computer (signed link, 30 minutes, one photo).
Route::middleware(['signed', 'throttle:20,1'])->group(function (): void {
    Route::get('/roznamcha/capture/{token}', [RoznamchaCaptureController::class, 'show'])->where('token', '[A-Za-z0-9]{40}')->name('roznamcha.capture');
    Route::post('/roznamcha/capture/{token}', [RoznamchaCaptureController::class, 'store'])->where('token', '[A-Za-z0-9]{40}')->name('roznamcha.capture.store');
});
// A party statement shared by WhatsApp: signed, expiring link (PartyStatementSharing).
Route::get('/statement', SharedStatementController::class)->middleware(['signed', 'throttle:30,1'])->name('statements.shared');
Route::get('/health', HealthController::class)->middleware('throttle:60,1')->name('health');

// Secret link given to the Owner who schedules maintenance: keeps working during the window.
Route::get('/maintenance/bypass/{token}', function (string $token, MaintenanceService $maintenance) {
    $window = collect($maintenance->current())->first(fn (object $w) => $maintenance->bypassValid($token, $w));
    abort_if($window === null, 404);
    $minutes = (int) max(1, now()->diffInMinutes(CarbonImmutable::parse($window->ends_at, 'UTC'), true) + 5);

    return redirect('/admin')->withCookie(cookie(MaintenanceService::BYPASS_COOKIE, $token, $minutes, secure: request()->isSecure(), httpOnly: true, sameSite: 'strict'));
})->middleware('throttle:10,1')->name('maintenance.bypass');

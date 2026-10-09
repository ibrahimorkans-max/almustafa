<?php

use App\Domain\Security\MaintenanceService;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\VerifyDocumentController;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Public check of printed slips, invoices and gate passes (number + printed code; minimal facts only).
Route::get('/verify', VerifyDocumentController::class)->middleware('throttle:30,1')->name('verify');

// Uptime monitoring: states and ages only, no secrets or versions.
Route::get('/health', HealthController::class)->middleware('throttle:60,1')->name('health');

// Secret link given to the Super Admin who schedules maintenance: keeps working during the window.
Route::get('/maintenance/bypass/{token}', function (string $token, MaintenanceService $maintenance) {
    $window = collect($maintenance->current())->first(fn (object $w) => $maintenance->bypassValid($token, $w));
    abort_if($window === null, 404);
    $minutes = (int) max(1, now()->diffInMinutes(CarbonImmutable::parse($window->ends_at, 'UTC'), true) + 5);

    return redirect('/admin')->withCookie(cookie(MaintenanceService::BYPASS_COOKIE, $token, $minutes, secure: request()->isSecure(), httpOnly: true, sameSite: 'strict'));
})->middleware('throttle:10,1')->name('maintenance.bypass');

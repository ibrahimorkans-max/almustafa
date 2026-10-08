<?php

use App\Http\Controllers\VerifyDocumentController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Public check of printed slips, invoices and gate passes (number + printed code; minimal facts only).
Route::get('/verify', VerifyDocumentController::class)->middleware('throttle:30,1')->name('verify');

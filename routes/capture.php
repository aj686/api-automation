<?php

use App\Http\Controllers\CaptureController;
use Illuminate\Support\Facades\Route;

// Outbound capture (plan section 13). Loaded with the `api` middleware group:
// no session, no CSRF — the caller is another system, not a browser.
Route::any('/capture/{bucket}/{token}/{path?}', CaptureController::class)
    ->where('path', '.*')
    ->middleware('throttle:capture')
    ->name('capture');

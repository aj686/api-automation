<?php

use Illuminate\Support\Facades\Route;

// /api/v1 — called by n8n with `Authorization: Bearer <token>` (plan section 8).
// No basic auth on /api/ in nginx (loophole 42); this token is the protection.
Route::prefix('v1')->middleware(['automation.token', 'throttle:automation-api'])->group(function () {
    // Lets n8n (or curl) check the URL and token before any real call.
    Route::get('/ping', fn () => response()->json(['ok' => true]))->name('api.ping');
});

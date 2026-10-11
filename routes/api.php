<?php

use App\Http\Controllers\Api\CaptureController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\RunController;
use Illuminate\Support\Facades\Route;

// /api/v1 — called by n8n with `Authorization: Bearer <token>` (plan section 8,
// contract in docs/n8n.md). No basic auth on /api/ in nginx (loophole 42);
// this token is the protection.
Route::prefix('v1')->middleware(['automation.token', 'throttle:automation-api'])->group(function () {
    // Lets n8n (or curl) check the URL and token before any real call.
    Route::get('/ping', fn () => response()->json(['ok' => true]))->name('api.ping');

    Route::get('/catalog', CatalogController::class)->name('api.catalog');
    Route::post('/runs', [RunController::class, 'store'])->name('api.runs.store');
    Route::get('/runs/{run}', [RunController::class, 'show'])->name('api.runs.show');

    // What a capture URL received; Postman polls this in outbound tests (plan section 13).
    Route::get('/captures/{project:slug}/{bucket}', CaptureController::class)->name('api.captures');
});

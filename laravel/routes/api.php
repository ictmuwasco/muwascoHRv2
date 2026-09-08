<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\V1\PingController;
use Illuminate\Support\Facades\Route;

/*
 * MUWASCO HR API — v1 (Laravel backend, the authoritative backend after
 * the strangler-fig cutover). The legacy PHP API (api.php / backend/)
 * keeps serving the production SPA until L12; Laravel owns /api/v1/**.
 */

// Public health check.
Route::prefix('v1')->group(function (): void {
    Route::get('/ping', PingController::class)->middleware('throttle:60,1');

    // Auth routes (L2).
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,15');
    Route::post('/auth/logout', [AuthController::class, 'logout'])
        ->middleware('auth:sanctum');
    Route::post('/auth/change-password', [AuthController::class, 'changePassword'])
        ->middleware('auth:sanctum')
        ->middleware('throttle:3,60');
    Route::get('/auth/user', [AuthController::class, 'user'])
        ->middleware('auth:sanctum');
});

// Skeleton placeholder kept from install:api — becomes the real
// /api/v1/auth/user endpoint in L2 (minimal payload per mandate §5.2).
Route::get('/user', function (\Illuminate\Http\Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


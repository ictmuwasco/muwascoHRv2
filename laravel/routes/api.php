<?php

use App\Http\Controllers\Api\V1\PingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * MUWASCO HR API — v1 (Laravel backend, the authoritative backend after
 * the strangler-fig cutover). The legacy PHP API (api.php / backend/)
 * keeps serving the production SPA until L12; Laravel owns /api/v1/**.
 */
Route::prefix('v1')->group(function (): void {
    Route::get('/ping', PingController::class)->middleware('throttle:60,1');
});

// Skeleton placeholder kept from install:api — becomes the real
// /api/v1/auth/user endpoint in L2 (minimal payload per mandate §5.2).
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


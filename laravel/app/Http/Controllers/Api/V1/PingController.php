<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Liveness/contract probe for the Laravel API (L1).
 *
 * Used by the strangler-fig cutover checklist, load balancers and the React
 * integration phase to verify the Laravel backend serves the unified
 * envelope before any real module is switched over.
 */
class PingController extends Controller
{
    use ApiResponse;

    public function __invoke(): JsonResponse
    {
        return $this->success([
            'service'   => 'muwasco-hr-laravel',
            'version'   => 'v1',
            'time'      => now()->toIso8601String(),
            'framework' => app()->version(),
        ], 'Laravel API is reachable.');
    }
}

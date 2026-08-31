<?php

declare(strict_types=1);

/**
 * CORS configuration.
 *
 * Centralized allow-list for cross-origin requests from the Vue SPA.
 * SecurityMiddleware::applyCorsHeaders() reads these values, replacing the
 * inline origin lists that were previously duplicated in api.php,
 * BaseController::json() and AuthenticationMiddleware.
 *
 * Origins may be overridden per-environment via the CORS_ALLOWED_ORIGINS
 * env var (comma-separated list).
 */
return [
    // Comma-separated override, e.g. "https://hr.example.org,http://localhost:5173".
    'allowed_origins' => env('CORS_ALLOWED_ORIGINS')
        ? array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS')))
        : [
            'http://localhost:5173', // Vite dev server
            'http://localhost:3000', // Alternative dev port
            'http://localhost',      // Production
        ],

    'allowed_methods'  => 'GET, POST, PUT, DELETE, OPTIONS',
    'allowed_headers'  => 'Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN',
    'allow_credentials' => true,
    'max_age'           => 86400,
];

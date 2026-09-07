<?php

/*
 * Centralized CORS policy (ports backend/config/cors.php semantics).
 *
 * The React SPA is the only first-party browser client. Credentials
 * (Sanctum SPA cookie / httpOnly token cookie) require an explicit origin
 * list — never `*` together with supports_credentials.
 */
return [

    'paths' => ['api/*', 'up'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:5173'),
        env('FRONTEND_URL_SECONDARY', 'http://localhost/hrdemo'),
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With', 'X-Request-Id'],

    'exposed_headers' => ['X-Request-Id'],

    'max_age' => 3600,

    'supports_credentials' => true,

];

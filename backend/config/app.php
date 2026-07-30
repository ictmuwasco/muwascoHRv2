<?php

declare(strict_types=1);

/**
 * Application Configuration
 *
 * NOTE: The previous `providers` and `aliases` keys were dead config — the
 * referenced `App\Providers\*` and `App\Events\*` namespaces were never
 * created. They have been removed; add them back only when those subsystems
 * are actually implemented.
 */
return [
    'name' => env('APP_NAME', 'MUWASCO HR System'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost/hrdemo'),
    'timezone' => 'Africa/Nairobi',
    'locale' => 'en',
    'charset' => 'utf8mb4',
];
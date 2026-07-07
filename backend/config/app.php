<?php

declare(strict_types=1);

/**
 * Application Configuration
 */
return [
    'name' => env('APP_NAME', 'MUWASCO HR System'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost/hrdemo'),
    'timezone' => 'Africa/Nairobi',
    'locale' => 'en',
    'charset' => 'utf8mb4',

    'providers' => [
        \App\Providers\AuthServiceProvider::class,
        \App\Providers\EventServiceProvider::class,
        \App\Providers\RouteServiceProvider::class,
    ],

    'aliases' => [
        'Auth' => \App\Helpers\Auth::class,
        'Hash' => \App\Helpers\Hash::class,
        'Session' => \App\Helpers\Session::class,
    ],
];
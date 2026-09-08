<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SPA cookie auth: the React app (Vite dev server or built assets)
        // talks to this API over cookies set via Sanctum.
        $middleware->api(prepend: EnsureFrontendRequestsAreStateful::class);

        // Correlation id on EVERY response (legacy api.php contract) —
        // global, so unmatched routes and exception-rendered responses
        // carry it too.
        $middleware->append(App\Http\Middleware\EnsureRequestId::class);

        // XAMPP/Apache terminates TLS and proxies in dev + staging.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Render AuthenticationException as the standard API envelope.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                    'data'    => null,
                    'errors'  => [
                        'code'       => 'AUTH_NOT_AUTHENTICATED',
                        'request_id' => $request->headers->get('X-Request-Id') ?: (string) $request->attributes->get('request_id'),
                    ],
                ], 401);
            }
        });
    })->create();

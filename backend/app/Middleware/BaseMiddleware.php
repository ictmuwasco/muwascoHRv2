<?php

declare(strict_types=1);

namespace App\Middleware;

/**
 * Base Middleware
 *
 * Provides common functionality for all middleware.
 *
 * Note (Phase 1): implements App\Middleware\MiddlewareInterface directly
 * (same namespace). The previous `use App\Middleware\Contracts\...` import
 * referenced an interface that does not exist anywhere in the codebase and
 * would have caused a fatal error the moment this class was autoloaded.
 */
abstract class BaseMiddleware implements MiddlewareInterface
{
    /**
     * Handle the incoming request.
     *
     * @param callable $next
     * @return mixed
     */
    abstract public function handle(callable $next): mixed;

    /**
     * Redirect to a URL.
     */
    protected function redirect(string $url): void
    {
        header("Location: {$url}");
        exit();
    }

    /**
     * Return a JSON response.
     *
     * Delegates to the standardized ApiResponse envelope so middleware exits
     * share the same shape as controller and exception-handler responses.
     */
    protected function json(array $data, int $statusCode = 200): void
    {
        if (($data['success'] ?? null) === false) {
            \App\Helpers\ApiResponse::error(
                (string) ($data['message'] ?? $data['error'] ?? 'Request failed.'),
                'MIDDLEWARE_ERROR',
                [],
                $statusCode
            );
        }
        \App\Helpers\ApiResponse::success($data, 'OK', $statusCode);
    }

    /**
     * Abort the request with a standardized error envelope.
     */
    protected function abort(string $message, int $statusCode = 403): void
    {
        \App\Helpers\ApiResponse::error($message, 'REQUEST_ABORTED', [], $statusCode);
    }
}
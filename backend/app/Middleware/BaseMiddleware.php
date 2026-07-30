<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Middleware\Contracts\MiddlewareInterface;

/**
 * Base Middleware
 *
 * Provides common functionality for all middleware.
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
     */
    protected function json(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }

    /**
     * Abort the request with an error message.
     */
    protected function abort(string $message, int $statusCode = 403): void
    {
        $this->json(['error' => $message], $statusCode);
    }
}
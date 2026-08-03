<?php

declare(strict_types=1);

namespace App\Middleware;

/**
 * Middleware Interface
 *
 * Defines the contract for all middleware in the application.
 */
interface MiddlewareInterface
{
    /**
     * Handle the incoming request.
     *
     * @param callable $next
     * @return mixed
     */
    public function handle(callable $next): mixed;
}
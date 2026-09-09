<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Not Found Exception
 * 
 * Thrown when a resource is not found (404 Not Found).
 */
class NotFoundException extends AppException
{
    public function __construct(
        string $message = 'Resource not found',
        string $resourceType = 'Resource',
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            $message,
            'NOT_FOUND',
            404,
            ['resource_type' => $resourceType],
            $previous
        );
    }
}

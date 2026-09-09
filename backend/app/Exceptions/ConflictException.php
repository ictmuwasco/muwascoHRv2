<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Conflict Exception
 * 
 * Thrown when there's a resource conflict (409 Conflict).
 * Example: duplicate email, overlapping dates, etc.
 */
class ConflictException extends AppException
{
    public function __construct(
        string $message = 'Resource conflict',
        array $details = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            $message,
            'CONFLICT',
            409,
            $details,
            $previous
        );
    }
}

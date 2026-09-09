<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Authentication Exception
 * 
 * Thrown when authentication fails (401 Unauthorized).
 */
class AuthenticationException extends AppException
{
    public function __construct(
        string $message = 'Authentication required',
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            $message,
            'UNAUTHENTICATED',
            401,
            [],
            $previous
        );
    }
}

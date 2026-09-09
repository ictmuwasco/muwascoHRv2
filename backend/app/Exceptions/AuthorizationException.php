<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Authorization Exception
 * 
 * Thrown when user lacks permission (403 Forbidden).
 */
class AuthorizationException extends AppException
{
    public function __construct(
        string $message = 'You do not have permission to perform this action',
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            $message,
            'FORBIDDEN',
            403,
            [],
            $previous
        );
    }
}

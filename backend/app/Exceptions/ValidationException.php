<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Validation Exception
 * 
 * Thrown when input validation fails (422 Unprocessable Entity).
 */
class ValidationException extends AppException
{
    public function __construct(
        string $message = 'Validation failed',
        array $errors = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            $message,
            'VALIDATION_ERROR',
            422,
            ['errors' => $errors],
            $previous
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Rate Limit Exception
 * 
 * ThrottledException
 * Thrown when rate limit is exceeded (429 Too Many Requests).
 */
class RateLimitException extends AppException
{
    public function __construct(
        string $message = 'Too many requests. Please try again later.',
        int $retryAfter = 60,
        ?\Throwable $previous = null
    ) {
        parent::__construct(
            $message,
            'RATE_LIMIT_EXCEEDED',
            429,
            ['retry_after' => $retryAfter],
            $previous
        );
    }
}

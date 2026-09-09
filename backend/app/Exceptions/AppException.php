<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Base Application Exception
 * 
 * All custom exceptions extend this class for consistent error handling.
 */
abstract class AppException extends RuntimeException
{
    protected string $errorCode;
    protected int $httpStatusCode;
    protected array $details;

    public function __construct(
        string $message = '',
        string $errorCode = 'ERROR',
        int $httpStatusCode = 400,
        array $details = [],
        ?\Throwable $previous = null
    ) {
        $this->errorCode = $errorCode;
        $this->httpStatusCode = $httpStatusCode;
        $this->details = $details;
        
        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * Convert exception to array for API response
     */
    public function toArray(): array
    {
        return [
            'success' => false,
            'message' => $this->getMessage(),
            'error' => [
                'code' => $this->errorCode,
                'details' => $this->details,
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Leave;

/**
 * Base exception for leave-domain business rule failures. Carries
 * client-safe context; maps to HTTP 4xx in the controller layer.
 */
class LeaveException extends \RuntimeException
{
    /** @var array<string,mixed> */
    private array $context;

    /**
     * @param array<string,mixed> $context
     */
    public function __construct(string $message, array $context = [], int $code = 0)
    {
        parent::__construct($message, $code);
        $this->context = $context;
    }

    /**
     * @return array<string,mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}

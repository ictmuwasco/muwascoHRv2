<?php

declare(strict_types=1);

namespace App\Services\Attendance;

/**
 * Base exception for attendance-domain business rule failures.
 *
 * Carries structured context (distances, radii, stable error codes) so the
 * HTTP layer can map failures to precise API responses without parsing
 * human-readable messages. Never carries internal details (SQL, paths).
 */
class AttendanceException extends \RuntimeException
{
    /** @var array<string,mixed> */
    private array $context;

    /**
     * @param array<string,mixed> $context Structured, client-safe context.
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

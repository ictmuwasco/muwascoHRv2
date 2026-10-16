<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * AiAssistantException — application-level error raised by the AI assistant
 * service layer. Carries a user-safe message, a stable reason code, and an
 * HTTP status code. Never leaks provider internals or stack traces upstream.
 */
class AiAssistantException extends AppException
{
    /** @var string Stable, user-safe reason code (e.g. ASSISTANT_UNAVAILABLE). */
    private string $reason;

    public function __construct(string $message, string $reason, int $httpStatus = 500, ?\Throwable $previous = null)
    {
        parent::__construct($message, $reason, $httpStatus, [], $previous);
        $this->reason = $reason;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
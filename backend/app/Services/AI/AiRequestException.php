<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * AiRequestException — typed, user-safe request failure raised by the AI
 * chat pipeline. The controller maps the reason to an HTTP status and a
 * sanitized error code; the reason NEVER carries provider internals or the
 * offending user payload.
 */
class AiRequestException extends \RuntimeException
{
    public const REASON_EMPTY_MESSAGE       = 'EMPTY_MESSAGE';
    public const REASON_SAFETY_FLAGGED      = 'SAFETY_FLAGGED';
    public const REASON_CONVERSATION_NOT_FOUND = 'CONVERSATION_NOT_FOUND';

    private function __construct(string $message, string $reason)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }

    private string $reason;

    public function getReason(): string
    {
        return $this->reason;
    }

    public static function emptyMessage(): self
    {
        return new self('Message is required.', self::REASON_EMPTY_MESSAGE);
    }

    /** Generic wording on purpose: never echo the flagged payload back. */
    public static function safetyFlagged(string $ruleLabel): self
    {
        \logger()->warning('AI chat request blocked by safety filter', ['rule' => $ruleLabel]);
        return new self(
            'Your message was blocked by the assistant safety filter. Please rephrase the request.',
            self::REASON_SAFETY_FLAGGED
        );
    }

    public static function conversationNotFound(): self
    {
        return new self('Conversation not found.', self::REASON_CONVERSATION_NOT_FOUND);
    }

    public const REASON_PROVIDER_UNAVAILABLE = 'PROVIDER_UNAVAILABLE';

    /**
     * The provider could not produce a completion (any non-success result
     * status). Message is fixed and retry-friendly; the reason maps to a
     * 503 + AI_UNAVAILABLE in the controller. Provider internals are never
     * included.
     */
    public static function providerUnavailable(): self
    {
        \logger()->warning('AI completion unavailable', ['reason' => self::REASON_PROVIDER_UNAVAILABLE]);
        return new self(
            'The AI assistant is unavailable right now. Please try again shortly.',
            self::REASON_PROVIDER_UNAVAILABLE
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * AiCompletionResult — typed outcome of one AI completion attempt.
 *
 * Mirrors the SMS provider's SmsResult value object (status constants,
 * immutable, isRetryable()). Providers return this object; they never echo
 * raw provider bodies or headers back to the API.
 *
 * NOTE: the content field carries the provider's plain-text reply AFTER the
 * Phase 5/11 output guard has validated and labelled it, and any tool calls
 * returned are PROPOSALS only — they are NOT executed by the provider and are
 * validated/authorized independently by the controlled-tool layer.
 */
final class AiCompletionResult
{
    public const STATUS_SUCCESS           = 'SUCCESS';
    public const STATUS_TEMPORARY_FAILURE = 'TEMPORARY_FAILURE';
    public const STATUS_PERMANENT_FAILURE = 'PERMANENT_FAILURE';
    public const STATUS_RATE_LIMITED      = 'RATE_LIMITED';
    public const STATUS_PROVIDER_ERROR    = 'PROVIDER_ERROR';
    public const STATUS_TIMEOUT           = 'TIMEOUT';
    public const STATUS_TOOL_CALL_SELECTED = 'TOOL_CALL_SELECTED';

    private function __construct(
        private string $status,
        private string $content = '',
        private array $toolCalls = [],
        private string $finishReason = '',
        private array $usage = [],
        private string $providerModel = '',
        private string $failureReason = '',
        private int $httpStatus = 0,
        private int $attempts = 1
    ) {
    }

    public static function success(
        string $content,
        string $finishReason = 'stop',
        array $usage = [],
        string $providerModel = ''
    ): self {
        return new self(self::STATUS_SUCCESS, $content, [], $finishReason, $usage, $providerModel);
    }

    /** Assistant chose to invoke one or more tools rather than answer directly. */
    public static function toolCalls(array $toolCalls, string $providerModel = ''): self
    {
        return new self(self::STATUS_TOOL_CALL_SELECTED, '', $toolCalls, 'tool_calls', [], $providerModel);
    }

    public static function failure(string $status, string $reason, int $httpStatus = 0): self
    {
        return new self($status, '', [], '', [], '', $reason, $httpStatus);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /** @return array<int, array{name:string, arguments:string, id?:string}> */
    public function getToolCalls(): array
    {
        return $this->toolCalls;
    }

    public function getFinishReason(): string
    {
        return $this->finishReason;
    }

    /** @return array{prompt_tokens?:int, completion_tokens?:int, total_tokens?:int} */
    public function getUsage(): array
    {
        return $this->usage;
    }

    public function getProviderModel(): string
    {
        return $this->providerModel;
    }

    /** Sanitized, truncated failure reason (never a raw provider body). */
    public function getFailureReason(): string
    {
        return $this->failureReason;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /** Temporary failures / rate limits / timeouts may be retried (or fallback). */
    public function isRetryable(): bool
    {
        return in_array($this->status, [
            self::STATUS_TEMPORARY_FAILURE,
            self::STATUS_RATE_LIMITED,
            self::STATUS_TIMEOUT,
            self::STATUS_PROVIDER_ERROR,
        ], true);
    }

    /** True when the provider asked us to perform tool call(s) instead of replying. */
    public function isToolCall(): bool
    {
        return $this->status === self::STATUS_TOOL_CALL_SELECTED;
    }

    /** Immutable copy carrying the number of transport attempts performed. */
    public function withAttempts(int $attempts): self
    {
        $copy = clone $this;
        $copy->attempts = $attempts;
        return $copy;
    }
}

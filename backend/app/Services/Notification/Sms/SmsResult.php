<?php

declare(strict_types=1);

namespace App\Services\Notification\Sms;

/**
 * Value object describing the outcome of one SMS send attempt.
 */
class SmsResult
{
    public const STATUS_SUCCESS           = 'SUCCESS';
    public const STATUS_TEMPORARY_FAILURE = 'TEMPORARY_FAILURE';
    public const STATUS_PERMANENT_FAILURE = 'PERMANENT_FAILURE';
    public const STATUS_INVALID_NUMBER    = 'INVALID_NUMBER';
    public const STATUS_RATE_LIMITED      = 'RATE_LIMITED';
    public const STATUS_PROVIDER_ERROR    = 'PROVIDER_ERROR';

    private function __construct(
        private string $status,
        private ?string $providerMessageId = null,
        private string $failureReason = ''
    ) {
    }

    public static function success(?string $providerMessageId): self
    {
        return new self(self::STATUS_SUCCESS, $providerMessageId);
    }

    public static function failure(string $status, string $reason): self
    {
        return new self($status, null, $reason);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getProviderMessageId(): ?string
    {
        return $this->providerMessageId;
    }

    public function getFailureReason(): string
    {
        return $this->failureReason;
    }

    /** Temporary failures may be retried by the scheduler policy. */
    public function isRetryable(): bool
    {
        return in_array($this->status, [self::STATUS_TEMPORARY_FAILURE, self::STATUS_RATE_LIMITED], true);
    }
}

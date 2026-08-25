<?php

declare(strict_types=1);

namespace App\Services\Notification;

/**
 * Outcome of one channel delivery attempt.
 */
class ChannelResult
{
    public const SENT              = 'sent';
    public const FAILED            = 'failed';
    public const FAILED_RETRYABLE  = 'failed_retryable';
    public const SKIPPED           = 'skipped';

    private function __construct(
        private string $status,
        private ?string $providerMessageId = null,
        private string $reason = ''
    ) {
    }

    public static function sent(?string $providerMessageId = null): self
    {
        return new self(self::SENT, $providerMessageId);
    }

    public static function failed(string $reason): self
    {
        return new self(self::FAILED, null, $reason);
    }

    public static function failedRetryable(string $reason): self
    {
        return new self(self::FAILED_RETRYABLE, null, $reason);
    }

    public static function skipped(string $reason): self
    {
        return new self(self::SKIPPED, null, $reason);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getProviderMessageId(): ?string
    {
        return $this->providerMessageId;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}

<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

/**
 * Repository contract for notification_logs (idempotency ledger).
 */
interface NotificationLogRepositoryInterface
{
    /**
     * Atomically claim the (user, date, type, channel, stage) slot.
     * Returns the new row id, or null when it already exists
     * (duplicate cron run / retry - caller must not re-send).
     */
    public function claim(int $userId, ?int $employeeId, string $type, string $channel, string $stage, string $businessDate): ?int;

    public function markSent(int $id, ?string $providerMessageId = null): void;
    public function markFailed(int $id, string $reason, bool $retryable): void;
    public function markSkipped(int $id, string $reason): void;
    public function markRetrying(int $id): void;

    public function findById(int $id): ?array;
    /** @return array<int,array> all log rows for the user's day (audit view) */
    public function findByUserAndDate(int $userId, string $date): array;

    /** Aggregated counts grouped by channel/stage/status for one day. @return array<int,array> */
    public function statsForDate(string $date): array;

    /** Number of real SMS attempts for the user today (cost cap). */
    public function countSmsAttempts(int $userId, string $date): int;

    /** Rows awaiting another temporary-failure retry. @return array<int,array> */
    public function findRetryable(string $stage, string $date, int $maxAttempts): array;

    /** Single row lookup for a user/date/channel/stage tuple. */
    public function findFor(int $userId, string $date, string $channel, string $stage): ?array;

    /** Fail rows stuck pending after a crashed process. Returns affected count. */
    public function reapStalePending(int $minutes): int;
}

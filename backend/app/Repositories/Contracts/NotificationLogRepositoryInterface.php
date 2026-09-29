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
     *
     * This is the SCHEDULED-notification form. The dedupe identity is derived
     * from the tuple, which is exactly right for a daily reminder but wrong for
     * an event: use claimFor() with an explicit key for those.
     */
    public function claim(int $userId, ?int $employeeId, string $type, string $channel, string $stage, string $businessDate): ?int;

    /**
     * Claim an EVENT notification by caller-supplied identity.
     *
     * $dedupeKey is the notification's identity, e.g. "leave:412:approved".
     * Two different events on the same day for the same user get different keys
     * and therefore both send - which is the whole reason this exists: the
     * tuple form would have collapsed them as duplicates.
     *
     * Returns the new row id, or null if this exact notification already exists.
     */
    public function claimFor(
        int $userId,
        ?int $employeeId,
        string $type,
        string $channel,
        string $stage,
        string $dedupeKey,
        ?string $businessDate = null,
        ?array $payload = null,
        string $status = 'pending'
    ): ?int;

    /** Rows waiting for the worker, oldest first. @return array<int,array> */
    public function findPendingBatch(int $limit = 50, ?array $types = null): array;

    /** Rows a crashed worker left mid-send. @return array<int,array> */
    public function findStalePending(int $minutes): array;

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

    /**
     * Take exclusive ownership of a pending row.
     * @return bool True when this caller now owns it.
     */
    public function tryClaimPending(int $id): bool;

    /** Put a row back in the queue for a later attempt. */
    public function reschedule(int $id, string $nextAttemptAt, string $reason = ''): void;

    /** Fail rows stuck pending after a crashed process. Returns affected count. */
    public function reapStalePending(int $minutes): int;
}

<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\Database;
use App\Repositories\Contracts\NotificationLogRepositoryInterface;
use mysqli_sql_exception;

/**
 * Notification Log Repository - notification_logs SQL.
 *
 * claim() is THE idempotency gate: a plain INSERT against the unique
 * key means only the first caller wins; concurrent/duplicate runs get
 * a duplicate-key exception and receive null.
 */
class NotificationLogRepository implements NotificationLogRepositoryInterface
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function claim(int $userId, ?int $employeeId, string $type, string $channel, string $stage, string $businessDate): ?int
    {
        // The scheduled form derives its identity from the tuple, so the
        // dedupe semantics are byte-for-byte what migration 025 guaranteed.
        return $this->claimFor(
            $userId,
            $employeeId,
            $type,
            $channel,
            $stage,
            self::scheduledDedupeKey($type, $stage, $businessDate),
            $businessDate
        );
    }

    /**
     * The dedupe identity for a SCHEDULED notification.
     *
     * Mirrors the tuple migration 094 backfills for pre-existing rows, so a
     * reminder written before the migration and one written after it are
     * correctly recognised as the same notification.
     */
    public static function scheduledDedupeKey(string $type, string $stage, string $businessDate): string
    {
        return $businessDate . '|' . $type . '|' . $stage;
    }

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
    ): ?int {
        // The unique index is on (user_id, dedupe_key); MySQL would coerce a
        // NULL to NULL in a unique index, allowing unlimited duplicates, so the
        // key is never allowed to be empty.
        $dedupeKey = trim($dedupeKey);
        if ($dedupeKey === '') {
            throw new \InvalidArgumentException('Notification dedupe key must not be empty.');
        }

        try {
            return $this->db->insert('notification_logs', [
                'user_id'           => $userId,
                'employee_id'       => $employeeId,
                'notification_type' => $type,
                'channel'           => $channel,
                'stage'             => $stage,
                'dedupe_key'        => $dedupeKey,
                // Rendered at dispatch time, where the business context lives.
                'payload'           => $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
                // Event notifications have no attendance "business day"; fall
                // back to today so the admin stats views still bucket them.
                'business_date'     => $businessDate ?? date('Y-m-d'),
                // Callers that already know the outcome (a channel blocked by
                // policy) insert the row as 'skipped' so the worker - which only
                // ever selects 'pending' - cannot pick it up and deliver it.
                'status'            => $status,
                'attempts'          => 0,
                'scheduled_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (mysqli_sql_exception $e) {
            if ((int) ($e->getCode() ?? 0) === 1062 || str_contains($e->getMessage(), 'Duplicate entry')) {
                return null; // Already queued for this identity - idempotent no-op.
            }
            throw $e;
        }
    }

    /**
     * Take exclusive ownership of a pending row.
     *
     * The `status = 'pending'` predicate is what makes overlapping worker runs
     * safe: the second run's UPDATE matches zero rows and it moves on. Without
     * it two schedulers firing at once would each deliver the same email.
     *
     * @return bool True when this caller now owns the row.
     */
    public function tryClaimPending(int $id): bool
    {
        // The status flip is what makes this exclusive. An earlier version only
        // set `stage`, leaving the row's status as 'pending' - so the WHERE
        // predicate still matched on a second call and BOTH workers believed
        // they owned the row, delivering the notification twice. `status` must
        // move to 'sending' for the claim to mean anything.
        $stmt = $this->db->query(
            "UPDATE notification_logs
             SET status = 'sending', stage = 'sending', updated_at = NOW()
             WHERE id = ? AND status = 'pending'",
            'i',
            [$id]
        );
        $owned = $stmt->affected_rows === 1;
        $stmt->close();
        return $owned;
    }

    /**
     * Put a row back in the queue for a later attempt.
     *
     * Used for backoff on a transient provider failure, so an SMTP outage
     * becomes a slow drip rather than a hot loop against a dead server.
     */
    public function reschedule(int $id, string $nextAttemptAt, string $reason = ''): void
    {
        $stmt = $this->db->query(
            "UPDATE notification_logs
             SET status = 'retrying',
                 stage = 'queued',
                 scheduled_at = ?,
                 failure_reason = ?,
                 updated_at = NOW()
             WHERE id = ?",
            'ssi',
            [$nextAttemptAt, $reason !== '' ? $reason : null, $id]
        );
        $stmt->close();
    }

    /**
     * Decode the payload captured at dispatch time.
     *
     * @return array<string,mixed>
     */
    public static function decodePayload(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Rows the worker should try to deliver now, oldest first.
     *
     * Only rows scheduled at or before now: event notifications are queued with
     * the current timestamp, and a scheduled job may queue into the future.
     *
     * @param  array<int,string>|null $types Restrict to these notification_types.
     * @return array<int,array>
     */
    public function findPendingBatch(int $limit = 50, ?array $types = null): array
    {
        $limit = max(1, min(500, $limit));

        if ($types !== null && $types !== []) {
            $ph = implode(',', array_fill(0, count($types), '?'));
            $types = array_values($types);
            return $this->db->fetchAll(
                "SELECT * FROM notification_logs
                 WHERE status = 'pending'
                   AND (scheduled_at IS NULL OR scheduled_at <= NOW())
                   AND notification_type IN ({$ph})
                 ORDER BY id ASC
                 LIMIT {$limit}",
                str_repeat('s', count($types)),
                $types
            );
        }

        return $this->db->fetchAll(
            "SELECT * FROM notification_logs
             WHERE status = 'pending'
               AND (scheduled_at IS NULL OR scheduled_at <= NOW())
             ORDER BY id ASC
             LIMIT {$limit}"
        );
    }

    /**
     * Pending rows old enough that the worker that claimed them must have died.
     *
     * @return array<int,array>
     */
    public function findStalePending(int $minutes): array
    {
        // 'sending' is included deliberately: that is the status a row is left
        // in when the worker process dies mid-delivery. Excluding it would make
        // a crashed delivery invisible to recovery - and it can never be retried
        // automatically either, because findPendingBatch() only returns
        // 'pending'.
        return $this->db->fetchAll(
            "SELECT * FROM notification_logs
             WHERE status IN ('pending', 'sending')
               AND (scheduled_at IS NULL OR scheduled_at < DATE_SUB(NOW(), INTERVAL ? MINUTE))
             ORDER BY id ASC",
            'i',
            [$minutes]
        );
    }

    public function markSent(int $id, ?string $providerMessageId = null): void
    {
        $stmt = $this->db->query(
            "UPDATE notification_logs
             SET status = 'sent', sent_at = NOW(), attempts = attempts + 1,
                 provider_message_id = COALESCE(?, provider_message_id), failure_reason = NULL
             WHERE id = ?",
            'si',
            [$providerMessageId, $id]
        );
        $stmt->close();
    }

    public function markFailed(int $id, string $reason, bool $retryable): void
    {
        $status = $retryable ? 'retrying' : 'failed';
        $stmt = $this->db->query(
            "UPDATE notification_logs
             SET status = ?, failure_reason = ?, attempts = attempts + 1
             WHERE id = ?",
            'ssi',
            [$status, substr($reason, 0, 490), $id]
        );
        $stmt->close();
    }

    public function markSkipped(int $id, string $reason): void
    {
        $stmt = $this->db->query(
            "UPDATE notification_logs SET status = 'skipped', failure_reason = ? WHERE id = ?",
            'si',
            [substr($reason, 0, 490), $id]
        );
        $stmt->close();
    }

    /** Mark a retry attempt back to pending (picked up again now). */
    public function markRetrying(int $id): void
    {
        $stmt = $this->db->query(
            "UPDATE notification_logs SET status = 'pending', scheduled_at = NOW() WHERE id = ?",
            'i',
            [$id]
        );
        $stmt->close();
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM notification_logs WHERE id = ?", 'i', [$id]);
    }

    public function findByUserAndDate(int $userId, string $date): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM notification_logs WHERE user_id = ? AND business_date = ?
             ORDER BY created_at ASC",
            'is',
            [$userId, $date]
        );
    }

    public function statsForDate(string $date): array
    {
        return $this->db->fetchAll(
            "SELECT channel, stage, status, COUNT(*) AS cnt
             FROM notification_logs WHERE business_date = ?
             GROUP BY channel, stage, status",
            's',
            [$date]
        );
    }

    public function countSmsAttempts(int $userId, string $date): int
    {
        // Cost control: rows where at least one real send attempt happened.
        // 'sending' is included so an in-flight delivery still counts against
        // the cap; a row being delivered right now has already cost money.
        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM notification_logs
             WHERE user_id = ? AND business_date = ? AND channel = 'sms'
               AND attempts > 0 AND status IN ('sent', 'retrying', 'pending', 'sending')",
            'is',
            [$userId, $date]
        );
    }

    public function findRetryable(string $stage, string $date, int $maxAttempts): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM notification_logs
             WHERE channel = 'sms' AND stage = ? AND business_date = ?
               AND status = 'retrying' AND attempts < ?",
            'ssi',
            [$stage, $date, $maxAttempts]
        );
    }

    public function findFor(int $userId, string $date, string $channel, string $stage): ?array
    {
        return $this->db->fetchOne(
            "SELECT id, status, failure_reason FROM notification_logs
             WHERE user_id = ? AND business_date = ? AND channel = ? AND stage = ?
             LIMIT 1",
            'isss',
            [$userId, $date, $channel, $stage]
        );
    }

    public function reapStalePending(int $minutes): int
    {
        $stmt = $this->db->query(
            // The `attempts > 0` filter this used to carry was inert: attempts
            // was never incremented, so the reap matched nothing and stranded
            // rows survived forever. Age alone is the right signal.
            //
            // 'sending' is matched alongside 'pending' because that is the state
            // a row is stranded in when the worker dies mid-delivery.
            "UPDATE notification_logs
             SET status = 'failed', failure_reason = 'Stale pending row (process died mid-send)'
             WHERE status IN ('pending', 'sending')
               AND (scheduled_at IS NULL OR scheduled_at < DATE_SUB(NOW(), INTERVAL ? MINUTE))",
            'i',
            [$minutes]
        );
        $count = $stmt->affected_rows;
        $stmt->close();
        return $count;
    }
}

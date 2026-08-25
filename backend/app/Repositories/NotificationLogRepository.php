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
        try {
            return $this->db->insert('notification_logs', [
                'user_id'           => $userId,
                'employee_id'       => $employeeId,
                'notification_type' => $type,
                'channel'           => $channel,
                'stage'             => $stage,
                'business_date'     => $businessDate,
                'status'            => 'pending',
                'attempts'          => 0,
                'scheduled_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (mysqli_sql_exception $e) {
            if ((int) ($e->getCode() ?? 0) === 1062 || str_contains($e->getMessage(), 'Duplicate entry')) {
                return null; // Already notified for this tuple - idempotent no-op.
            }
            throw $e;
        }
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
        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM notification_logs
             WHERE user_id = ? AND business_date = ? AND channel = 'sms'
               AND attempts > 0 AND status IN ('sent', 'retrying', 'pending')",
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
            "UPDATE notification_logs
             SET status = 'failed', failure_reason = 'Stale pending row (process died mid-send)'
             WHERE status = 'pending'
               AND attempts > 0
               AND scheduled_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            'i',
            [$minutes]
        );
        $count = $stmt->affected_rows;
        $stmt->close();
        return $count;
    }
}

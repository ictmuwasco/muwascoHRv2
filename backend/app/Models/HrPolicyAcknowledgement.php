<?php

declare(strict_types=1);

namespace App\Models;

/**
 * HrPolicyAcknowledgement — per-employee read receipts for a policy version
 * (migration 081). One row per (policy_document_id, user_id). The wording is
 * explicit and non-legal: "I confirm that I have accessed and read the
 * MUWASCO HR Policy & Procedures Manual." (HR-approved per version via
 * acknowledgement_message on the document row).
 */
class HrPolicyAcknowledgement extends BaseModel
{
    protected static string $table = 'hr_policy_acknowledgements';

    protected static array $fillable = [
        'policy_document_id', 'user_id', 'employee_id',
        'acknowledged_at', 'ip_address', 'user_agent',
    ];

    public static function findFor(int $documentId, int $userId): ?array
    {
        return \db()->fetchOne(
            "SELECT * FROM " . static::$table . "
              WHERE policy_document_id = ? AND user_id = ? LIMIT 1",
            'ii', [$documentId, $userId]
        );
    }

    /**
     * Compliance view for HR: who has acknowledged the given policy version.
     */
    public static function forDocument(int $documentId): array
    {
        return \db()->fetchAll(
            "SELECT a.id, a.user_id, a.employee_id, a.acknowledged_at, a.ip_address,
                    TRIM(CONCAT_WS(' ', u.first_name, u.last_name, u.surname)) AS user_name,
                    u.email
               FROM " . static::$table . " a
               JOIN users u ON u.id = a.user_id
              WHERE a.policy_document_id = ?
              ORDER BY a.acknowledged_at ASC",
            'i', [$documentId]
        );
    }

    /**
     * Upsert the acknowledgement (idempotent — re-acknowledging keeps the
     * FIRST receipt, matching "accessed and read" semantics).
     */
    public static function record(
        int $documentId,
        int $userId,
        ?int $employeeId,
        string $ip,
        string $userAgent
    ): bool {
        $existing = self::findFor($documentId, $userId);
        if ($existing) {
            return false; // already acknowledged — nothing to do
        }
        \db()->query(
            "INSERT INTO " . static::$table . "
                (policy_document_id, user_id, employee_id, acknowledged_at, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, NOW(), ?, ?, NOW())",
            'iiiss', [$documentId, $userId, $employeeId, $ip, mb_substr($userAgent, 0, 512)]
        );
        return true;
    }
}

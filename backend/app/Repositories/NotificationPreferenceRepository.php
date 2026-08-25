<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\Contracts\NotificationPreferenceRepositoryInterface;
use App\Helpers\Database;

/**
 * Notification Preference Repository - notification_preferences SQL.
 */
class NotificationPreferenceRepository implements NotificationPreferenceRepositoryInterface
{
    private \mysqli $conn;

    public function __construct()
    {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function findByUser(int $userId): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT user_id, push_enabled, sms_enabled, email_enabled, reminders_mandated
             FROM notification_preferences WHERE user_id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function save(int $userId, bool $pushEnabled, bool $smsEnabled): array
    {
        // reminders_mandated is organisation policy: preserved on update,
        // only writable by admins through dedicated tooling.
        $sql = "INSERT INTO notification_preferences (user_id, push_enabled, sms_enabled)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    push_enabled = VALUES(push_enabled),
                    sms_enabled  = VALUES(sms_enabled)";
        $stmt = $this->conn->prepare($sql);
        $push = $pushEnabled ? 1 : 0;
        $sms  = $smsEnabled ? 1 : 0;
        $stmt->bind_param('iii', $userId, $push, $sms);
        $stmt->execute();
        $stmt->close();

        return $this->findByUser($userId) ?? [
            'user_id'            => $userId,
            'push_enabled'       => $push,
            'sms_enabled'        => $sms,
            'email_enabled'      => 0,
            'reminders_mandated' => 0,
        ];
    }

    public function mapByUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $types = str_repeat('i', count($userIds));
        $stmt = $this->conn->prepare(
            "SELECT user_id, push_enabled, sms_enabled, email_enabled, reminders_mandated
             FROM notification_preferences WHERE user_id IN ($placeholders)"
        );
        $stmt->bind_param($types, ...$userIds);
        $stmt->execute();
        $map = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $map[(int) $row['user_id']] = $row;
        }
        $stmt->close();
        return $map;
    }
}

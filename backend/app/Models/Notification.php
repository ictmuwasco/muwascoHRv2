<?php

declare(strict_types=1);

namespace App\Models;


class Notification
{
    /**
     * Get notifications for a user.
     */
    public function getNotifications(int $userId, int $limit = 20): array
    {
        $conn = \App\Helpers\Database::getInstance()->getConnection();

        // Check if notifications table exists
        $tableCheck = $conn->query("SHOW TABLES LIKE 'notifications'");
        if ($tableCheck->num_rows === 0) {
            return [];
        }

        $stmt = $conn->prepare("
            SELECT id, title, message, type, is_read, created_at
            FROM notifications
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->bind_param("ii", $userId, $limit);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $result;
    }

    /**
     * Get unread notification count.
     */
    public function getUnreadCount(int $userId): int
    {
        $conn = \App\Helpers\Database::getInstance()->getConnection();

        $tableCheck = $conn->query("SHOW TABLES LIKE 'notifications'");
        if ($tableCheck->num_rows === 0) {
            return 0;
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) as cnt FROM notifications
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($result['cnt'] ?? 0);
    }

    /**
     * Mark a notification as read.
     */
    public function markAsRead(int $notificationId, int $userId): bool
    {
        $conn = \App\Helpers\Database::getInstance()->getConnection();
        $stmt = $conn->prepare("
            UPDATE notifications SET is_read = 1
            WHERE id = ? AND user_id = ?
        ");
        $stmt->bind_param("ii", $notificationId, $userId);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }

    /**
     * Mark all notifications as read for a user.
     */
    public function markAllAsRead(int $userId): bool
    {
        $conn = \App\Helpers\Database::getInstance()->getConnection();
        $stmt = $conn->prepare("
            UPDATE notifications SET is_read = 1
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->bind_param("i", $userId);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }
}

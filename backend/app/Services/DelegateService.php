<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;

/**
 * DelegateService
 *
 * Handles delegate assignment and temporary role/permission delegation
 * for leave applications.
 */
class DelegateService
{
    private \mysqli $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Assign a delegate for a leave application.
     */
    public function assignDelegate(int $applicationId, int $delegateEmpId, string $delegatedRole): void
    {
        $stmt = $this->db->prepare("
            UPDATE leave_applications
            SET delegate_emp_id = ?, delegate_role = ?
            WHERE id = ?
        ");
        $stmt->bind_param('isi', $delegateEmpId, $delegatedRole, $applicationId);
        $stmt->execute();
    }

    /**
     * Get delegate notification user IDs.
     */
    public function getDelegateUserIds(int $delegateEmpId): array
    {
        $stmt = $this->db->prepare("
            SELECT u.id FROM users u
            JOIN employees e ON e.employee_id = u.employee_id
            WHERE e.id = ?
        ");
        $stmt->bind_param('i', $delegateEmpId);
        $stmt->execute();
        $result = $stmt->get_result();
        $userIds = [];
        while ($row = $result->fetch_assoc()) {
            $userIds[] = (int) $row['id'];
        }
        return $userIds;
    }

    /**
     * Notify the delegate about the assignment.
     */
    public function notifyDelegate(int $applicationId, int $delegateEmpId, int $applicantUserId): void
    {
        $delegateUserIds = $this->getDelegateUserIds($delegateEmpId);
        if (empty($delegateUserIds)) {
            return;
        }

        // Get application info for the notification
        $stmt = $this->db->prepare("
            SELECT la.start_date, la.end_date, lt.name as leave_type_name,
                   e.first_name as applicant_first, e.last_name as applicant_last
            FROM leave_applications la
            JOIN leave_types lt ON la.leave_type_id = lt.id
            JOIN employees e ON la.employee_id = e.id
            WHERE la.id = ?
        ");
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $app = $stmt->get_result()->fetch_assoc();

        if (!$app) {
            return;
        }

        $title = 'Leave Delegation Assignment';
        $message = sprintf(
            '%s %s has assigned you as their delegate for %s from %s to %s.',
            $app['applicant_first'] ?? '',
            $app['applicant_last'] ?? '',
            $app['leave_type_name'] ?? 'Leave',
            date('M d, Y', strtotime($app['start_date'])),
            date('M d, Y', strtotime($app['end_date']))
        );

        foreach ($delegateUserIds as $userId) {
            $stmt = $this->db->prepare("
                INSERT INTO notifications (user_id, title, message, type, category, is_read, created_at)
                VALUES (?, ?, ?, 'delegate_assignment', 'leave', 0, NOW())
            ");
            $stmt->bind_param('iss', $userId, $title, $message);
            $stmt->execute();
        }
    }
}
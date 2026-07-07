<?php
/**
 * NotificationService.php
 * Core notification service for HR Management System
 * Handles creation, retrieval, and management of notifications
 */

class NotificationService {
    private $conn;

    public function __construct($conn) {
        $this->conn = $conn;
        $this->ensureTableExists();
    }

    /**
     * Ensure the notifications table exists with correct schema
     */
    private function ensureTableExists() {
        // Drop table if exists to ensure proper schema (development environment)
        $this->conn->query("DROP TABLE IF EXISTS notifications");
        
        $this->conn->query("
            CREATE TABLE notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                type VARCHAR(100) NOT NULL DEFAULT 'general',
                category VARCHAR(100) DEFAULT 'general',
                is_read TINYINT(1) DEFAULT 0,
                related_entity VARCHAR(100) DEFAULT NULL,
                related_id INT DEFAULT NULL,
                action_url VARCHAR(500) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user_read (user_id, is_read),
                INDEX idx_created (created_at),
                INDEX idx_type (type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Create a notification directly
     */
    public function createNotification($userId, $title, $message, $type = 'general', $category = 'general', $relatedEntity = null, $relatedId = null, $actionUrl = null) {
        $stmt = $this->conn->prepare("
            INSERT INTO notifications (user_id, title, message, type, category, is_read, related_entity, related_id, action_url, created_at)
            VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, NOW())
        ");
        
        if (!$stmt) {
            error_log("NotificationService: Prepare failed - " . $this->conn->error);
            return false;
        }

        $stmt->bind_param("isssssss", $userId, $title, $message, $type, $category, $relatedEntity, $relatedId, $actionUrl);
        
        if ($stmt->execute()) {
            return $this->conn->insert_id;
        }
        
        error_log("NotificationService: Execute failed - " . $stmt->error);
        return false;
    }

    /**
     * Trigger notification using template-style approach
     * This is the main method called from leave_management.php
     */
    public function triggerNotification($templateName, $data, $userIds) {
        if (empty($userIds)) {
            error_log("NotificationService: No user IDs provided for template '$templateName'");
            return 0;
        }

        $count = 0;
        
        // Build notification content based on template name
        $notification = $this->buildFromTemplate($templateName, $data);
        
        if (!$notification) {
            error_log("NotificationService: Failed to build notification for template '$templateName'");
            return 0;
        }

        foreach ($userIds as $userId) {
            if (!$userId) continue;
            
            $result = $this->createNotification(
                $userId,
                $notification['title'],
                $notification['message'],
                $notification['type'],
                $notification['category'],
                $data['related_entity'] ?? 'leave',
                $data['related_id'] ?? ($data['id'] ?? null),
                $notification['action_url']
            );
            
            if ($result) {
                $count++;
                error_log("NotificationService: Created notification ID $result for user $userId");
            }
        }

        return $count;
    }

    /**
     * Build notification content from template name and data
     */
    private function buildFromTemplate($templateName, $data) {
        $employeeName = $data['employee_name'] ?? 'An employee';
        $leaveType    = $data['leave_type'] ?? 'Leave';
        $startDate    = isset($data['start_date']) ? date('M d, Y', strtotime($data['start_date'])) : '';
        $endDate      = isset($data['end_date'])   ? date('M d, Y', strtotime($data['end_date']))   : '';
        $days         = $data['days'] ?? 0;
        $status       = $data['status'] ?? '';
        $leaveId      = $data['id'] ?? null;

        $statusLabels = [
            'pending_subsection_head'   => 'Pending Subsection Head Approval',
            'pending_section_head'      => 'Pending Section Head Approval',
            'pending_dept_head'         => 'Pending Department Head Approval',
            'pending_managing_director' => 'Pending Managing Director Approval',
            'pending_hr'                => 'Pending HR Approval',
            'approved'                  => 'Approved',
            'rejected'                  => 'Rejected',
        ];
        $statusLabel = $statusLabels[$status] ?? ucfirst(str_replace('_', ' ', $status));

        switch ($templateName) {
            // Approver receives this when a new leave is submitted
            case 'leave_application':
                return [
                    'title'      => "🗓️ New Leave Request from $employeeName",
                    'message'    => "$employeeName has applied for $leaveType leave from $startDate to $endDate ($days day" . ($days != 1 ? 's' : '') . "). Your approval is required.",
                    'type'       => 'leave_application',
                    'category'   => 'leave',
                    'action_url' => 'manage.php',
                ];

            // Employee receives this as confirmation after submitting
            case 'leave_applied':
                return [
                    'title'      => "✅ Leave Application Submitted",
                    'message'    => "Your $leaveType leave from $startDate to $endDate ($days day" . ($days != 1 ? 's' : '') . ") has been submitted successfully. Status: $statusLabel.",
                    'type'       => 'leave_applied',
                    'category'   => 'leave',
                    'action_url' => 'leave_management.php',
                ];

            // Employee receives this when their leave moves to next approver
            case 'leave_status_update':
                return [
                    'title'      => "📋 Leave Application Update",
                    'message'    => "Your $leaveType leave application has been updated. Current status: $statusLabel.",
                    'type'       => 'leave_status_update',
                    'category'   => 'leave',
                    'action_url' => 'leave_management.php',
                ];

            // Next approver gets this when previous level approves
            case 'leave_pending_approval':
                return [
                    'title'      => "⏳ Leave Awaiting Your Approval",
                    'message'    => "$employeeName's $leaveType leave ($startDate to $endDate, $days day" . ($days != 1 ? 's' : '') . ") has been forwarded to you for approval.",
                    'type'       => 'leave_pending_approval',
                    'category'   => 'leave',
                    'action_url' => 'manage.php',
                ];

            // Employee is notified their leave was approved
            case 'leave_approved':
                return [
                    'title'      => "🎉 Leave Application Approved!",
                    'message'    => "Your $leaveType leave from $startDate to $endDate ($days day" . ($days != 1 ? 's' : '') . ") has been approved.",
                    'type'       => 'leave_approved',
                    'category'   => 'leave',
                    'action_url' => 'leave_management.php',
                ];

            // Employee is notified their leave was rejected
            case 'leave_rejected':
                $reason = $data['reason'] ?? '';
                return [
                    'title'      => "❌ Leave Application Not Approved",
                    'message'    => "Your $leaveType leave from $startDate to $endDate has not been approved." . ($reason ? " Reason: $reason" : ''),
                    'type'       => 'leave_rejected',
                    'category'   => 'leave',
                    'action_url' => 'leave_management.php',
                ];

            // HR receives notification when leave fully approved
            case 'leave_fully_approved':
                return [
                    'title'      => "✅ Leave Fully Approved – For Your Records",
                    'message'    => "$employeeName's $leaveType leave from $startDate to $endDate ($days day" . ($days != 1 ? 's' : '') . ") has been fully approved through the approval chain.",
                    'type'       => 'leave_fully_approved',
                    'category'   => 'leave',
                    'action_url' => 'manage.php',
                ];

            default:
                // Generic fallback
                return [
                    'title'      => "🔔 Leave Notification",
                    'message'    => "$employeeName applied for $leaveType leave from $startDate to $endDate ($days day" . ($days != 1 ? 's' : '') . "). Status: $statusLabel.",
                    'type'       => 'leave_general',
                    'category'   => 'leave',
                    'action_url' => 'leave_management.php',
                ];
        }
    }

    /**
     * Get unread notification count for a user
     */
    public function getUnreadCount($userId) {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as count FROM notifications 
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return (int)($result['count'] ?? 0);
    }

    /**
     * Get notifications for a user (paginated)
     */
    public function getNotifications($userId, $limit = 20, $offset = 0, $unreadOnly = false) {
        $whereExtra = $unreadOnly ? " AND is_read = 0" : "";
        
        $stmt = $this->conn->prepare("
            SELECT * FROM notifications 
            WHERE user_id = ? $whereExtra
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param("iii", $userId, $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $notifications = [];
        while ($row = $result->fetch_assoc()) {
            $notifications[] = $row;
        }
        return $notifications;
    }

    /**
     * Mark a single notification as read
     */
    public function markAsRead($notificationId, $userId) {
        $stmt = $this->conn->prepare("
            UPDATE notifications SET is_read = 1, updated_at = NOW()
            WHERE id = ? AND user_id = ?
        ");
        $stmt->bind_param("ii", $notificationId, $userId);
        return $stmt->execute();
    }

    /**
     * Mark all notifications as read for a user
     */
    public function markAllAsRead($userId) {
        $stmt = $this->conn->prepare("
            UPDATE notifications SET is_read = 1, updated_at = NOW()
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        return $stmt->affected_rows;
    }

    /**
     * Delete old read notifications (cleanup)
     */
    public function cleanup($userId, $daysOld = 30) {
        $stmt = $this->conn->prepare("
            DELETE FROM notifications 
            WHERE user_id = ? AND is_read = 1 
            AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->bind_param("ii", $userId, $daysOld);
        $stmt->execute();
        return $stmt->affected_rows;
    }

    /**
     * Send approval progression notification
     * Called from manage.php when each approver acts
     */
    public function notifyApprovalProgression($conn, $leaveId, $newStatus, $employeeData, $leaveData) {
        // Notify the applicant about status change
        $applicantUserId = $employeeData['user_id'] ?? null;
        
        if ($applicantUserId) {
            $notifData = [
                'id'            => $leaveId,
                'employee_name' => ($employeeData['first_name'] ?? '') . ' ' . ($employeeData['last_name'] ?? ''),
                'leave_type'    => $leaveData['leave_type_name'] ?? 'Leave',
                'start_date'    => $leaveData['start_date'] ?? '',
                'end_date'      => $leaveData['end_date'] ?? '',
                'days'          => $leaveData['days_requested'] ?? 0,
                'status'        => $newStatus,
                'related_entity'=> 'leave',
                'related_id'    => $leaveId,
            ];

            if ($newStatus === 'approved') {
                $this->triggerNotification('leave_approved', $notifData, [$applicantUserId]);
            } elseif ($newStatus === 'rejected') {
                $notifData['reason'] = $leaveData['rejection_reason'] ?? '';
                $this->triggerNotification('leave_rejected', $notifData, [$applicantUserId]);
            } else {
                $this->triggerNotification('leave_status_update', $notifData, [$applicantUserId]);
            }
        }

        // Notify the next approver in chain
        $nextApproverUserId = $this->getNextApproverUserId($conn, $newStatus, $leaveData);
        
        if ($nextApproverUserId && $newStatus !== 'approved' && $newStatus !== 'rejected') {
            $pendingData = [
                'id'            => $leaveId,
                'employee_name' => ($employeeData['first_name'] ?? '') . ' ' . ($employeeData['last_name'] ?? ''),
                'leave_type'    => $leaveData['leave_type_name'] ?? 'Leave',
                'start_date'    => $leaveData['start_date'] ?? '',
                'end_date'      => $leaveData['end_date'] ?? '',
                'days'          => $leaveData['days_requested'] ?? 0,
                'status'        => $newStatus,
                'related_entity'=> 'leave',
                'related_id'    => $leaveId,
            ];
            $this->triggerNotification('leave_pending_approval', $pendingData, [$nextApproverUserId]);
        }

        // Notify HR when fully approved
        if ($newStatus === 'approved') {
            $hrResult = $conn->query("SELECT id FROM users WHERE role = 'hr_manager' LIMIT 1");
            if ($hrRow = $hrResult->fetch_assoc()) {
                $hrData = [
                    'id'            => $leaveId,
                    'employee_name' => ($employeeData['first_name'] ?? '') . ' ' . ($employeeData['last_name'] ?? ''),
                    'leave_type'    => $leaveData['leave_type_name'] ?? 'Leave',
                    'start_date'    => $leaveData['start_date'] ?? '',
                    'end_date'      => $leaveData['end_date'] ?? '',
                    'days'          => $leaveData['days_requested'] ?? 0,
                    'status'        => $newStatus,
                    'related_entity'=> 'leave',
                    'related_id'    => $leaveId,
                ];
                $this->triggerNotification('leave_fully_approved', $hrData, [$hrRow['id']]);
            }
        }
    }

    /**
     * Get the user ID of the next approver based on status
     */
    private function getNextApproverUserId($conn, $status, $leaveData) {
        switch ($status) {
            case 'pending_subsection_head':
                $subsectionId = $leaveData['subsection_id'] ?? null;
                if ($subsectionId) {
                    $res = $conn->prepare("
                        SELECT u.id FROM users u 
                        JOIN employees e ON u.employee_id = e.employee_id 
                        WHERE u.role = 'sub_section_head' AND e.subsection_id = ? LIMIT 1
                    ");
                    $res->bind_param("i", $subsectionId);
                    $res->execute();
                    $row = $res->get_result()->fetch_assoc();
                    return $row['id'] ?? null;
                }
                break;

            case 'pending_section_head':
                $sectionId = $leaveData['section_id'] ?? null;
                if ($sectionId) {
                    $res = $conn->prepare("
                        SELECT u.id FROM users u 
                        JOIN employees e ON u.employee_id = e.employee_id 
                        WHERE u.role = 'section_head' AND e.section_id = ? LIMIT 1
                    ");
                    $res->bind_param("i", $sectionId);
                    $res->execute();
                    $row = $res->get_result()->fetch_assoc();
                    return $row['id'] ?? null;
                }
                break;

            case 'pending_dept_head':
                $deptId = $leaveData['department_id'] ?? null;
                if ($deptId) {
                    $res = $conn->prepare("
                        SELECT u.id FROM users u 
                        JOIN employees e ON u.employee_id = e.employee_id 
                        WHERE u.role = 'dept_head' AND e.department_id = ? LIMIT 1
                    ");
                    $res->bind_param("i", $deptId);
                    $res->execute();
                    $row = $res->get_result()->fetch_assoc();
                    return $row['id'] ?? null;
                }
                break;

            case 'pending_managing_director':
                $res = $conn->query("SELECT id FROM users WHERE role = 'managing_director' LIMIT 1");
                $row = $res->fetch_assoc();
                return $row['id'] ?? null;

            case 'pending_hr':
                $res = $conn->query("SELECT id FROM users WHERE role = 'hr_manager' LIMIT 1");
                $row = $res->fetch_assoc();
                return $row['id'] ?? null;
        }

        return null;
    }
}
<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * Dashboard Controller - Handles dashboard data and statistics.
 * 
 * Provides operational widgets and analytics for the dashboard.
 */
class DashboardController extends BaseController
{
    /**
     * Get all dashboard statistics.
     */
    public function statsAction(): void
    {
        $this->requirePermission('dashboard', 'view');
        
        $db = \db();
        $data = [
            'employee_count' => $this->getEmployeeCount(),
            'attendance_today' => $this->getTodayAttendance(),
            'pending_leaves' => $this->getPendingLeavesCount(),
            'open_complaints' => $this->getOpenComplaintsCount(),
            'pending_appraisals' => $this->getPendingAppraisalsCount(),
            'active_users' => $this->getActiveUsersCount(),
            'department_stats' => $this->getDepartmentStats(),
            'attendance_rate' => $this->getAttendanceRate(),
        ];

        $this->success($data);
    }

    /**
     * Get today's attendance records.
     */
    public function attendanceTodayAction(): void
    {
        $this->requirePermission('attendance', 'view');
        
        $db = \db();
        $today = date('Y-m-d');
        
        $records = $db->fetchAll(
            "SELECT a.*, e.first_name, e.last_name, e.department, o.name as office_name
             FROM attendance a
             JOIN employees e ON a.employee_id = e.employee_id
             LEFT JOIN offices o ON a.office_id = o.id
             WHERE DATE(a.clock_in) = ?
             ORDER BY a.clock_in DESC
             LIMIT 50",
            's',
            [$today]
        );

        $stats = [
            'total' => count($records),
            'clocked_in' => count(array_filter($records, fn($r) => $r['status'] === 'clocked_in')),
            'clocked_out' => count(array_filter($records, fn($r) => $r['status'] === 'clocked_out')),
            'late' => count(array_filter($records, fn($r) => $r['is_late'] == 1)),
        ];

        $this->success([
            'records' => $records,
            'stats' => $stats,
        ]);
    }

    /**
     * Get pending leave requests.
     */
    public function pendingLeavesAction(): void
    {
        $this->requirePermission('leave', 'view');
        
        $db = \db();
        $leaves = $db->fetchAll(
            "SELECT l.*, e.first_name, e.last_name, e.department, lt.name as leave_type_name
             FROM leave_requests l
             JOIN employees e ON l.employee_id = e.id
             JOIN leave_types lt ON l.leave_type_id = lt.id
             WHERE l.status = 'pending'
             ORDER BY l.created_at DESC
             LIMIT 20"
        );

        $this->success($leaves);
    }

    /**
     * Get recent complaints.
     */
    public function recentComplaintsAction(): void
    {
        $this->requirePermission('complaints', 'view');
        
        $db = \db();
        $complaints = $db->fetchAll(
            "SELECT c.*, e.first_name, e.last_name, e.department, cc.name as category_name
             FROM complaints c
             JOIN employees e ON c.employee_id = e.id
             LEFT JOIN complaint_categories cc ON c.category_id = cc.id
             ORDER BY c.created_at DESC
             LIMIT 10"
        );

        $this->success($complaints);
    }

    /**
     * Get employee count by department.
     */
    public function employeeCountAction(): void
    {
        $this->requirePermission('employees', 'view');
        
        $data = $this->getDepartmentStats();
        $this->success([
            'total' => $this->getEmployeeCount(),
            'by_department' => $data,
        ]);
    }

    /**
     * Get recent notifications for the current user.
     */
    public function notificationsAction(): void
    {
        $userId = $this->getUserId();
        $notificationService = \App\Services\NotificationService::getInstance();
        
        $notifications = $notificationService->getUnreadNotifications($userId, 10);
        $unreadCount = $notificationService->getUnreadCount($userId);

        $this->success([
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Get total employee count.
     */
    private function getEmployeeCount(): int
    {
        $db = \db();
        return (int) $db->fetchValue(
            "SELECT COUNT(*) FROM employees WHERE employee_status = 'active' OR employee_status IS NULL"
        );
    }

    /**
     * Get today's attendance count.
     */
    private function getTodayAttendance(): array
    {
        $db = \db();
        $today = date('Y-m-d');
        
        $total = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM attendance WHERE DATE(clock_in) = ?",
            's',
            [$today]
        );
        
        $clockedIn = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM attendance WHERE DATE(clock_in) = ? AND status = 'clocked_in'",
            's',
            [$today]
        );

        return [
            'total' => $total,
            'clocked_in' => $clockedIn,
            'clocked_out' => $total - $clockedIn,
        ];
    }

    /**
     * Get pending leaves count.
     */
    private function getPendingLeavesCount(): int
    {
        $db = \db();
        return (int) $db->fetchValue(
            "SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'"
        );
    }

    /**
     * Get open complaints count.
     */
    private function getOpenComplaintsCount(): int
    {
        $db = \db();
        return (int) $db->fetchValue(
            "SELECT COUNT(*) FROM complaints WHERE status NOT IN ('resolved', 'closed')"
        );
    }

    /**
     * Get pending appraisals count.
     */
    private function getPendingAppraisalsCount(): int
    {
        $db = \db();
        return (int) $db->fetchValue(
            "SELECT COUNT(*) FROM employee_appraisals WHERE status = 'pending'"
        );
    }

    /**
     * Get active users count.
     */
    private function getActiveUsersCount(): int
    {
        $db = \db();
        return (int) $db->fetchValue(
            "SELECT COUNT(*) FROM users WHERE is_active = 1"
        );
    }

    /**
     * Get employee statistics by department.
     */
    private function getDepartmentStats(): array
    {
        $db = \db();
        return $db->fetchAll(
            "SELECT department, COUNT(*) as count 
             FROM employees 
             WHERE (employee_status = 'active' OR employee_status IS NULL)
             GROUP BY department 
             ORDER BY count DESC"
        );
    }

    /**
     * Get today's attendance rate.
     */
    private function getAttendanceRate(): float
    {
        $totalEmployees = $this->getEmployeeCount();
        if ($totalEmployees === 0) {
            return 0.0;
        }

        $today = date('Y-m-d');
        $db = \db();
        $attended = (int) $db->fetchValue(
            "SELECT COUNT(DISTINCT employee_id) FROM attendance WHERE DATE(clock_in) = ?",
            's',
            [$today]
        );

        return round(($attended / $totalEmployees) * 100, 2);
    }
}
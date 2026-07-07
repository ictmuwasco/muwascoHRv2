<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LeaveRequest;
use App\Models\Employee;

/**
 * Leave Service - Business logic for leave management.
 * 
 * Handles leave requests, approvals, rejections, and balance tracking.
 */
class LeaveService
{
    private static ?LeaveService $instance = null;
    private AuditService $audit;
    private NotificationService $notifier;

    private function __construct()
    {
        $this->audit = AuditService::getInstance();
        $this->notifier = NotificationService::getInstance();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Submit a new leave request.
     */
    public function create(array $data): array
    {
        $db = \db();

        // Validate overlapping leaves
        $overlap = $db->fetchOne(
            "SELECT id FROM leave_requests 
             WHERE employee_id = ? 
             AND status IN ('pending', 'approved')
             AND ((start_date BETWEEN ? AND ?) OR (end_date BETWEEN ? AND ?) OR (? BETWEEN start_date AND end_date))
             AND id != ?",
            'isssssi',
            [
                (int) $data['employee_id'],
                $data['start_date'], $data['end_date'],
                $data['start_date'], $data['end_date'],
                $data['start_date'], 0
            ]
        );

        if ($overlap) {
            throw new \RuntimeException('An overlapping leave request already exists for this period.');
        }

        $leaveId = $db->insert('leave_requests', [
            'employee_id' => (int) $data['employee_id'],
            'leave_type_id' => (int) $data['leave_type_id'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'reason' => $data['reason'] ?? '',
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Notify HR managers
        $employee = Employee::find((int) $data['employee_id']);
        if ($employee) {
            $this->notifier->notifyLeaveRequest(
                (int) $data['employee_id'],
                $employee['first_name'] . ' ' . $employee['last_name'],
                $data['leave_type_id'],
                $data['start_date'],
                $data['end_date']
            );
        }

        $this->audit->logCreate('leave_requests', $leaveId, $data, "New leave request submitted");

        return ['success' => true, 'id' => $leaveId, 'message' => 'Leave request submitted successfully.'];
    }

    /**
     * Approve a leave request.
     */
    public function approve(int $leaveId, int $approvedBy, ?string $comment = null): array
    {
        $db = \db();
        $leave = LeaveRequest::find($leaveId);
        if (!$leave) throw new \RuntimeException('Leave request not found');

        $db->update('leave_requests', [
            'status' => 'approved',
            'approved_by' => $approvedBy,
            'approved_at' => date('Y-m-d H:i:s'),
            'rejection_reason' => $comment,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', 'i', [$leaveId]);

        $this->audit->logApproval('leave_requests', $leaveId, 'approved', $comment ?? '');
        
        $employee = Employee::find((int) $leave['employee_id']);
        if ($employee) {
            $this->notifier->notifyLeaveApproval((int) $leave['employee_id'], $employee['first_name'], 'approved');
        }

        return ['success' => true, 'message' => 'Leave request approved.'];
    }

    /**
     * Reject a leave request.
     */
    public function reject(int $leaveId, string $reason): array
    {
        $db = \db();
        $leave = LeaveRequest::find($leaveId);
        if (!$leave) throw new \RuntimeException('Leave request not found');

        $db->update('leave_requests', [
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', 'i', [$leaveId]);

        $this->audit->logApproval('leave_requests', $leaveId, 'rejected', $reason);

        $employee = Employee::find((int) $leave['employee_id']);
        if ($employee) {
            $this->notifier->notifyLeaveApproval((int) $leave['employee_id'], $employee['first_name'], 'rejected');
        }

        return ['success' => true, 'message' => 'Leave request rejected.'];
    }

    /**
     * Get leave types.
     */
    public function getLeaveTypes(): array
    {
        $db = \db();
        return $db->fetchAll("SELECT * FROM leave_types ORDER BY name");
    }

    /**
     * Get leave balances for an employee.
     */
    public function getBalances(int $employeeId): array
    {
        $db = \db();
        $leaveTypes = $this->getLeaveTypes();
        $balances = [];

        foreach ($leaveTypes as $type) {
            // Calculate used days
            $used = (int) $db->fetchValue(
                "SELECT COALESCE(SUM(DATEDIFF(end_date, start_date) + 1), 0)
                 FROM leave_requests 
                 WHERE employee_id = ? AND leave_type_id = ? AND status = 'approved'
                 AND YEAR(created_at) = YEAR(CURDATE())",
                'ii',
                [$employeeId, (int) $type['id']]
            );

            $balances[] = [
                'type_id' => $type['id'],
                'type_name' => $type['name'],
                'days_allowed' => (int) $type['days_allowed'],
                'days_used' => $used,
                'days_remaining' => max(0, (int) $type['days_allowed'] - $used),
            ];
        }

        return $balances;
    }

    /**
     * List leave requests with filters and pagination.
     */
    public function list(array $params): array
    {
        $filters = [];
        if (!empty($params['status'])) $filters['status'] = $params['status'];
        if (!empty($params['employee_id'])) $filters['employee_id'] = $params['employee_id'];
        if (!empty($params['date_from'])) $filters['date_from'] = $params['date_from'];
        if (!empty($params['date_to'])) $filters['date_to'] = $params['date_to'];

        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 20)));

        return LeaveRequest::getAllWithDetails($filters, $page, $perPage);
    }

    private function __clone(): void {}
    public function __wakeup(): void
    {
        throw new \RuntimeException('Cannot unserialize singleton');
    }
}
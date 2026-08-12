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
     * Check if the given user can act as a backup approver for this application.
     *
     * A delegate may approve at a stage only when BOTH are true:
     *   (a) The natural approver at the current pending stage IS the applicant
     *       (i.e. self-application — the chain supervisor cannot approve themselves).
     *   (b) The delegate is in the same scope the natural approver would have had.
     *
     * For all other stages (where the natural approver is someone else),
     * this returns false — the delegate does not get to "double-approve" later stages.
     */
    public function canDelegateApprove(int $applicationId, int $delegateUserId): bool
    {
        // Look up the application and its currently logged-in delegate.
        $stmt = $this->db->prepare("
            SELECT la.employee_id        AS applicant_emp_id,
                   la.status             AS status,
                   la.delegate_emp_id    AS delegate_emp_id,
                   e.subsection_id       AS applicant_subsection_id,
                   e.section_id          AS applicant_section_id,
                   e.department_id       AS applicant_department_id,
                   e.first_name          AS applicant_first_name,
                   e.last_name           AS applicant_last_name
            FROM leave_applications la
            JOIN employees e ON la.employee_id = e.id
            WHERE la.id = ?
        ");
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $app = $stmt->get_result()->fetch_assoc();
        if (!$app || empty($app['delegate_emp_id'])) {
            return false;
        }

        // Resolve the delegate user → delegate employee.
        $delStmt = $this->db->prepare("
            SELECT e.id, e.subsection_id, e.section_id, e.department_id
            FROM employees e
            JOIN users u ON u.employee_id = e.employee_id
            WHERE u.id = ?
        ");
        $delStmt->bind_param('i', $delegateUserId);
        $delStmt->execute();
        $delegate = $delStmt->get_result()->fetch_assoc();
        if (!$delegate) {
            return false;
        }

        // The application's delegate_emp_id must match the delegate's employee id.
        if ((int) $delegate['id'] !== (int) $app['delegate_emp_id']) {
            return false;
        }

        // Determine the natural approver slot for the current pending status.
        // Only stages where the natural approver is the APPLICANT qualify for backup.
        $roleAtStage = match ($app['status']) {
            'pending_subsection_head' => 'sub_section_head',
            'pending_section_head'    => 'section_head',
            'pending_dept_head'       => 'dept_head',
            // pending_managing_director / pending_bod_chair / pending_hr — applicant
            // is not the natural approver at these stages; do not allow delegate.
            default                   => null,
        };
        if ($roleAtStage === null) {
            return false;
        }

        // Check whether the applicant themselves holds that role at that scope.
        $applicantRole = $this->getEmployeeRole((int) $app['applicant_emp_id']);
        if ($applicantRole !== $roleAtStage) {
            // Natural approver is someone else — delegate should NOT intervene.
            return false;
        }

        // Delegate must be in scope of the role/stage.
        return match ($roleAtStage) {
            'sub_section_head' => !empty($app['applicant_subsection_id'])
                                  && (int) $delegate['subsection_id'] === (int) $app['applicant_subsection_id']
                                  && (int) $delegate['id'] !== (int) $app['applicant_emp_id'],
            'section_head'     => !empty($app['applicant_section_id'])
                                  && (int) $delegate['section_id'] === (int) $app['applicant_section_id']
                                  && (int) $delegate['id'] !== (int) $app['applicant_emp_id'],
            'dept_head'        => !empty($app['applicant_department_id'])
                                  && (int) $delegate['department_id'] === (int) $app['applicant_department_id']
                                  && (int) $delegate['id'] !== (int) $app['applicant_emp_id'],
            default            => false,
        };
    }

    /**
     * Get an employee's role from the users table.
     */
    private function getEmployeeRole(int $employeeId): string
    {
        $stmt = $this->db->prepare("
            SELECT u.role
            FROM users u
            JOIN employees e ON e.employee_id = u.employee_id
            WHERE e.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row['role'] ?? '';
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
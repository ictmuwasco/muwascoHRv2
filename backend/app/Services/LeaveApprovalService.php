<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use App\Helpers\Auth;
use App\Services\Leave\InvalidLeaveTransitionException;
use App\Services\Leave\LeaveTypePolicy;
use App\Services\Leave\LeaveWorkflowRules;

/**
 * LeaveApprovalService
 *
 * Handles the approval workflow for leave applications.
 * Manages the multi-stage approval hierarchy:
 *   subsection_head → section_head → dept_head → managing_director → bod_chair → hr
 *
 * This service was referenced by LeaveController but did not exist,
 * causing a fatal error on controller instantiation.  It is now
 * implemented with the full approval / rejection / cancellation
 * lifecycle, reusing the existing LeaveWorkflowService for hierarchy
 * resolution and LeaveApplicationService for balance updates.
 */
class LeaveApprovalService
{
    private \mysqli $db;
    private LeaveWorkflowService $workflowService;
    private LeaveCalculationService $calculationService;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->workflowService = new LeaveWorkflowService();
        $this->calculationService = new LeaveCalculationService();
    }

    /**
     * List leave applications for the current approver, grouped by
     * status (pending / approved / rejected).
     *
     * @param int $userId
     * @param array $pagination
     * @return array
     */
    public function listForApprover(int $userId, array $pagination): array
    {
        $limit = (int) ($pagination['limit'] ?? 15);

        // Get the current user's employee record and role
        $stmt = $this->db->prepare("
            SELECT e.*, u.role as user_role
            FROM employees e
            JOIN users u ON u.employee_id = e.employee_id
            WHERE u.id = ? AND e.employee_status = 'active'
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $currentUser = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$currentUser) {
            return ['success' => false, 'message' => 'User not found', 'data' => ['counts' => ['pending' => 0, 'approved' => 0, 'rejected' => 0], 'role' => '']];
        }

        $role = $currentUser['user_role'] ?? 'officer';
        $employeeId = $this->getEmployeeIdFromUserId($userId);
        if (!$employeeId) {
            return ['success' => false, 'message' => 'User not found', 'data' => ['counts' => ['pending' => 0, 'approved' => 0, 'rejected' => 0], 'role' => $role]];
        }

        $pendingOffset  = (int) ($pagination['pending_offset'] ?? 0);
        $approvedOffset = (int) ($pagination['approved_offset'] ?? 0);
        $rejectedOffset = (int) ($pagination['rejected_offset'] ?? 0);

        // Determine which applications this user can see based on their role
        $pendingApps = $this->getPendingForApprover($userId, $role, $currentUser, $limit, $pendingOffset);
        $approvedApps = $this->getApprovedForApprover($userId, $role, $currentUser, $limit, $approvedOffset);
        $rejectedApps = $this->getRejectedForApprover($userId, $role, $currentUser, $limit, $rejectedOffset);

        // Enrich rows with approver names / employee codes so the UI does not
        // fall back to "System" / "Not Assigned".  Pending rows resolve their
        // *next* approver from the org-hierarchy *_emp_id columns; approved and
        // rejected rows resolve their *actual decider* from the per-stage
        // *_approved_by columns (see resolveDeciders()).
        $pendingApps  = $this->attachPendingApprovers($pendingApps);
        $approvedApps = $this->resolveDeciders($approvedApps);
        $rejectedApps = $this->resolveDeciders($rejectedApps);

        // Counts must be the true total, independent of the LIMIT, otherwise a
        // limit=1 request would always report 1/1/1.

        // Counts must reflect the true total, independent of the applied LIMIT,
        // otherwise a limit=1 counts request would always report 1/1/1.
        $pendingTotal  = $this->countForApprover($role, $employeeId, $currentUser, 'pending');
        $approvedTotal = $this->countForApprover($role, $employeeId, $currentUser, 'approved');
        $rejectedTotal = $this->countForApprover($role, $employeeId, $currentUser, 'rejected');

        return [
            'success' => true,
            'data' => [
                'counts' => [
                    'pending'  => $pendingTotal,
                    'approved' => $approvedTotal,
                    'rejected' => $rejectedTotal,
                ],
                'role' => $role,
                'pending'  => $pendingApps,
                'approved' => $approvedApps,
                'rejected' => $rejectedApps,
            ],
        ];
    }

    /**
     * Approve a leave application (advance it one step in the workflow).
     */
    public function approve(int $userId, int $applicationId): array
    {
        $app = $this->getApplication($applicationId);
        if (!$app) {
            return ['success' => false, 'message' => 'Application not found'];
        }

        $currentUser = $this->getUserById($userId);
        if (!$currentUser) {
            return ['success' => false, 'message' => 'User not found'];
        }

        $role = $currentUser['role'] ?? '';

        // Verify the user is an authorised approver for this application
        if (!$this->isAuthorisedApprover($userId, $app, $role)) {
            return ['success' => false, 'message' => 'You are not authorised to approve this application'];
        }

        // Phase 5 §6/§21: formal transition guard. Approve is only valid from
        // a pending stage — repeat approvals (double-clicks, or the HR /
        // super-admin bypass acting on an already-decided application) are
        // blocked here so the balance ledger can NEVER be applied twice.
        try {
            LeaveWorkflowRules::assertCanDecide($app['status'], 'approve');
        } catch (InvalidLeaveTransitionException $e) {
            \logger()->warning('Leave decision blocked: invalid transition', [
                'action' => 'approve',
                'application_id' => $applicationId,
                'current_status' => $app['status'],
                'actor_user_id' => $userId,
            ]);
            return ['success' => false, 'message' => $e->getMessage(), 'code' => 'INVALID_TRANSITION'];
        }

        $currentStatus = $app['status'];
        $nextStatus = $this->getNextStatus($currentStatus, $role, $app);

        if ($nextStatus === null) {
            return ['success' => false, 'message' => 'This application cannot be approved at this stage'];
        }

        $this->db->begin_transaction();
        try {
            // Update the application status
            $stmt = $this->db->prepare("
                UPDATE leave_applications
                SET status = ?, approved_by = ?, approved_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param('sii', $nextStatus, $userId, $applicationId);
            $stmt->execute();
            $stmt->close();

            // If fully approved, apply balance updates
            if ($nextStatus === 'approved') {
                $this->applyBalanceUpdates($applicationId, $app);
            }

            // Log history
            $this->logHistory($applicationId, $userId, 'approved', $app);

            $this->db->commit();

            return [
                'success' => true,
                'message' => $nextStatus === 'approved'
                    ? 'Leave application fully approved'
                    : "Leave application approved — forwarded to next approver",
                'data' => ['status' => $nextStatus],
            ];
        } catch (\Exception $e) {
            $this->db->rollback();
            \logger()->error('Leave approval failed', [
                'application_id' => $applicationId,
                'actor_user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Unable to process the approval. Please try again.'];
        }
    }

    /**
     * Reject a leave application.
     */
    public function reject(int $userId, int $applicationId, string $reason): array
    {
        $app = $this->getApplication($applicationId);
        if (!$app) {
            return ['success' => false, 'message' => 'Application not found'];
        }

        $currentUser = $this->getUserById($userId);
        if (!$currentUser) {
            return ['success' => false, 'message' => 'User not found'];
        }

        $role = $currentUser['role'] ?? '';

        if (!$this->isAuthorisedApprover($userId, $app, $role)) {
            return ['success' => false, 'message' => 'You are not authorised to reject this application'];
        }

        // Phase 5 §6: reject is only valid from a pending stage. Rejecting an
        // already-approved application would desynchronise the status from
        // the balances deducted at approval time.
        try {
            LeaveWorkflowRules::assertCanDecide($app['status'], 'reject');
        } catch (InvalidLeaveTransitionException $e) {
            \logger()->warning('Leave decision blocked: invalid transition', [
                'action' => 'reject',
                'application_id' => $applicationId,
                'current_status' => $app['status'],
                'actor_user_id' => $userId,
            ]);
            return ['success' => false, 'message' => $e->getMessage(), 'code' => 'INVALID_TRANSITION'];
        }

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare("
                UPDATE leave_applications
                SET status = 'rejected', updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param('i', $applicationId);
            $stmt->execute();
            $stmt->close();

            $this->logHistory($applicationId, $userId, 'rejected', $app, $reason);

            $this->db->commit();

            return ['success' => true, 'message' => 'Leave application rejected', 'data' => ['status' => 'rejected']];
        } catch (\Exception $e) {
            $this->db->rollback();
            \logger()->error('Leave rejection failed', [
                'application_id' => $applicationId,
                'actor_user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Unable to process the rejection. Please try again.'];
        }
    }

    /**
     * Invalidate a leave application (admin-only, removes from active workflow).
     */
    public function invalidate(int $userId, int $applicationId, string $reason): array
    {
        $auth = Auth::getInstance();
        if (!$auth->isSuperAdmin() && !$auth->isHRManager()) {
            return ['success' => false, 'message' => 'Only HR or Super Admin can invalidate applications'];
        }

        $app = $this->getApplication($applicationId);
        if (!$app) {
            return ['success' => false, 'message' => 'Application not found'];
        }

        // Phase 5 §5/§6: invalidation is the formal REVERSAL path. Allowed
        // from pending stages and from approved (which must restore the
        // deducted balances); rejected/cancelled/invalidated are final.
        $wasApproved = $app['status'] === LeaveWorkflowRules::STATUS_APPROVED;
        try {
            LeaveWorkflowRules::assertCanInvalidate($app['status']);
        } catch (InvalidLeaveTransitionException $e) {
            \logger()->warning('Leave decision blocked: invalid transition', [
                'action' => 'invalidate',
                'application_id' => $applicationId,
                'current_status' => $app['status'],
                'actor_user_id' => $userId,
            ]);
            return ['success' => false, 'message' => $e->getMessage(), 'code' => 'INVALID_TRANSITION'];
        }

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare("
                UPDATE leave_applications
                SET status = 'invalidated', updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param('i', $applicationId);
            $stmt->execute();
            $stmt->close();

            // Reversing a fully-approved application restores the balances
            // that were deducted at approval time (mirror of the deduction).
            if ($wasApproved) {
                $this->reverseBalanceUpdates($applicationId, $app);
            }

            $this->logHistory($applicationId, $userId, 'invalidated', $app, $reason);

            $this->db->commit();

            return ['success' => true, 'message' => 'Leave application invalidated', 'data' => ['status' => 'invalidated']];
        } catch (\Exception $e) {
            $this->db->rollback();
            \logger()->error('Leave invalidation failed', [
                'application_id' => $applicationId,
                'actor_user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Unable to process the invalidation. Please try again.'];
        }
    }

    /**
     * Cancel a still-pending leave application.  Only the applicant may cancel.
     */
    public function cancel(int $userId, int $applicationId): array
    {
        $app = $this->getApplication($applicationId);
        if (!$app) {
            return ['success' => false, 'message' => 'Application not found'];
        }

        $currentUser = $this->getUserById($userId);
        if (!$currentUser) {
            return ['success' => false, 'message' => 'User not found'];
        }

        // Only the applicant can cancel
        $applicantEmployeeId = $this->getEmployeeIdFromUserId($userId);
        if ($applicantEmployeeId != $app['employee_id']) {
            return ['success' => false, 'message' => 'Only the applicant can cancel this application'];
        }

        // Can only cancel if still in a pending state. The pending set comes
        // from the single source of truth (LeaveWorkflowRules) — the previous
        // hand-written list silently omitted the 'pending' (column default)
        // and 'pending_hr_manager' stages, making those applications
        // impossible to cancel.
        if (!LeaveWorkflowRules::isPending($app['status'])) {
            return ['success' => false, 'message' => 'Only pending applications can be cancelled'];
        }

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare("
                UPDATE leave_applications
                SET status = 'cancelled', updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->bind_param('i', $applicationId);
            $stmt->execute();
            $stmt->close();

            $this->logHistory($applicationId, $userId, 'cancelled', $app);

            $this->db->commit();

            return ['success' => true, 'message' => 'Leave application cancelled', 'data' => ['status' => 'cancelled']];
        } catch (\Exception $e) {
            $this->db->rollback();
            \logger()->error('Leave cancellation failed', [
                'application_id' => $applicationId,
                'actor_user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Unable to process the cancellation. Please try again.'];
        }
    }

    // ───────────────────────────────────────────────────────────────────
    //  Internal helpers
    // ───────────────────────────────────────────────────────────────────

    /**
     * Get a leave application by ID with leave type info.
     */
    private function getApplication(int $applicationId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT la.*, lt.name as leave_type_name
            FROM leave_applications la
            LEFT JOIN leave_types lt ON la.leave_type_id = lt.id
            WHERE la.id = ?
        ");
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result ?: null;
    }

    /**
     * Get a user by ID.
     */
    private function getUserById(int $userId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result ?: null;
    }

    /**
     * Get employee ID from user ID.
     */
    private function getEmployeeIdFromUserId(int $userId): ?int
    {
        $stmt = $this->db->prepare("
            SELECT e.id FROM employees e
            JOIN users u ON u.employee_id = e.employee_id
            WHERE u.id = ?
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ? (int) $result['id'] : null;
    }

    /**
     * Check if the user is an authorised approver for this application.
     */
    private function isAuthorisedApprover(int $userId, array $app, string $role): bool
    {
        $auth = Auth::getInstance();

        // Super admin and HR can approve anything
        if ($auth->isSuperAdmin() || $auth->isHRManager()) {
            return true;
        }

        $currentEmployeeId = $this->getEmployeeIdFromUserId($userId);
        if (!$currentEmployeeId) {
            return false;
        }

        $managers = $this->workflowService->getManagers($app['employee_id']);
        $status = $app['status'];

        switch ($status) {
            case 'pending_subsection_head':
                return $role === 'sub_section_head'
                    && $managers['subsection_head_emp_id'] == $currentEmployeeId;
            case 'pending_section_head':
                return in_array($role, ['section_head', 'sub_section_head'])
                    && $managers['section_head_emp_id'] == $currentEmployeeId;
            case 'pending_dept_head':
                return in_array($role, ['dept_head', 'section_head', 'sub_section_head'])
                    && $managers['dept_head_emp_id'] == $currentEmployeeId;
            case 'pending_managing_director':
                return $role === 'managing_director';
            case 'pending_hr':
                return $role === 'hr_manager';
            case 'pending_bod_chair':
                return $role === 'bod_chair' || $role === 'bod_chairman';
            case 'pending_manager':
                return $role === 'manager';
            default:
                return false;
        }
    }

    /**
     * Get the next status in the approval workflow.
     * Considers the applicant's role so ordinary employee leaves terminate
     * at Department Head / HR rather than escalating to Managing Director and Board Chair.
     */
    private function getNextStatus(string $currentStatus, string $approverRole, array $app = []): ?string
    {
        if ($approverRole === 'super_admin') {
            return 'approved';
        }

        // Get applicant's role in the organization
        $applicantRole = 'officer';
        if (!empty($app['employee_id'])) {
            $stmt = $this->db->prepare("
                SELECT COALESCE(u.role, 'officer') as role
                FROM employees e
                LEFT JOIN users u ON u.employee_id = e.employee_id
                WHERE e.id = ?
            ");
            $empId = (int)$app['employee_id'];
            $stmt->bind_param('i', $empId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && !empty($row['role'])) {
                $applicantRole = $row['role'];
            }
        }

        // Standard staff (officer, employee, sub_section_head, section_head)
        // Dept Head approval is the final approval for departmental staff.
        if (in_array($applicantRole, ['officer', 'employee', 'sub_section_head', 'section_head', ''], true)) {
            switch ($currentStatus) {
                case 'pending_subsection_head':
                    return 'pending_section_head';
                case 'pending_section_head':
                    return 'pending_dept_head';
                case 'pending_dept_head':
                case 'pending_hr':
                case 'pending_manager':
                    return 'approved';
                default:
                    return 'approved';
            }
        }

        // Department Heads / HR Managers: escalate to Managing Director
        if (in_array($applicantRole, ['dept_head', 'manager', 'hr_manager'], true)) {
            switch ($currentStatus) {
                case 'pending_managing_director':
                case 'pending_hr':
                    return 'approved';
                default:
                    return 'pending_managing_director';
            }
        }

        // Managing Director: escalate to Board Chairman
        if ($applicantRole === 'managing_director') {
            switch ($currentStatus) {
                case 'pending_bod_chair':
                    return 'approved';
                default:
                    return 'pending_bod_chair';
            }
        }

        return 'approved';
    }

    /**
     * Apply balance updates when an application is fully approved.
     */
    private function applyBalanceUpdates(int $applicationId, array $app): void
    {
        $leaveTypeId = (int) $app['leave_type_id'];
        $employeeId = (int) $app['employee_id'];
        $financialYearId = (int) $app['financial_year_id'];
        $primaryDays = (float) ($app['primary_days'] ?? 0);
        $annualDays = (float) ($app['annual_days'] ?? 0);

        // Claim a Day — credit annual leave
        if ($leaveTypeId === LeaveTypePolicy::TYPE_CLAIM_A_DAY) {
            $annualTypeId = $this->getAnnualLeaveTypeId();
            $daysToAdd = (float) ($primaryDays > 0 ? $primaryDays : ($app['days_requested'] ?? 0));
            $stmt = $this->db->prepare("
                UPDATE employee_leave_balances
                SET allocated_days = allocated_days + ?,
                    accumulated_days = accumulated_days + ?,
                    remaining_days = remaining_days + ?
                WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?
            ");
            $stmt->bind_param('dddiii', $daysToAdd, $daysToAdd, $daysToAdd, $employeeId, $annualTypeId, $financialYearId);
            $stmt->execute();
            $stmt->close();
            return;
        }

        // Leave of Absence — no balance deduction
        if ($leaveTypeId === LeaveTypePolicy::TYPE_ABSENCE) {
            return;
        }

        // Normal leave — deduct from primary balance
        if ($primaryDays > 0) {
            $stmt = $this->db->prepare("
                UPDATE employee_leave_balances
                SET used_days = used_days + ?, remaining_days = remaining_days - ?
                WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?
            ");
            $stmt->bind_param('ddiii', $primaryDays, $primaryDays, $employeeId, $leaveTypeId, $financialYearId);
            $stmt->execute();
            $stmt->close();
        }

        // Deduct from annual leave if applicable
        if ($annualDays > 0) {
            $annualTypeId = $this->getAnnualLeaveTypeId();
            $stmt = $this->db->prepare("
                UPDATE employee_leave_balances
                SET used_days = used_days + ?, remaining_days = remaining_days - ?
                WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?
            ");
            $stmt->bind_param('ddiii', $annualDays, $annualDays, $employeeId, $annualTypeId, $financialYearId);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Restore balances when a fully-approved application is invalidated
     * (Phase 5 §5 reversal). Exact mirror of applyBalanceUpdates() with
     * inverted deltas; runs inside the caller's transaction.
     */
    private function reverseBalanceUpdates(int $applicationId, array $app): void
    {
        $leaveTypeId = (int) $app['leave_type_id'];
        $employeeId = (int) $app['employee_id'];
        $financialYearId = (int) $app['financial_year_id'];
        $primaryDays = (float) ($app['primary_days'] ?? 0);
        $annualDays = (float) ($app['annual_days'] ?? 0);

        // Claim a Day — remove the annual leave credit
        if ($leaveTypeId === LeaveTypePolicy::TYPE_CLAIM_A_DAY) {
            $annualTypeId = $this->getAnnualLeaveTypeId();
            $daysToAdd = (float) ($primaryDays > 0 ? $primaryDays : ($app['days_requested'] ?? 0));
            $stmt = $this->db->prepare("
                UPDATE employee_leave_balances
                SET allocated_days = allocated_days - ?,
                    accumulated_days = accumulated_days - ?,
                    remaining_days = remaining_days - ?
                WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?
            ");
            $stmt->bind_param('dddiii', $daysToAdd, $daysToAdd, $daysToAdd, $employeeId, $annualTypeId, $financialYearId);
            $stmt->execute();
            $stmt->close();
            return;
        }

        // Leave of Absence — no balance movement to reverse
        if ($leaveTypeId === LeaveTypePolicy::TYPE_ABSENCE) {
            return;
        }

        // Restore the primary balance deduction
        if ($primaryDays > 0) {
            $stmt = $this->db->prepare("
                UPDATE employee_leave_balances
                SET used_days = used_days - ?, remaining_days = remaining_days + ?
                WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?
            ");
            $stmt->bind_param('ddiii', $primaryDays, $primaryDays, $employeeId, $leaveTypeId, $financialYearId);
            $stmt->execute();
            $stmt->close();
        }

        // Restore the annual leave deduction
        if ($annualDays > 0) {
            $annualTypeId = $this->getAnnualLeaveTypeId();
            $stmt = $this->db->prepare("
                UPDATE employee_leave_balances
                SET used_days = used_days - ?, remaining_days = remaining_days + ?
                WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?
            ");
            $stmt->bind_param('ddiii', $annualDays, $annualDays, $employeeId, $annualTypeId, $financialYearId);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Log an approval history entry.
     */
    private function logHistory(int $applicationId, int $userId, string $action, array $app, ?string $comment = null): void
    {
        $comment = $comment ?? "Leave application {$action} by user {$userId}";
        $stmt = $this->db->prepare("
            INSERT INTO leave_history (leave_application_id, action, performed_by, comments, performed_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param('isis', $applicationId, $action, $userId, $comment);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Get pending applications for an approver.
     */
    private function getPendingForApprover(int $userId, string $role, array $currentUser, int $limit, int $offset = 0): array
    {
        $employeeId = $this->getEmployeeIdFromUserId($userId);
        if (!$employeeId) {
            return [];
        }

        $pendingStatuses = "'pending_subsection_head','pending_section_head','pending_dept_head','pending_managing_director','pending_hr','pending_hr_manager','pending_bod_chair','pending_manager'";

        // Build the WHERE clause based on role
        $where = $this->buildApproverWhereClause($role, $employeeId, $currentUser, 'pending');

        $sql = "
            SELECT la.*, lt.name as leave_type_name,
                   e.first_name, e.last_name, e.employee_id as emp_no
            FROM leave_applications la
            JOIN leave_types lt ON la.leave_type_id = lt.id
            JOIN employees e ON la.employee_id = e.id
            WHERE la.status IN ({$pendingStatuses})
              AND {$where}
            ORDER BY la.applied_at DESC
            LIMIT ?, ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $offset, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $apps = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $apps;
    }

    /**
     * Get approved applications for an approver.
     */
    private function getApprovedForApprover(int $userId, string $role, array $currentUser, int $limit, int $offset = 0): array
    {
        $employeeId = $this->getEmployeeIdFromUserId($userId);
        if (!$employeeId) {
            return [];
        }

        $where = $this->buildApproverWhereClause($role, $employeeId, $currentUser, 'approved');

        $sql = "
            SELECT la.*, lt.name as leave_type_name,
                   e.first_name, e.last_name, e.employee_id as emp_no
            FROM leave_applications la
            JOIN leave_types lt ON la.leave_type_id = lt.id
            JOIN employees e ON la.employee_id = e.id
            WHERE la.status = 'approved'
              AND {$where}
            ORDER BY la.applied_at DESC, la.id DESC
            LIMIT ?, ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $offset, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $apps = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $apps;
    }

    /**
     * Get rejected applications for an approver.
     */
    private function getRejectedForApprover(int $userId, string $role, array $currentUser, int $limit, int $offset = 0): array
    {
        $employeeId = $this->getEmployeeIdFromUserId($userId);
        if (!$employeeId) {
            return [];
        }

        $where = $this->buildApproverWhereClause($role, $employeeId, $currentUser, 'rejected');

        $sql = "
            SELECT la.*, lt.name as leave_type_name,
                   e.first_name, e.last_name, e.employee_id as emp_no
            FROM leave_applications la
            JOIN leave_types lt ON la.leave_type_id = lt.id
            JOIN employees e ON la.employee_id = e.id
            WHERE la.status = 'rejected'
              AND {$where}
            ORDER BY la.applied_at DESC, la.id DESC
            LIMIT ?, ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $offset, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $apps = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $apps;
    }

    /**
     * Build the WHERE clause that determines which applications an
     * approver can see, based on their role and organisational scope.
     */
    private function buildApproverWhereClause(string $role, int $employeeId, array $currentUser, string $category): string
    {
        $auth = Auth::getInstance();

        if ($auth->isSuperAdmin() || $auth->isHRManager()) {
            return '1=1';
        }

        if ($role === 'managing_director') {
            return '1=1';
        }

        if ($role === 'dept_head') {
            return "e.department_id = " . ((int) ($currentUser['department_id'] ?? 0));
        }

        if ($role === 'section_head') {
            return "e.section_id = " . ((int) ($currentUser['section_id'] ?? 0));
        }

        if ($role === 'sub_section_head') {
            $subId = (int) ($currentUser['subsection_id'] ?? 0);
            if ($subId > 0) {
                return "e.subsection_id = {$subId}";
            }
            return "e.section_id = " . ((int) ($currentUser['section_id'] ?? 0));
        }

        // Default: only see own applications
        return "la.employee_id = {$employeeId}";
    }

    /**
     * Attach approver label + name to each pending row so the Stage column
     * shows the correct approver instead of "Approver / Not Assigned".
     */
    private function attachPendingApprovers(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $stageMap = [
            'pending_subsection_head'   => ['label' => 'Subsection Head',   'col' => 'subsection_head_emp_id'],
            'pending_section_head'      => ['label' => 'Section Head',      'col' => 'section_head_emp_id'],
            'pending_dept_head'         => ['label' => 'Department Head',   'col' => 'dept_head_emp_id'],
            'pending_managing_director' => ['label' => 'Managing Director', 'col' => 'md_emp_id'],
            'pending_manager'           => ['label' => 'Manager',           'col' => 'manager_emp_id'],
        ];

        // Collect approver employee ids to resolve with one batched query.
        $empIds = [];
        foreach ($rows as $row) {
            $status = $row['status'] ?? '';
            $col = $stageMap[$status]['col'] ?? 'dept_head_emp_id';
            $id = (int) ($row[$col] ?? 0);
            if ($id > 0) {
                $empIds[$id] = true;
            }
        }

        $names = [];
        if ($empIds) {
            $list = implode(',', array_map(fn($v) => (int)$v, array_keys($empIds)));
            $stmt = $this->db->prepare("SELECT id, first_name, last_name FROM employees WHERE id IN ({$list})");
            $stmt->execute();
            $result = $stmt->get_result();
            while ($x = $result->fetch_assoc()) {
                $names[(int)$x['id']] = trim(($x['first_name'] ?? '') . ' ' . ($x['last_name'] ?? ''));
            }
            $stmt->close();
        }

        foreach ($rows as &$row) {
            $status = $row['status'] ?? '';
            $def = $stageMap[$status] ?? null;
            if ($def) {
                $row['pending_approver_label'] = $def['label'];
                $id = (int) ($row[$def['col']] ?? 0);
                $row['pending_approver_name'] = ($id > 0 && !empty($names[$id]))
                    ? $names[$id]
                    : 'Not Assigned';
            } else {
                $row['pending_approver_label'] = 'Approver';
                $row['pending_approver_name']  = 'Not Assigned';
            }
        }
        unset($row);

        return $rows;
    }

/**
     * Resolve the actual deciding approver's name + decision date for an
     * approved or rejected application and attach `approver_name` / `action_date`
     * to each row.
     *
     * Why this exists: the live leave_applications schema has NO single
     * approved_by / approved_at column, and leave_history only records the
     * "applied"/"approved"/"auto-approved" events (never a "rejected" action),
     * so neither source alone identifies the decider for most rows.  The real
     * decider is therefore the highest stage that populated its per-stage
     * *_approved_by column, e.g.:
     *     managing_director_approved_by > hr_approved_by > dept_head_approved_by
     *     > section_head_approved_by > subsection_head_approved_by > manager_emp_id
     *
     * The *_approved_by columns hold a MIXED id space -- some store a users.id
     * (e.g. 355 = "DAVID KIMANI"), others an employees.id (e.g. 473 = "JAMES
     * MAINA", with no matching user) -- so each id is resolved against users
     * FIRST, then employees.
     */
    private function resolveDeciders(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        // Decision-chain priority (final stage wins).  First column that
        // resolves to a known person is the decider; its *_approved_at
        // supplies the action date.
        $priority = [
            ['id' => 'managing_director_approved_by', 'date' => 'managing_director_approved_at'],
            ['id' => 'hr_approved_by',               'date' => 'hr_approved_at'],
            ['id' => 'dept_head_approved_by',        'date' => 'dept_head_approved_at'],
            ['id' => 'section_head_approved_by',     'date' => 'section_head_approved_at'],
            ['id' => 'subsection_head_approved_by',  'date' => 'subsection_head_approved_at'],
            ['id' => 'manager_emp_id',               'date' => 'manager_emp_id'],
        ];

        // 1. Collect every candidate id across all rows.
        $candidateIds = [];
        foreach ($rows as $row) {
            foreach ($priority as $col) {
                $id = (int) ($row[$col['id']] ?? 0);
                if ($id > 0) {
                    $candidateIds[$id] = true;
                }
            }
        }

        // 2. Batch-resolve names.  users.id and employees.id overlap, so query
        //    users first and only fall back to employees for ids that are not
        //    users.  This correctly maps 355 -> "DAVID KIMANI" (a user) while
        //    473 -> "JAMES MAINA" (employee only).
        $names = [];
        if ($candidateIds) {
            $list = implode(',', array_map(fn($v) => (int)$v, array_keys($candidateIds)));

            $stmt = $this->db->prepare("SELECT id, first_name, last_name FROM users WHERE id IN ({$list})");
            $stmt->execute();
            $result = $stmt->get_result();
            while ($x = $result->fetch_assoc()) {
                $names[(int)$x['id']] = trim(($x['first_name'] ?? '') . ' ' . ($x['last_name'] ?? ''));
            }
            $stmt->close();

            $stmt = $this->db->prepare("SELECT id, first_name, last_name FROM employees WHERE id IN ({$list})");
            $stmt->execute();
            $result = $stmt->get_result();
            while ($x = $result->fetch_assoc()) {
                $id = (int)$x['id'];
                if (!isset($names[$id])) {
                    $names[$id] = trim(($x['first_name'] ?? '') . ' ' . ($x['last_name'] ?? ''));
                }
            }
            $stmt->close();
        }

        // 3. For each row, pick the highest-priority populated stage.
        foreach ($rows as &$row) {
            $row['approver_name'] = 'System';
            $row['action_date']   = null;

            foreach ($priority as $col) {
                $id = (int) ($row[$col['id']] ?? 0);
                if ($id > 0 && !empty($names[$id])) {
                    $row['approver_name'] = $names[$id];
                    $dateCol = $col['date'];
                    // manager_emp_id has no dedicated date column; fall back to
                    // applied_at so the cell is never unexpectedly empty.
                    if ($dateCol === 'manager_emp_id') {
                        $row['action_date'] = $row['applied_at'] ?? null;
                    } else {
                        $row['action_date'] = $row[$dateCol] ?? null;
                    }
                    break;
                }
            }
        }
        unset($row);

        return $rows;
    }


    /**
     * Count leave applications for an approver in a given category.
     * Mirrors the SELECT queries' joins/where so the count always matches
     * the visible rows, and is independent of the pagination LIMIT.
     */
    private function countForApprover(string $role, int $employeeId, array $currentUser, string $category): int
    {
        $pendingStatuses = "'pending_subsection_head','pending_section_head','pending_dept_head','pending_managing_director','pending_hr','pending_hr_manager','pending_bod_chair','pending_manager'";

        switch ($category) {
            case 'approved':
                $statusCondition = "la.status = 'approved'";
                break;
            case 'rejected':
                $statusCondition = "la.status = 'rejected'";
                break;
            case 'pending':
            default:
                $statusCondition = "la.status IN ({$pendingStatuses})";
                break;
        }

        $where = $this->buildApproverWhereClause($role, $employeeId, $currentUser, $category);

        $sql = "
            SELECT COUNT(*) AS total
            FROM leave_applications la
            JOIN leave_types lt ON la.leave_type_id = lt.id
            JOIN employees e ON la.employee_id = e.id
            WHERE {$statusCondition}
              AND {$where}
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $total = (int) $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        return $total;
    }

    /**
     * Get the annual leave type ID.
     */
    private function getAnnualLeaveTypeId(): int
    {
        $stmt = $this->db->prepare("SELECT id FROM leave_types WHERE name LIKE '%annual%' LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ? (int) $result['id'] : 1;
    }
}

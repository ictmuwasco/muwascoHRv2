<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;

/**
 * LeaveApprovalService
 *
 * Owns the supervisor approval workflow for leave applications.
 * Stage transitions, scope checks, balance deduction at terminal approval,
 * and the delegate-as-backup-approver rule all live here.
 *
 * Chain (strict, no overrides), ported from legacy manage.php:
 *   sub_section_head  -> pending_section_head
 *   section_head      -> pending_dept_head
 *   dept_head         -> approved   (or pending_managing_director if leave_type_id = 8)
 *   managing_director -> approved
 *   hr_manager        -> approved   (Claim-a-Day path, leave_type_id = 9)
 */
class LeaveApprovalService
{
    private \mysqli $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Return three lists scoped to the given approver: pending, approved, rejected.
     * Mirrors manage.php 962–1215.
     */
    public function listForApprover(int $userId, array $pagination = []): array
    {
        $ctx = $this->resolveApproverContext($userId);
        if (!$ctx) {
            return ['success' => false, 'message' => 'Approver record not found.', 'data' => []];
        }

        $limit  = max(1, (int) ($pagination['limit'] ?? 15));
        $pendingOffset  = max(0, (int) ($pagination['pending_offset'] ?? 0));
        $approvedOffset = max(0, (int) ($pagination['approved_offset'] ?? 0));
        $rejectedOffset = max(0, (int) ($pagination['rejected_offset'] ?? 0));

        try {
            return [
                'success' => true,
                'data' => [
                    'pending'  => $this->fetchPendingLeaves($ctx, $limit, $pendingOffset),
                    'approved' => $this->fetchApprovedLeaves($ctx, $limit, $approvedOffset),
                    'rejected' => $this->fetchRejectedLeaves($ctx, $limit, $rejectedOffset),
                    'counts'   => [
                        'pending'  => $this->countPendingLeaves($ctx),
                        'approved' => $this->countApprovedLeaves($ctx),
                        'rejected' => $this->countRejectedLeaves($ctx),
                    ],
                    'role'     => $ctx['role'],
                ],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to fetch leaves: ' . $e->getMessage(), 'data' => []];
        }
    }

    /**
     * Approve a leave application at the current pending stage.
     * Returns {success, message, new_status}.
     */
    public function approve(int $userId, int $applicationId): array
    {
        try {
            $app = $this->loadApplication($applicationId);
            if (!$app) {
                return ['success' => false, 'message' => 'Leave application not found.'];
            }

            $authorization = $this->authorizeApproverAction($userId, $app, 'approve');
            if (!$authorization['authorized']) {
                return ['success' => false, 'message' => $authorization['message']];
            }

            $this->db->begin_transaction();

            $leaveTypeId = (int) $app['leave_type_id'];
            $currentStatus = (string) $app['status'];
            $nextStatus = $this->computeNextStatusOnApprove($currentStatus, $leaveTypeId);
            if ($nextStatus === null) {
                $this->db->rollback();
                return ['success' => false, 'message' => 'This leave is not in a state you can approve.'];
            }

            // Write the approver column appropriate to the stage.
            [$byCol, $atCol] = $this->approverColumnFor($currentStatus, $leaveTypeId);

            // Special: claim-a-day (id 9) skip-chain, only HR approves.
            if ($currentStatus !== 'pending_hr' || $leaveTypeId === 9) {
                // The $byCol/$atCol pair is always defined for known pending stages.
                $sql = "UPDATE leave_applications
                        SET status = ?, {$byCol} = ?, {$atCol} = NOW()";
                $params = [$nextStatus, $authorization['approver_emp_id']];
                $types  = 'si';
            } else {
                // pending_hr for non-Claim-a-Day leaves — update HR columns.
                $sql = "UPDATE leave_applications
                        SET status = ?, hr_approved_by = ?, hr_approved_at = NOW()";
                $params = [$nextStatus, $authorization['approver_emp_id']];
                $types  = 'si';
            }
            $sql .= ' WHERE id = ?';
            $params[] = $applicationId;
            $types   .= 'i';

            $upd = $this->db->prepare($sql);
            $upd->bind_param($types, ...$params);
            $upd->execute();

            $message = 'Leave approved.';
            $balanceWarnings = '';

            if ($nextStatus === 'approved') {
                $balanceWarnings = $this->applyBalanceDeduction($app);
                $message .= $balanceWarnings;
            }

            // Log history.
            $this->logHistory($applicationId, $userId, 'approved', $message);
            // Notify the applicant of the stage advancement.
            $this->notifyApplicantAdvanced($app, $nextStatus);

            $this->db->commit();

            return [
                'success' => true,
                'message' => $message,
                'new_status' => $nextStatus,
            ];
        } catch (\Throwable $e) {
            if ($this->db->errno === 0) {
                // No transaction in flight; just bubble.
            } else {
                @$this->db->rollback();
            }
            return ['success' => false, 'message' => 'Approval failed: ' . $e->getMessage()];
        }
    }

    /**
     * Reject a leave application. Terminal. Requires a reason.
     */
    public function reject(int $userId, int $applicationId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'message' => 'A reason is required to reject leave.'];
        }

        try {
            $app = $this->loadApplication($applicationId);
            if (!$app) {
                return ['success' => false, 'message' => 'Leave application not found.'];
            }

            $authorization = $this->authorizeApproverAction($userId, $app, 'reject');
            if (!$authorization['authorized']) {
                return ['success' => false, 'message' => $authorization['message']];
            }

            $this->db->begin_transaction();

            $currentStatus = (string) $app['status'];
            [$byCol, $atCol] = $this->approverColumnFor($currentStatus, (int) $app['leave_type_id']);
            // Reject always uses rejection_reason + the stage's approver column.
            $sql = "UPDATE leave_applications
                    SET status = 'rejected',
                        rejection_reason = ?,
                        {$byCol} = ?,
                        {$atCol} = NOW()
                    WHERE id = ?";
            $upd = $this->db->prepare($sql);
            $upd->bind_param('sii', $reason, $authorization['approver_emp_id'], $applicationId);
            $upd->execute();

            $this->logHistory($applicationId, $userId, 'rejected', 'Rejected: ' . $reason);
            $this->notifyApplicantRejected($app, $reason);

            $this->db->commit();
            return ['success' => true, 'message' => 'Leave rejected. Reason recorded.'];
        } catch (\Throwable $e) {
            @$this->db->rollback();
            return ['success' => false, 'message' => 'Rejection failed: ' . $e->getMessage()];
        }
    }

    /**
     * Invalidate (send back to employee for reapplication). Requires reason.
     */
    public function invalidate(int $userId, int $applicationId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'message' => 'A reason is required to invalidate leave.'];
        }

        try {
            $app = $this->loadApplication($applicationId);
            if (!$app) {
                return ['success' => false, 'message' => 'Leave application not found.'];
            }

            $authorization = $this->authorizeApproverAction($userId, $app, 'invalidate');
            if (!$authorization['authorized']) {
                return ['success' => false, 'message' => $authorization['message']];
            }

            $upd = $this->db->prepare("
                UPDATE leave_applications
                SET status = 'invalidated', invalidation_reason = ?
                WHERE id = ?
            ");
            $upd->bind_param('si', $reason, $applicationId);
            $upd->execute();

            $this->logHistory($applicationId, $userId, 'invalidated', 'Invalidated: ' . $reason);
            $this->notifyApplicantInvalidated($app, $reason);

            return ['success' => true, 'message' => 'Leave invalidated. Employee notified.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Invalidation failed: ' . $e->getMessage()];
        }
    }

    /**
     * Cancel a still-pending leave application. Only the original applicant may cancel.
     */
    public function cancel(int $userId, int $applicationId): array
    {
        try {
            $app = $this->loadApplication($applicationId);
            if (!$app) {
                return ['success' => false, 'message' => 'Leave application not found.'];
            }

            // Only the original applicant can cancel their own pending application.
            $applicantUserId = $this->getApplicantUserId((int) $app['employee_id']);
            if ($applicantUserId !== $userId) {
                return ['success' => false, 'message' => 'Only the applicant can cancel this leave.'];
            }

            $currentStatus = (string) $app['status'];
            if (!str_starts_with($currentStatus, 'pending_') && $currentStatus !== 'pending') {
                return ['success' => false, 'message' => 'Only pending leaves can be cancelled.'];
            }

            $upd = $this->db->prepare("
                UPDATE leave_applications
                SET status = 'invalidated', invalidation_reason = 'Cancelled by applicant'
                WHERE id = ?
            ");
            $upd->bind_param('i', $applicationId);
            $upd->execute();

            $this->logHistory($applicationId, $userId, 'cancelled', 'Cancelled by applicant.');
            return ['success' => true, 'message' => 'Leave cancelled.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Cancellation failed: ' . $e->getMessage()];
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Internals
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Resolve {role, employee_id, subsection_id, section_id, department_id} for a user.
     */
    private function resolveApproverContext(int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT u.id AS user_id, u.role,
                   e.id AS employee_id, e.subsection_id, e.section_id, e.department_id
            FROM users u
            LEFT JOIN employees e ON e.employee_id = u.employee_id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            return null;
        }
        return [
            'user_id'       => (int) $row['user_id'],
            'role'          => (string) ($row['role'] ?? ''),
            'employee_id'   => isset($row['employee_id']) ? (int) $row['employee_id'] : null,
            'subsection_id' => isset($row['subsection_id']) ? (int) $row['subsection_id'] : null,
            'section_id'    => isset($row['section_id']) ? (int) $row['section_id'] : null,
            'department_id' => isset($row['department_id']) ? (int) $row['department_id'] : null,
        ];
    }

    /**
     * Load a full leave-application row joined to employee + leave type.
     */
    private function loadApplication(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT la.*, e.id AS emp_internal_id, e.employee_id AS emp_string_id,
                   e.department_id, e.section_id, e.subsection_id,
                   e.first_name, e.last_name,
                   lt.name AS leave_type_name, lt.id AS leave_type_id
            FROM leave_applications la
            JOIN employees e ON la.employee_id = e.id
            JOIN leave_types lt ON la.leave_type_id = lt.id
            WHERE la.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: null;
    }

    /**
     * Decide whether `$userId` may take `$action` (approve/reject/invalidate)
     * on `$app` at its current status.
     */
    private function authorizeApproverAction(int $userId, array $app, string $action): array
    {
        $ctx = $this->resolveApproverContext($userId);
        if (!$ctx) {
            return ['authorized' => false, 'message' => 'Approver record not found.', 'approver_emp_id' => null];
        }

        $appEmpId  = (int) $app['emp_internal_id'];
        $applicantUserId = $this->getApplicantUserId($appEmpId);
        $isOwnApplication = $applicantUserId === $userId;

        // Officers / non-managers: do not expose approver actions on Manage Leave at all.
        // managerRoles includes both 'bod_chair' (legacy/notification code) and 'bod_chairman'
        // (the value currently written to users.role by EmployeeForm).
        $managerRoles = [
            'sub_section_head', 'section_head', 'dept_head',
            'managing_director', 'hr_manager', 'super_admin',
            'bod_chair', 'bod_chairman',
        ];
        if (!in_array($ctx['role'], $managerRoles, true)) {
            return ['authorized' => false, 'message' => 'You do not have permission to manage leave.', 'approver_emp_id' => null];
        }

        // Self-applicant can never approve their own leave.
        if ($isOwnApplication) {
            return ['authorized' => false, 'message' => 'You cannot approve or reject your own leave application.', 'approver_emp_id' => null];
        }

        // Check scope (matches legacy approverCanActOnLeave).
        $inScope = $this->approverInScope($ctx, $app);
        $isDelegateBackup = false;

        // If not in scope, give the delegate-as-backup rule one chance.
        if (!$inScope) {
            $delegateService = new DelegateService();
            $isDelegateBackup = $delegateService->canDelegateApprove((int) $app['id'], $userId);
            if (!$isDelegateBackup) {
                return ['authorized' => false, 'message' => 'You are not authorised to act on this leave (out of scope).', 'approver_emp_id' => null];
            }
        }

        // Status must be a known pending stage that maps to a known approver column.
        $status = (string) $app['status'];
        $validStatuses = [
            'pending_subsection_head',
            'pending_section_head',
            'pending_dept_head',
            'pending_managing_director',
            'pending_bod_chair',
            'pending_hr',
        ];
        if (!in_array($status, $validStatuses, true)) {
            return ['authorized' => false, 'message' => 'This leave is not pending approval.', 'approver_emp_id' => null];
        }

        // Match role to stage (strict chain order).
        $stageRole = match ($status) {
            'pending_subsection_head'  => 'sub_section_head',
            'pending_section_head'     => 'section_head',
            'pending_dept_head'        => 'dept_head',
            'pending_managing_director'=> 'managing_director',
            'pending_bod_chair'        => 'bod_chair',
            'pending_hr'               => 'hr_manager',
            default                    => null,
        };

        // HR / super_admin / managing_director / bod_chair may approve at any stage.
        $anyStageRoles = ['hr_manager', 'super_admin', 'managing_director', 'bod_chair', 'bod_chairman'];
        if (in_array($ctx['role'], $anyStageRoles, true)) {
            return ['authorized' => true, 'message' => '', 'approver_emp_id' => $ctx['employee_id']];
        }

        // Delegate fallback: allow delegate to act when natural approver is the applicant.
        if ($isDelegateBackup) {
            return ['authorized' => true, 'message' => '', 'approver_emp_id' => $ctx['employee_id']];
        }

        // Otherwise: role must equal stage role, and user must be in scope (already checked above).
        if ($stageRole !== $ctx['role']) {
            return ['authorized' => false, 'message' => "This leave is pending {$status}; your role ({$ctx['role']}) cannot act on it at this stage.", 'approver_emp_id' => null];
        }

        return ['authorized' => true, 'message' => '', 'approver_emp_id' => $ctx['employee_id']];
    }

    /**
     * Mirrors legacy approverCanActOnLeave.
     */
    private function approverInScope(array $ctx, array $app): bool
    {
        return match ($ctx['role']) {
            'sub_section_head'  => !empty($ctx['subsection_id'])
                                   && (int) $app['subsection_id'] === (int) $ctx['subsection_id'],
            'section_head'      => !empty($ctx['section_id'])
                                   && (int) $app['section_id'] === (int) $ctx['section_id'],
            'dept_head'         => !empty($ctx['department_id'])
                                   && (int) $app['department_id'] === (int) $ctx['department_id'],
            'managing_director' => true,
            'hr_manager'        => true,
            'super_admin'       => true,
            'bod_chair'         => true,
            'bod_chairman'      => true,
            default             => false,
        };
    }

    /**
     * Compute next status when an approver clicks Approve.
     * Returns null if no transition is valid.
     */
    private function computeNextStatusOnApprove(string $currentStatus, int $leaveTypeId): ?string
    {
        return match ($currentStatus) {
            'pending_subsection_head' => 'pending_section_head',
            'pending_section_head'    => 'pending_dept_head',
            'pending_dept_head'       => $leaveTypeId === 8 ? 'pending_managing_director' : 'approved',
            'pending_managing_director' => 'approved',
            'pending_bod_chair'       => 'approved',
            'pending_hr'              => 'approved',
            default                   => null,
        };
    }

    /**
     * Return [byCol, atCol] for the stage's approver columns.
     * For leave_type_id = 9 (Claim-a-Day), pending_hr maps to hr_approved_by.
     */
    private function approverColumnFor(string $status, int $leaveTypeId): array
    {
        // Claim-a-Day skip-chain: only HR approves, status always pending_hr.
        if ($leaveTypeId === 9) {
            return ['hr_approved_by', 'hr_approved_at'];
        }

        return match ($status) {
            'pending_subsection_head'  => ['subsection_head_approved_by', 'subsection_head_approved_at'],
            'pending_section_head'     => ['section_head_approved_by',    'section_head_approved_at'],
            'pending_dept_head'        => ['dept_head_approved_by',       'dept_head_approved_at'],
            'pending_managing_director'=> ['managing_director_approved_by', 'managing_director_approved_at'],
            'pending_bod_chair'        => ['hr_approved_by',              'hr_approved_at'],
            'pending_hr'               => ['hr_approved_by',              'hr_approved_at'],
            default                    => ['hr_approved_by',              'hr_approved_at'],
        };
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Role hierarchy — used to enforce "downward only" visibility:
    //  a viewer may only see leaves filed by employees of equal or lower rank
    //  inside their unit scope.
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Comma-separated, single-quoted employee_type literals that each scoped
     * role is allowed to *see* in approved/rejected listings.
     *
     * Rule: the viewer's role must be >= the applicant's role in the org chart.
     */
    private const SECTION_VISIBLE_TYPES = "'officer','sub_section_head','section_head','employee'";
    private const DEPT_VISIBLE_TYPES    = "'officer','sub_section_head','section_head','dept_head','manager','employee'";

    // ══════════════════════════════════════════════════════════════════════
    //  Listing queries — ported from manage.php 962–1215
    // ══════════════════════════════════════════════════════════════════════

    private function pendingWhere(array $ctx): string
    {
        $pendingStatuses = "('pending_subsection_head','pending_section_head','pending_dept_head','pending_managing_director','pending_bod_chair','pending_hr')";

        return match ($ctx['role']) {
            'sub_section_head'  => "WHERE la.status='pending_subsection_head' AND e.subsection_id = " . (int) $ctx['subsection_id'],
            'section_head'      => "WHERE la.status='pending_section_head' AND e.section_id = " . (int) $ctx['section_id'],
            'dept_head'         => "WHERE la.status='pending_dept_head' AND e.department_id = " . (int) $ctx['department_id'],
            'managing_director' => "WHERE la.status='pending_managing_director'",
            // HR / super_admin / bod_chair (chairman) see every pending stage
            // anywhere in the org.
            'hr_manager', 'super_admin', 'bod_chair', 'bod_chairman' => "WHERE la.status IN {$pendingStatuses}",
            default             => "WHERE 1=0",
        };
    }

    /**
     * Build the visibility filter for approved/rejected rows.
     * - sub_section_head / section_head / dept_head: only their unit scope,
     *   AND only applicants whose employee_type is <= their own rank
     *   (downward only).
     * - hr_manager / managing_director / bod_chair / super_admin: no filter
     *   (they can see across the org).
     */
    private function finalisedWhere(array $ctx, string $status): string
    {
        switch ($ctx['role']) {
            case 'sub_section_head':
                if (empty($ctx['subsection_id'])) {
                    return "AND 1=0";
                }
                return "AND e.subsection_id = " . (int) $ctx['subsection_id']
                    . " AND COALESCE(LOWER(e.employee_type),'officer') IN ('officer','sub_section_head','employee')";

            case 'section_head':
                if (empty($ctx['section_id'])) {
                    return "AND 1=0";
                }
                return "AND e.section_id = " . (int) $ctx['section_id']
                    . " AND COALESCE(LOWER(e.employee_type),'officer') IN (" . self::SECTION_VISIBLE_TYPES . ")";

            case 'dept_head':
                if (empty($ctx['department_id'])) {
                    return "AND 1=0";
                }
                return "AND e.department_id = " . (int) $ctx['department_id']
                    . " AND COALESCE(LOWER(e.employee_type),'officer') IN (" . self::DEPT_VISIBLE_TYPES . ")";

            // hr_manager / managing_director / bod_chair / super_admin — org-wide.
            default:
                return "";
        }
    }

    private function pendingBaseJoin(): string
    {
        return "
            FROM leave_applications la
            JOIN employees e    ON la.employee_id   = e.id
            JOIN leave_types lt ON la.leave_type_id = lt.id
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN sections    s ON e.section_id    = s.id
        ";
    }

    private function finalisedBaseJoin(): string
    {
        return "
            FROM leave_applications la
            JOIN employees e    ON la.employee_id   = e.id
            JOIN leave_types lt ON la.leave_type_id = lt.id
        ";
    }

    private function approverColumnExpr(): string
    {
        return "
            COALESCE(
                (SELECT CONCAT(u.first_name,' ',u.last_name) FROM users u JOIN employees em ON u.employee_id=em.employee_id WHERE em.id=la.hr_approved_by LIMIT 1),
                (SELECT CONCAT(u.first_name,' ',u.last_name) FROM users u JOIN employees em ON u.employee_id=em.employee_id WHERE em.id=la.managing_director_approved_by LIMIT 1),
                (SELECT CONCAT(u.first_name,' ',u.last_name) FROM users u JOIN employees em ON u.employee_id=em.employee_id WHERE em.id=la.dept_head_approved_by LIMIT 1),
                (SELECT CONCAT(u.first_name,' ',u.last_name) FROM users u JOIN employees em ON u.employee_id=em.employee_id WHERE em.id=la.section_head_approved_by LIMIT 1),
                (SELECT CONCAT(u.first_name,' ',u.last_name) FROM users u JOIN employees em ON u.employee_id=em.employee_id WHERE em.id=la.subsection_head_approved_by LIMIT 1),
                'System'
            ) AS approver_name,
            COALESCE(la.hr_approved_at, la.managing_director_approved_at, la.dept_head_approved_at, la.section_head_approved_at, la.subsection_head_approved_at) AS action_date
        ";
    }

    private function fetchPendingLeaves(array $ctx, int $limit, int $offset): array
    {
        $sql = "SELECT la.*, e.employee_id AS emp_no, e.first_name, e.last_name,
                       e.department_id, e.section_id, e.subsection_id,
                       lt.name AS leave_type_name,
                       d.name AS department_name, s.name AS section_name
                " . $this->pendingBaseJoin() . "
                " . $this->pendingWhere($ctx) . "
                ORDER BY la.applied_at DESC LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $limit, $offset);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        return $this->annotatePendingRows($rows);
    }

    private function countPendingLeaves(array $ctx): int
    {
        $sql = "SELECT COUNT(*) AS cnt
                FROM leave_applications la
                JOIN employees e ON la.employee_id = e.id
                " . $this->pendingWhere($ctx);
        $res = $this->db->query($sql);
        return $res ? (int) $res->fetch_assoc()['cnt'] : 0;
    }

    private function fetchApprovedLeaves(array $ctx, int $limit, int $offset): array
    {
        $sql = "SELECT la.*, e.employee_id AS emp_no, e.first_name, e.last_name,
                       e.department_id, lt.name AS leave_type_name, " . $this->approverColumnExpr() . "
                " . $this->finalisedBaseJoin() . "
                WHERE la.status = 'approved'
                " . $this->finalisedWhere($ctx, 'approved') . "
                ORDER BY action_date DESC LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($sql);
        // finalisedWhere() inlines the scope int (no `?` placeholder) when applicable,
        // so the only bound params here are LIMIT and OFFSET.
        $stmt->bind_param('ii', $limit, $offset);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function countApprovedLeaves(array $ctx): int
    {
        $sql = "SELECT COUNT(*) AS cnt
                FROM leave_applications la
                JOIN employees e ON la.employee_id = e.id
                WHERE la.status = 'approved'
                " . $this->finalisedWhere($ctx, 'approved');
        $res = $this->db->query($sql);
        return $res ? (int) $res->fetch_assoc()['cnt'] : 0;
    }

    private function fetchRejectedLeaves(array $ctx, int $limit, int $offset): array
    {
        $sql = "SELECT la.*, e.employee_id AS emp_no, e.first_name, e.last_name,
                       e.department_id, lt.name AS leave_type_name, " . $this->approverColumnExpr() . "
                " . $this->finalisedBaseJoin() . "
                WHERE la.status = 'rejected'
                " . $this->finalisedWhere($ctx, 'rejected') . "
                ORDER BY action_date DESC LIMIT ? OFFSET ?";
        $stmt = $this->db->prepare($sql);
        // finalisedWhere() inlines the scope int (no `?` placeholder) when applicable,
        // so the only bound params here are LIMIT and OFFSET.
        $stmt->bind_param('ii', $limit, $offset);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function countRejectedLeaves(array $ctx): int
    {
        $sql = "SELECT COUNT(*) AS cnt
                FROM leave_applications la
                JOIN employees e ON la.employee_id = e.id
                WHERE la.status = 'rejected'
                " . $this->finalisedWhere($ctx, 'rejected');
        $res = $this->db->query($sql);
        return $res ? (int) $res->fetch_assoc()['cnt'] : 0;
    }

    /**
     * Mark each pending row with the UI hints: canActOn, isDelegateApprover, etc.
     */
    private function annotatePendingRows(array $rows): array
    {
        // For the UI: compute canActOn per row (cheaper than running scope check
        // for the same role repeatedly — we know the role from the page context).
        foreach ($rows as &$row) {
            $row['pending_approver_label'] = $this->pendingApproverLabel((string) $row['status']);
            $row['pending_approver_name']  = $this->pendingApproverName($row);
        }
        return $rows;
    }

    private function pendingApproverLabel(string $status): string
    {
        return match ($status) {
            'pending_subsection_head'  => 'Subsection Head',
            'pending_section_head'     => 'Section Head',
            'pending_dept_head'        => 'Department Head',
            'pending_managing_director'=> 'Managing Director',
            'pending_bod_chair'        => 'BOD Chair',
            'pending_hr'               => 'HR Manager',
            default                    => '',
        };
    }

    private function pendingApproverName(array $row): string
    {
        $status = (string) $row['status'];
        $col = match ($status) {
            'pending_subsection_head'  => 'subsection_head_emp_id',
            'pending_section_head'     => 'section_head_emp_id',
            'pending_dept_head'        => 'dept_head_emp_id',
            default                    => null,
        };
        if ($col === null) {
            // For MD/BoD/HR — look up by role.
            $role = match ($status) {
                'pending_managing_director' => 'managing_director',
                'pending_bod_chair'         => 'bod_chair',
                default                     => 'hr_manager',
            };
            $stmt = $this->db->prepare("
                SELECT CONCAT(u.first_name,' ',u.last_name) AS name
                FROM users u
                WHERE u.role = ? LIMIT 1
            ");
            $stmt->bind_param('s', $role);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            return $r['name'] ?? 'Not Assigned';
        }

        $empId = isset($row[$col]) ? (int) $row[$col] : 0;
        if (!$empId) {
            return 'Not Assigned';
        }
        $stmt = $this->db->prepare("
            SELECT CONCAT(u.first_name,' ',u.last_name) AS name
            FROM users u JOIN employees e ON u.employee_id = e.employee_id
            WHERE e.id = ? LIMIT 1
        ");
        $stmt->bind_param('i', $empId);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        return $r['name'] ?? 'Not Assigned';
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Balance deduction at terminal approval — ported from manage.php 661–724
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Apply balance deduction. Returns warning string or empty on success.
     */
    private function applyBalanceDeduction(array $app): string
    {
        $leaveTypeId = (int) $app['leave_type_id'];
        $empId       = (int) $app['emp_internal_id'];
        $empStringId = (string) $app['emp_string_id'];
        $fyId        = $this->getFinancialYearIdForApplication($app);
        $primaryDays = (float) ($app['primary_days'] ?? 0);
        $annualDays  = (float) ($app['annual_days']  ?? 0);
        $daysRequested = (float) ($app['days_requested'] ?? 0);

        // Leave of absence (id 8) — no balance impact at all.
        if ($leaveTypeId === 8) {
            return '';
        }

        if (!$fyId) {
            return ' Warning: Leave balance could not be updated - no matching financial year found.';
        }

        // Claim-a-Day credits annual leave.
        if ($leaveTypeId === 9) {
            $r = $this->updateLeaveBalance($empStringId, 1, $daysRequested, true, $fyId);
            return $r['success'] ? '' : ' Warning: Annual balance could not be updated – ' . ($r['message'] ?? 'Unknown error.');
        }

        $messages = [];

        if ($primaryDays > 0) {
            $r = $this->updateLeaveBalance($empStringId, $leaveTypeId, $primaryDays, false, $fyId);
            if (!$r['success']) {
                $messages[] = 'Warning: Primary balance could not be updated – ' . ($r['message'] ?? 'Unknown error.');
            }
        }
        if ($annualDays > 0) {
            $annualTypeId = $this->getAnnualLeaveTypeId();
            if ($annualTypeId) {
                $r = $this->updateLeaveBalance($empStringId, $annualTypeId, $annualDays, false, $fyId);
                if (!$r['success']) {
                    $messages[] = 'Warning: Annual balance could not be updated – ' . ($r['message'] ?? 'Unknown error.');
                }
            }
        }
        // Legacy fallback for older rows with no split.
        if ($primaryDays === 0.0 && $annualDays === 0.0 && $daysRequested > 0) {
            $targetTypeId = $this->getTargetLeaveTypeId($leaveTypeId);
            $r = $this->updateLeaveBalance($empStringId, $targetTypeId, $daysRequested, false, $fyId);
            if (!$r['success']) {
                $messages[] = ' Warning: Leave balance could not be updated – ' . ($r['message'] ?? 'Unknown error.');
            }
        }
        return $messages ? ' ' . implode('; ', $messages) : '';
    }

    /**
     * Get current financial year for the application's start date.
     */
    private function getFinancialYearIdForApplication(array $app): ?int
    {
        if (!empty($app['financial_year_id'])) {
            return (int) $app['financial_year_id'];
        }
        if (empty($app['start_date'])) {
            return null;
        }
        $stmt = $this->db->prepare("
            SELECT id FROM financial_years
            WHERE ? BETWEEN start_date AND end_date
            ORDER BY start_date DESC LIMIT 1
        ");
        $stmt->bind_param('s', $app['start_date']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            return (int) $row['id'];
        }
        $res = $this->db->query("
            SELECT id FROM financial_years
            ORDER BY end_date DESC, start_date DESC LIMIT 1
        ");
        $row = $res ? $res->fetch_assoc() : null;
        return $row ? (int) $row['id'] : null;
    }

    /**
     * Update one leave balance row. employee_id here is the STRING employee no.
     */
    private function updateLeaveBalance(string $empStringId, int $leaveTypeId, float $days, bool $isClaimDay, int $fyId): array
    {
        try {
            // Handle study leave chain: deduct from study first, then annual.
            if ($leaveTypeId === 5) {
                return $this->handleStudyLeaveDeduction($empStringId, $days, $fyId);
            }

            $balance = $this->fetchLatestBalance($empStringId, $leaveTypeId, $fyId);
            $id = (int) $balance['id'];

            if ($isClaimDay) {
                $newAccum     = (float) $balance['accumulated_days'] + $days;
                $newRemaining = (float) $balance['remaining_days'] + $days;
                $sql = "UPDATE employee_leave_balances
                        SET accumulated_days = ?, remaining_days = ?, updated_at = NOW()
                        WHERE id = ?";
                $stmt = $this->db->prepare($sql);
                $stmt->bind_param('ddi', $newAccum, $newRemaining, $id);
            } else {
                $newUsed      = (float) $balance['used_days'] + $days;
                $newRemaining = (float) $balance['remaining_days'] - $days;
                $sql = "UPDATE employee_leave_balances
                        SET used_days = ?, remaining_days = ?, updated_at = NOW()
                        WHERE id = ?";
                $stmt = $this->db->prepare($sql);
                $stmt->bind_param('ddi', $newUsed, $newRemaining, $id);
            }

            if (!$stmt->execute()) {
                return ['success' => false, 'message' => 'Failed to update leave balance: ' . $this->db->error];
            }
            return ['success' => true];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Handle study leave chain: study first, annual second. Balanced.
     * employee_id is the STRING employee no.
     */
    private function handleStudyLeaveDeduction(string $empStringId, float $days, int $fyId): array
    {
        $remaining = $days;

        try {
            $study = $this->fetchLatestBalance($empStringId, 5, $fyId);
            $fromStudy = min((float) $study['remaining_days'], $remaining);
            if ($fromStudy > 0) {
                $newUsed = (float) $study['used_days'] + $fromStudy;
                $newRem  = (float) $study['remaining_days'] - $fromStudy;
                $stmt = $this->db->prepare("UPDATE employee_leave_balances SET used_days=?, remaining_days=?, updated_at=NOW() WHERE id=?");
                $stmt->bind_param('ddi', $newUsed, $newRem, $study['id']);
                if (!$stmt->execute()) {
                    return ['success' => false, 'message' => 'Failed to update study leave balance.'];
                }
                $remaining -= $fromStudy;
            }

            if ($remaining > 0) {
                $annual = $this->fetchLatestBalance($empStringId, 1, $fyId);
                if ((float) $annual['remaining_days'] < $remaining) {
                    return ['success' => false, 'message' => "Insufficient total leave. Study: {$study['remaining_days']}, Annual: {$annual['remaining_days']}, Required: {$days}."];
                }
                $newUsed = (float) $annual['used_days'] + $remaining;
                $newRem  = (float) $annual['remaining_days'] - $remaining;
                $stmt = $this->db->prepare("UPDATE employee_leave_balances SET used_days=?, remaining_days=?, updated_at=NOW() WHERE id=?");
                $stmt->bind_param('ddi', $newUsed, $newRem, $annual['id']);
                if (!$stmt->execute()) {
                    return ['success' => false, 'message' => 'Failed to update annual balance.'];
                }
            }
            return ['success' => true];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Fetch latest employee_leave_balances row, auto-creating one if absent.
     */
    private function fetchLatestBalance(string $empStringId, int $leaveTypeId, int $fyId): array
    {
        $stmt = $this->db->prepare("
            SELECT elb.*
            FROM employee_leave_balances elb
            JOIN financial_years fy ON elb.financial_year_id = fy.id
            WHERE elb.employee_id = ? AND elb.leave_type_id = ? AND elb.financial_year_id = ?
            ORDER BY fy.end_date DESC, fy.start_date DESC LIMIT 1
        ");
        $stmt->bind_param('sii', $empStringId, $leaveTypeId, $fyId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) {
            $this->ensureLeaveBalanceExists($empStringId, $leaveTypeId, $fyId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
        }

        if (!$row) {
            throw new \RuntimeException("No leave balance for employee {$empStringId} / type {$leaveTypeId}.");
        }
        return $row;
    }

    /**
     * Insert a zero-balance row for the employee + type + FY if missing.
     */
    private function ensureLeaveBalanceExists(string $empStringId, int $leaveTypeId, int $fyId): void
    {
        $check = $this->db->prepare("
            SELECT id FROM employee_leave_balances
            WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?
            LIMIT 1
        ");
        $check->bind_param('sii', $empStringId, $leaveTypeId, $fyId);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            return;
        }

        // Read default_days for this leave type. If absent, allocated = 0.
        $res = $this->db->query("SELECT * FROM leave_types WHERE id = " . (int) $leaveTypeId . " LIMIT 1");
        $lt = $res ? $res->fetch_assoc() : null;
        $alloc = isset($lt['default_days']) ? (float) $lt['default_days'] : 0.0;

        $ins = $this->db->prepare("
            INSERT INTO employee_leave_balances
                (employee_id, leave_type_id, financial_year_id, allocated_days, brought_forward_days,
                 accumulated_days, used_days, remaining_days, created_at, updated_at)
            VALUES (?, ?, ?, ?, 0, ?, 0, ?, NOW(), NOW())
        ");
        $acc = $alloc; // start with accumulated = allocated (mirrors legacy)
        $rem = $alloc;
        $ins->bind_param('siiddd', $empStringId, $leaveTypeId, $fyId, $alloc, $acc, $rem);
        if (!$ins->execute()) {
            throw new \RuntimeException('Failed to create leave balance: ' . $this->db->error);
        }
    }

    private function getAnnualLeaveTypeId(): int
    {
        $res = $this->db->query("SELECT id FROM leave_types WHERE name LIKE '%annual%' LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        return $row ? (int) $row['id'] : 1;
    }

    private function getTargetLeaveTypeId(int $leaveTypeId): int
    {
        $res = $this->db->query("SELECT deducted_from_annual FROM leave_types WHERE id = " . (int) $leaveTypeId . " LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        if ($row && ((int) $row['deducted_from_annual'] === 1 || $leaveTypeId === 7)) {
            return 1;
        }
        return $leaveTypeId;
    }

    // ══════════════════════════════════════════════════════════════════════
    //  History & notifications
    // ══════════════════════════════════════════════════════════════════════

    private function logHistory(int $applicationId, int $userId, string $action, string $comment): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO leave_history (leave_application_id, action, performed_by, comments, performed_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param('isis', $applicationId, $action, $userId, $comment);
        @$stmt->execute();
    }

    private function getApplicantUserId(int $employeeId): ?int
    {
        $stmt = $this->db->prepare("
            SELECT u.id FROM users u
            JOIN employees e ON e.employee_id = u.employee_id
            WHERE e.id = ? LIMIT 1
        ");
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ? (int) $row['id'] : null;
    }

    /**
     * Notify the employee by inserting into notifications.
     */
    private function notifyApplicant(array $app, string $title, string $message, string $category = 'leave'): void
    {
        $userId = $this->getApplicantUserId((int) $app['emp_internal_id']);
        if (!$userId) {
            return;
        }
        $stmt = $this->db->prepare("
            INSERT INTO notifications (user_id, title, message, type, category, is_read, created_at)
            VALUES (?, ?, ?, 'leave_status', ?, 0, NOW())
        ");
        $stmt->bind_param('isss', $userId, $title, $message, $category);
        @$stmt->execute();
    }

    private function notifyApplicantAdvanced(array $app, string $newStatus): void
    {
        $label = $this->pendingApproverLabel($newStatus);
        $humanStatus = $newStatus === 'approved' ? 'fully approved' : "advanced to {$label}";
        $this->notifyApplicant(
            $app,
            'Leave application update',
            "Your leave application has been {$humanStatus}.",
        );
    }

    private function notifyApplicantRejected(array $app, string $reason): void
    {
        $this->notifyApplicant(
            $app,
            'Leave application rejected',
            'We regret to inform you that your leave application has been rejected. Reason: ' . $reason,
        );
    }

    private function notifyApplicantInvalidated(array $app, string $reason): void
    {
        $this->notifyApplicant(
            $app,
            'Leave application invalidated',
            'Your leave application has been invalidated and returned for reapplication. Reason: ' . $reason,
        );
    }
}

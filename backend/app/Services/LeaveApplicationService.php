<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use App\Services\Leave\LeaveTypePolicy;
use App\Services\Leave\LeaveWorkflowRules;

/**
 * LeaveApplicationService
 *
 * Orchestrates the leave application submission flow.
 * Uses DB::transaction for atomicity.
 */
class LeaveApplicationService
{
    private \mysqli $db;
    private LeaveCalculationService $calculationService;
    private LeaveDocumentService $documentService;
    private LeaveWorkflowService $workflowService;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->calculationService = new LeaveCalculationService();
        $this->documentService = new LeaveDocumentService();
        $this->workflowService = new LeaveWorkflowService();
    }

    /**
     * Submit a new leave application.
     *
     * @param array $data
     * @param array|null $file
     * @return array
     */
    public function submitApplication(array $data, ?array $file = null): array
    {
        $employeeId = (int) ($data['employee_id'] ?? 0);
        $leaveTypeId = (int) ($data['leave_type_id'] ?? 0);
        $startDate = $data['start_date'] ?? '';
        $endDate = $data['end_date'] ?? '';
        $reason = trim($data['reason'] ?? '');
        $userId = (int) ($data['user_id'] ?? 0);
        $delegateEmpId = (int) ($data['delegate_emp_id'] ?? 0);

        if (!$employeeId || !$leaveTypeId || !$startDate || !$endDate || !$delegateEmpId) {
            return ['success' => false, 'message' => 'Missing required fields, including delegate.'];
        }

        // Authorization check: verify user can submit leave for this employee
        $authCheck = $this->verifyEmployeeAuthorization($userId, $employeeId);
        if (!$authCheck['authorized']) {
            return ['success' => false, 'message' => 'Access denied: You are not authorized to submit leave for this employee.'];
        }

        // Authorization check: verify user can select this delegate
        $delegateAuthCheck = $this->verifyDelegateAuthorization($userId, $delegateEmpId);
        if (!$delegateAuthCheck['authorized']) {
            return ['success' => false, 'message' => 'Access denied: You are not authorized to select this delegate.'];
        }

        $leaveType = $this->getLeaveType($leaveTypeId);
        if (!$leaveType) {
            return ['success' => false, 'message' => 'Invalid leave type.'];
        }

        // Business rule: Claim a Day must be for past or current dates only.
        // It credits annual leave for a day actually worked, so a future date is nonsense.
        $today = date('Y-m-d');
        if ($leaveTypeId === LeaveTypePolicy::TYPE_CLAIM_A_DAY) {
            if ($startDate > $today || $endDate > $today) {
                return [
                    'success' => false,
                    'message' => 'Claim a Day cannot be applied for future dates. Use it for past or current days you actually worked.',
                ];
            }
        }

        // Business rule: Backdating is only allowed for Sick, Study and
        // Claim a Day (see LeaveTypePolicy::allowsBackdate()). All other
        // leave types must start today or later.
        if (!LeaveTypePolicy::allowsBackdate($leaveTypeId) && $startDate < $today) {
            return [
                'success' => false,
                'message' => 'Backdating is not allowed for this leave type. The start date must be today or later.',
            ];
        }

        // Calculate eligible days
        $eligibleDays = $this->calculationService->calculateEligibleDays($startDate, $endDate, $leaveType);

        // Business rule: Annual Leave requires a minimum of 15 days.
        // Legacy behaviour: redirect the user to Short Leave instead.
        if ($leaveTypeId === LeaveTypePolicy::TYPE_ANNUAL && $eligibleDays > 0 && $eligibleDays < 15) {
            return [
                'success' => false,
                'message' => "Annual leave requires at least 15 days ({$eligibleDays} requested). Please apply for Short Leave instead.",
                'eligible_days' => $eligibleDays,
                'suggested_leave_type_id' => LeaveTypePolicy::TYPE_SHORT,
            ];
        }

        // Zero-day validation
        if ($eligibleDays <= 0) {
            return [
                'success' => false,
                'message' => 'No eligible leave days. The selected range contains only weekends/public holidays that are excluded for this leave type.',
                'eligible_days' => 0,
            ];
        }

        // Document requirement
        if ($this->documentService->requiresDocument($leaveTypeId)) {
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                return ['success' => false, 'message' => LeaveTypePolicy::documentMessage($leaveTypeId)];
            }

            $validation = $this->documentService->validateDocument($file);
            if (!$validation['valid']) {
                return ['success' => false, 'message' => implode(' ', $validation['errors'])];
            }
        }

        // Atomic transaction: the mutable-state consistency checks (pending/
        // on-leave block, balance sufficiency, date overlap) run INSIDE the
        // transaction after serialising concurrent submissions per employee
        // with a locking read, so two simultaneous requests can never both
        // pass the checks and both write.
        $this->db->begin_transaction();

        try {
            // Serialisation point: lock the employee row. Concurrent
            // submissions for the same employee queue here and each
            // transaction re-reads balances/overlaps only after the
            // previous one committed.
            $lockStmt = $this->db->prepare("SELECT id FROM employees WHERE id = ? FOR UPDATE");
            $lockStmt->bind_param('i', $employeeId);
            $lockStmt->execute();
            $lockStmt->close();

            // Business rule: Except for Sick Leave, an employee cannot submit
            // a new application while they have a pending application or are
            // currently on an approved leave (today falls within the range).
            if (!LeaveTypePolicy::exemptFromOverlapBlock($leaveTypeId)) {
                $activeBlock = $this->hasBlockingActiveLeave($employeeId, $today);
                if ($activeBlock) {
                    $this->db->rollback();
                    return [
                        'success' => false,
                        'message' => 'You are currently on leave or have a pending leave application. You cannot submit a new application for this leave type. Sick leave can still be applied.',
                    ];
                }
            }

            // Balance sufficiency (re-read under the lock)
            $deductionPlan = $this->calculationService->calculateDeductionFromBalances($employeeId, $leaveTypeId, $eligibleDays);
            if (!$deductionPlan['is_valid']) {
                $this->db->rollback();
                return ['success' => false, 'message' => implode(' ', $deductionPlan['warnings'])];
            }

            // Date-overlap validation (re-read under the lock)
            $overlaps = $this->hasOverlappingLeave($employeeId, $startDate, $endDate);
            if (!empty($overlaps)) {
                $dates = array_map(function ($l) {
                    return date('M d, Y', strtotime($l['start_date'])) . ' to ' . date('M d, Y', strtotime($l['end_date']));
                }, $overlaps);
                $this->db->rollback();
                return ['success' => false, 'message' => 'Date conflict with: ' . implode('; ', $dates)];
            }
            // Determine workflow (initial status + approver chain)
            $initialStatus = $this->workflowService->determineInitialWorkflowStatus($employeeId, $userId);
            $managers = $this->workflowService->getManagers($employeeId);

            // Insert application
            $applicationId = $this->insertApplication($data, $leaveTypeId, $startDate, $endDate, $eligibleDays, $reason, $initialStatus, $userId, $managers, $deductionPlan, $delegateEmpId);

            // Store document if provided
            if ($file && $file['error'] === UPLOAD_ERR_OK) {
                $documentType = $this->documentService->getRequiredDocumentType($leaveTypeId) ?? 'other';
                $this->documentService->storeDocument($applicationId, $file, $documentType, $userId);
            }

            // Update balances if auto-approved
            if ($initialStatus === 'approved') {
                $this->applyBalanceUpdates($applicationId, $employeeId, $leaveTypeId, $eligibleDays, $deductionPlan);
            }

            // Log transaction
            $this->logTransaction($applicationId, $employeeId, $leaveTypeId, $eligibleDays, $deductionPlan);

            // Log history
            $this->logHistory($applicationId, $userId, $initialStatus, $eligibleDays);

            // Notify delegate about the assignment
            $delegateService = new DelegateService();
            $delegateService->notifyDelegate($applicationId, $delegateEmpId, $userId);

            $this->db->commit();

            return [
                'success' => true,
                'application_id' => $applicationId,
                'status' => $initialStatus,
                'eligible_days' => $eligibleDays,
                'deduction_plan' => $deductionPlan,
            ];
        } catch (\Exception $e) {
            $this->db->rollback();
            \logger()->error('Leave application submission failed', [
                'employee_id' => $data['employee_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Unable to submit your leave application. Please try again.'];
        }
    }

    /**
     * Insert leave application.
     */
    private function insertApplication(array $data, int $leaveTypeId, string $startDate, string $endDate, int $eligibleDays, string $reason, string $status, int $userId, array $managers, array $deductionPlan, int $delegateEmpId): int
    {
        $employeeId = (int) $data['employee_id'];
        $fyId = $this->getCurrentFinancialYearId();

        $stmt = $this->db->prepare("
            INSERT INTO leave_applications
                (employee_id, leave_type_id, financial_year_id, start_date, end_date, days_requested, reason, status, applied_at,
                 subsection_head_emp_id, section_head_emp_id, dept_head_emp_id, delegate_emp_id, primary_days, annual_days, applied_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)
        ");

        $primaryDays = (int) $deductionPlan['primary_deduction'];
        $annualDays = (int) $deductionPlan['annual_deduction'];

        // bind_param() requires variables (references), so assign first.
        $subsectionHeadEmpId = $managers['subsection_head_emp_id'] ?? null;
        $sectionHeadEmpId    = $managers['section_head_emp_id'] ?? null;
        $deptHeadEmpId       = $managers['dept_head_emp_id'] ?? null;

        $stmt->bind_param(
            'iiissisiiiiiii',
            $employeeId,
            $leaveTypeId,
            $fyId,
            $startDate,
            $endDate,
            $eligibleDays,
            $reason,
            $status,
            $subsectionHeadEmpId,
            $sectionHeadEmpId,
            $deptHeadEmpId,
            $delegateEmpId,
            $primaryDays,
            $annualDays,
            $userId
        );
        $stmt->execute();

        // AUTO_INCREMENT primary key — never compute MAX(id)+1 manually
        // (a non-locking snapshot that serialises/duplicates under concurrency).
        $applicationId = (int) $stmt->insert_id;
        $stmt->close();

        return $applicationId;
    }

    /**
     * Apply balance updates for approved applications.
     */
    private function applyBalanceUpdates(int $applicationId, int $employeeId, int $leaveTypeId, int $eligibleDays, array $deductionPlan): void
    {
        $fyId = $this->getCurrentFinancialYearId();

        if ($deductionPlan['primary_deduction'] > 0) {
            $stmt = $this->db->prepare("
                UPDATE employee_leave_balances
                SET used_days = used_days + ?, remaining_days = remaining_days - ?
                WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?
            ");
            $stmt->bind_param('ddiii', $deductionPlan['primary_deduction'], $deductionPlan['primary_deduction'], $employeeId, $leaveTypeId, $fyId);
            $stmt->execute();
        }

        if (!empty($deductionPlan['add_to_annual'])) {
            $annualTypeId = $this->getAnnualLeaveTypeId();
            $stmt = $this->db->prepare("
                UPDATE employee_leave_balances
                SET allocated_days = allocated_days + ?, accumulated_days = accumulated_days + ?, remaining_days = remaining_days + ?
                WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?
            ");
            $add = (float) $deductionPlan['add_to_annual'];
            $stmt->bind_param('dddiii', $add, $add, $add, $employeeId, $annualTypeId, $fyId);
            $stmt->execute();
        } elseif ($deductionPlan['annual_deduction'] > 0) {
            $annualTypeId = $this->getAnnualLeaveTypeId();
            $stmt = $this->db->prepare("
                UPDATE employee_leave_balances
                SET used_days = used_days + ?, remaining_days = remaining_days - ?
                WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?
            ");
            $stmt->bind_param('ddiii', $deductionPlan['annual_deduction'], $deductionPlan['annual_deduction'], $employeeId, $annualTypeId, $fyId);
            $stmt->execute();
        }
    }

    /**
     * Log leave transaction.
     */
    private function logTransaction(int $applicationId, int $employeeId, int $leaveTypeId, int $days, array $deductionPlan): void
    {
        $transactionData = [
            'primary_leave_type' => $leaveTypeId,
            'primary_days' => $deductionPlan['primary_deduction'],
            'annual_days' => $deductionPlan['annual_deduction'],
            'unpaid_days' => $deductionPlan['unpaid_days'],
            'warnings' => implode('; ', $deductionPlan['warnings']),
        ];
        $stmt = $this->db->prepare("
            INSERT INTO leave_transactions (application_id, employee_id, transaction_date, transaction_type, details)
            VALUES (?, ?, NOW(), 'deduction', ?)
        ");
        $details = json_encode($transactionData);
        $stmt->bind_param('iis', $applicationId, $employeeId, $details);
        $stmt->execute();
    }

    /**
     * Log leave history.
     */
    private function logHistory(int $applicationId, int $userId, string $status, int $days): void
    {
        $action = ($status === 'approved') ? 'auto-approved' : 'applied';
        $comment = ($status === 'approved') ? "Leave application auto-approved for {$days} days" : "Leave application submitted for {$days} days";
        $stmt = $this->db->prepare("
            INSERT INTO leave_history (leave_application_id, action, performed_by, comments, performed_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param('isis', $applicationId, $action, $userId, $comment);
        $stmt->execute();
    }

    /**
     * Check overlapping leave.
     */
    private function hasOverlappingLeave(int $employeeId, string $startDate, string $endDate): array
    {
        // Any non-final application blocks the requested range: every pending
        // workflow stage plus approved. Final states (rejected, cancelled,
        // invalidated) never block. The status list comes from the single
        // source of truth (LeaveWorkflowRules) — the previous hand-written
        // list silently missed the 'pending', 'pending_manager' and
        // 'pending_hr_manager' stages. The inclusive interval predicate
        // covers partial overlaps, contained ranges and containing ranges;
        // adjacent (touching) ranges do not overlap.
        $statuses = array_merge(
            LeaveWorkflowRules::PENDING_STATUSES,
            [LeaveWorkflowRules::STATUS_APPROVED]
        );
        $statusList = implode(',', array_map(
            fn (string $status): string => "'" . $this->db->real_escape_string($status) . "'",
            $statuses
        ));

        $query = "
            SELECT id, start_date, end_date, status, leave_type_id
            FROM leave_applications
            WHERE employee_id = ?
              AND start_date <= ?
              AND end_date >= ?
              AND status IN ({$statusList})
        ";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('iss', $employeeId, $endDate, $startDate);
        $stmt->execute();
        $result = $stmt->get_result();
        $overlaps = [];
        while ($row = $result->fetch_assoc()) {
            $overlaps[] = $row;
        }
        return $overlaps;
    }

    /**
     * Check if the employee currently has a blocking active leave:
     *   - any pending application, OR
     *   - an approved application whose range covers today (currently on leave).
     *
     * Used to enforce "cannot apply while on leave or with a pending
     * application" for all leave types except Sick Leave (see
     * LeaveTypePolicy::exemptFromOverlapBlock()).
     */
    private function hasBlockingActiveLeave(int $employeeId, string $today): bool
    {
        $statusList = implode(',', array_map(
            fn (string $status): string => "'" . $this->db->real_escape_string($status) . "'",
            LeaveWorkflowRules::PENDING_STATUSES
        ));

        $query = "
            SELECT COUNT(*) AS cnt
            FROM leave_applications
            WHERE employee_id = ?
              AND (
                    status IN ({$statusList})
                    OR (status = 'approved' AND start_date <= ? AND end_date >= ?)
                  )
        ";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('iss', $employeeId, $today, $today);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['cnt'] ?? 0) > 0;
    }

    /**
     * Verify if the user is authorized to submit leave for the given employee.
     */
    private function verifyEmployeeAuthorization(int $userId, int $targetEmployeeId): array
    {
        $db = $this->db;
        
        // Get current user's employee record and role
        $stmt = $db->prepare("
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
            return ['authorized' => false];
        }

        $role = $currentUser['user_role'] ?? 'officer';

        // Super admin and managing director can do anything
        if ($role === 'super_admin' || $role === 'managing_director' || $role === 'hr_manager') {
            return ['authorized' => true];
        }

        // Get target employee
        $targetEmployee = $this->getEmployee($targetEmployeeId);
        if (!$targetEmployee) {
            return ['authorized' => false];
        }

        switch ($role) {
            case 'officer':
                // Officer can only submit for themselves
                return ['authorized' => $currentUser['id'] == $targetEmployeeId];

            case 'sub_section_head':
                // Sub-section head can submit for employees in their subsection
                return ['authorized' => $currentUser['subsection_id'] == $targetEmployee['subsection_id']];

            case 'section_head':
                // Section head can submit for employees in their section
                return ['authorized' => $currentUser['section_id'] == $targetEmployee['section_id']];

            case 'dept_head':
                // Department head can submit for employees in their department
                return ['authorized' => $currentUser['department_id'] == $targetEmployee['department_id']];

            default:
                return ['authorized' => false];
        }
    }

    /**
     * Verify if the user is authorized to select the given delegate.
     */
    private function verifyDelegateAuthorization(int $userId, int $delegateEmployeeId): array
    {
        $db = $this->db;
        
        // Get current user's employee record and role
        $stmt = $db->prepare("
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
            return ['authorized' => false];
        }

        $role = $currentUser['user_role'] ?? 'officer';

        // Super admin and managing director can do anything
        if ($role === 'super_admin' || $role === 'managing_director') {
            return ['authorized' => true];
        }

        // Get delegate employee
        $delegate = $this->getEmployee($delegateEmployeeId);
        if (!$delegate) {
            return ['authorized' => false];
        }

        // A delegate is authorized if they are within the logged-in user's
        // organisational scope (same subsection / section / department),
        // matching the candidate list shown in the dropdown.
        switch ($role) {
            case 'officer':
            case 'sub_section_head':
                // Same subsection; fall back to same section when no subsection is assigned.
                if (!empty($currentUser['subsection_id'])) {
                    return ['authorized' => $currentUser['subsection_id'] == $delegate['subsection_id']];
                }
                return ['authorized' => !empty($currentUser['section_id']) && $currentUser['section_id'] == $delegate['section_id']];

            case 'section_head':
                // Same section
                return ['authorized' => !empty($currentUser['section_id']) && $currentUser['section_id'] == $delegate['section_id']];

            case 'dept_head':
                // Same department
                return ['authorized' => !empty($currentUser['department_id']) && $currentUser['department_id'] == $delegate['department_id']];

            case 'hr_manager':
                // HR or Admin department — always include the HR manager's own
                // department since it is the HR department, even if its name
                // does not contain "hr"/"human resource"/"admin".
                $hrDept = $this->getHRDepartmentId();
                $adminDept = $this->getAdminDepartmentId();
                $allowedDepts = array_filter([$currentUser['department_id'] ?? 0, $hrDept, $adminDept]);
                return ['authorized' => in_array($delegate['department_id'], $allowedDepts, true)];

            default:
                return ['authorized' => false];
        }
    }

    /**
     * Get employee by ID.
     */
    private function getEmployee(int $employeeId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM employees WHERE id = ? AND employee_status = 'active'");
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result ?: null;
    }

    /**
     * Get employee's role.
     */
    private function getEmployeeRole(int $employeeId): string
    {
        $stmt = $this->db->prepare("
            SELECT u.role 
            FROM users u 
            JOIN employees e ON e.employee_id = u.employee_id 
            WHERE e.id = ?
        ");
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result['role'] ?? '';
    }

    /**
     * Get HR department ID.
     */
    private function getHRDepartmentId(): int
    {
        $stmt = $this->db->prepare("SELECT id FROM departments WHERE name LIKE '%hr%' OR name LIKE '%human resource%' LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result ? (int) $result['id'] : 0;
    }

    /**
     * Get Admin department ID.
     */
    private function getAdminDepartmentId(): int
    {
        $stmt = $this->db->prepare("SELECT id FROM departments WHERE name LIKE '%admin%' LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result ? (int) $result['id'] : 0;
    }

    /**
     * Get leave type details.
     */
    private function getLeaveType(int $leaveTypeId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM leave_types WHERE id = ? AND is_active = 1");
        $stmt->bind_param('i', $leaveTypeId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ?: null;
    }

    /**
     * Get current financial year ID.
     */
    private function getCurrentFinancialYearId(): int
    {
        $stmt = $this->db->prepare("SELECT id FROM financial_years WHERE end_date >= CURDATE() ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        if ($result) {
            return (int) $result['id'];
        }
        $stmt = $this->db->prepare("SELECT id FROM financial_years ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ? (int) $result['id'] : 0;
    }

    /**
     * Get annual leave type ID.
     */
    private function getAnnualLeaveTypeId(): int
    {
        $stmt = $this->db->prepare("SELECT id FROM leave_types WHERE name LIKE '%annual%' LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ? (int) $result['id'] : 1;
    }
}
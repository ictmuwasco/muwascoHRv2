<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;

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

        // Business rule: Claim a Day (id 9) must be for past or current dates only.
        // It credits annual leave for a day actually worked, so a future date is nonsense.
        $today = date('Y-m-d');
        if ($leaveTypeId === 9) {
            if ($startDate > $today || $endDate > $today) {
                return [
                    'success' => false,
                    'message' => 'Claim a Day cannot be applied for future dates. Use it for past or current days you actually worked.',
                ];
            }
        }

        // Calculate eligible days
        $eligibleDays = $this->calculationService->calculateEligibleDays($startDate, $endDate, $leaveType);

        // Business rule: Annual Leave (id 1) requires a minimum of 15 days.
        // Legacy behaviour: redirect the user to Short Leave instead.
        if ($leaveTypeId === 1 && $eligibleDays > 0 && $eligibleDays < 15) {
            return [
                'success' => false,
                'message' => "Annual leave requires at least 15 days ({$eligibleDays} requested). Please apply for Short Leave instead.",
                'eligible_days' => $eligibleDays,
                'suggested_leave_type_id' => 6,
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

        // Calculate deduction
        $deductionPlan = $this->calculationService->calculateDeductionFromBalances($employeeId, $leaveTypeId, $eligibleDays);
        if (!$deductionPlan['is_valid']) {
            return ['success' => false, 'message' => implode(' ', $deductionPlan['warnings'])];
        }

        // Document requirement
        if ($this->documentService->requiresDocument($leaveTypeId)) {
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                $requiredType = $this->documentService->getRequiredDocumentType($leaveTypeId);
                if ($leaveTypeId === 2) {
                    return ['success' => false, 'message' => 'A supporting medical document is required for Sick Leave.'];
                }
                if ($leaveTypeId === 5) {
                    return ['success' => false, 'message' => 'A supporting document such as a timetable is required for Study Leave.'];
                }
                return ['success' => false, 'message' => 'Supporting document required.'];
            }

            $validation = $this->documentService->validateDocument($file);
            if (!$validation['valid']) {
                return ['success' => false, 'message' => implode(' ', $validation['errors'])];
            }
        }

        // Overlap validation
        $overlaps = $this->hasOverlappingLeave($employeeId, $startDate, $endDate);
        if (!empty($overlaps)) {
            $dates = array_map(function ($l) {
                return date('M d, Y', strtotime($l['start_date'])) . ' to ' . date('M d, Y', strtotime($l['end_date']));
            }, $overlaps);
            return ['success' => false, 'message' => 'Date conflict with: ' . implode('; ', $dates)];
        }

        // Determine workflow
        $initialStatus = $this->workflowService->determineInitialWorkflowStatus($employeeId, $userId);
        $managers = $this->workflowService->getManagers($employeeId);

        // Atomic transaction
        $this->db->begin_transaction();

        try {
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

        $nextId = (int) $this->db->query("SELECT COALESCE(MAX(id),0)+1 as next_id FROM leave_applications")->fetch_assoc()['next_id'];

        $stmt = $this->db->prepare("
            INSERT INTO leave_applications
                (id, employee_id, leave_type_id, financial_year_id, start_date, end_date, days_requested, reason, status, applied_at,
                 subsection_head_emp_id, section_head_emp_id, dept_head_emp_id, delegate_emp_id, primary_days, annual_days, applied_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)
        ");

        $primaryDays = (int) $deductionPlan['primary_deduction'];
        $annualDays = (int) $deductionPlan['annual_deduction'];

        // bind_param() requires variables (references), so assign first.
        $subsectionHeadEmpId = $managers['subsection_head_emp_id'] ?? null;
        $sectionHeadEmpId    = $managers['section_head_emp_id'] ?? null;
        $deptHeadEmpId       = $managers['dept_head_emp_id'] ?? null;

        $stmt->bind_param(
            'iiiississiiiiiii',
            $nextId,
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

        return $nextId;
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
        $query = "
            SELECT id, start_date, end_date, status, leave_type_id
            FROM leave_applications
            WHERE employee_id = ?
              AND ((start_date <= ? AND end_date >= ?)
                OR (start_date BETWEEN ? AND ?)
                OR (end_date BETWEEN ? AND ?)
                OR (? BETWEEN start_date AND end_date)
                OR (? BETWEEN start_date AND end_date))
              AND status IN ('pending_subsection_head','pending_section_head','pending_dept_head','pending_managing_director','pending_hr','pending_bod_chair','approved')
        ";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('issssssss', $employeeId, $endDate, $startDate, $startDate, $endDate, $startDate, $endDate, $startDate, $endDate);
        $stmt->execute();
        $result = $stmt->get_result();
        $overlaps = [];
        while ($row = $result->fetch_assoc()) {
            $overlaps[] = $row;
        }
        return $overlaps;
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
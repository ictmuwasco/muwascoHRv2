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

        $leaveType = $this->getLeaveType($leaveTypeId);
        if (!$leaveType) {
            return ['success' => false, 'message' => 'Invalid leave type.'];
        }

        // Calculate eligible days
        $eligibleDays = $this->calculationService->calculateEligibleDays($startDate, $endDate, $leaveType);

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
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
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
            $managers['subsection_head_emp_id'] ?? null,
            $managers['section_head_emp_id'] ?? null,
            $managers['dept_head_emp_id'] ?? null,
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
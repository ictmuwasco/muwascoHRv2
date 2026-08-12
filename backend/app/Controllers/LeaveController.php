<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\LeaveApplicationService;
use App\Services\LeaveCalculationService;
use App\Services\LeaveDocumentService;
use App\Services\LeaveWorkflowService;
use App\Helpers\Auth;

/**
 * LeaveController
 *
 * Handles leave application API endpoints.
 * Uses new service layer for business logic.
 */
class LeaveController
{
    private LeaveApplicationService $applicationService;
    private LeaveCalculationService $calculationService;
    private LeaveDocumentService $documentService;
    private LeaveWorkflowService $workflowService;

    public function __construct()
    {
        $this->applicationService = new LeaveApplicationService();
        $this->calculationService = new LeaveCalculationService();
        $this->documentService = new LeaveDocumentService();
        $this->workflowService = new LeaveWorkflowService();
    }

    /**
     * GET /api/leave
     * List leave applications.
     */
    public function indexAction(): void
    {
        $userId = Auth::getInstance()->id();
        
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
            return;
        }

        $db = \App\Helpers\Database::getInstance()->getConnection();
        
        $query = "
            SELECT la.*, 
                   e.first_name, e.last_name, e.employee_id,
                   lt.name as leave_type_name,
                   de.first_name as delegate_first_name, de.last_name as delegate_last_name
            FROM leave_applications la
            LEFT JOIN employees e ON la.employee_id = e.id
            LEFT JOIN leave_types lt ON la.leave_type_id = lt.id
            LEFT JOIN employees de ON la.delegate_emp_id = de.id
            ORDER BY la.applied_at DESC
        ";
        $result = $db->query($query);
        $leaves = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

        // Map to frontend expected format
        $mapped = array_map(function ($row) {
            return [
                'id' => (int) $row['id'],
                'employee_name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                'leave_type' => $row['leave_type_name'] ?? '',
                'start_date' => $row['start_date'] ?? '',
                'end_date' => $row['end_date'] ?? '',
                'days_requested' => (int) ($row['days_requested'] ?? 0),
                'delegate_name' => trim(($row['delegate_first_name'] ?? '') . ' ' . ($row['delegate_last_name'] ?? '')),
                'status' => $row['status'] ? ucfirst(strtolower($row['status'])) : '',
                'reason' => $row['reason'] ?? '',
            ];
        }, $leaves);

        echo json_encode([
            'success' => true,
            'data' => $mapped,
        ]);
    }

    /**
     * POST /api/leave/applications
     * Submit a new leave application.
     */
    public function applyAction(): void
    {
        $userId = Auth::getInstance()->id();
        
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
            return;
        }

        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $leaveTypeId = (int) ($_POST['leave_type_id'] ?? 0);
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        $delegateEmpId = (int) ($_POST['delegate_emp_id'] ?? 0);

        // Validate required fields
        if (!$employeeId || !$leaveTypeId || !$startDate || !$endDate || !$delegateEmpId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
            return;
        }

        // Handle file upload
        $file = null;
        if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['document'];
        }

        $result = $this->applicationService->submitApplication([
            'employee_id' => $employeeId,
            'leave_type_id' => $leaveTypeId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'delegate_emp_id' => $delegateEmpId,
            'reason' => $reason,
            'user_id' => $userId,
        ], $file);

        if ($result['success']) {
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'message' => 'Leave application submitted successfully.',
                'data' => $result,
            ]);
        } else {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $result['message'],
                'eligible_days' => $result['eligible_days'] ?? 0,
            ]);
        }
    }

    /**
     * GET /api/leave/applications/{id}/documents
     * List documents for a leave application.
     */
    public function listDocumentsAction(int $applicationId): void
    {
        $userId = Auth::getInstance()->id();
        
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
            return;
        }

        $documents = $this->documentService->getDocuments($applicationId);
        
        echo json_encode([
            'success' => true,
            'data' => $documents,
        ]);
    }

    /**
     * GET /api/leave/applications/{id}/documents/{documentId}
     * View/download a specific document.
     */
    public function viewDocumentAction(int $applicationId, int $documentId): void
    {
        $userId = Auth::getInstance()->id();
        
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
            return;
        }

        $document = $this->documentService->getDocument($documentId, $userId);
        
        if (!$document) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Document not found or access denied.']);
            return;
        }

        $filePath = $document['file_path'];
        if (!file_exists($filePath)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'File not found on server.']);
            return;
        }

        // Serve file securely
        header('Content-Type: ' . $document['mime_type']);
        header('Content-Disposition: inline; filename="' . basename($document['original_filename']) . '"');
        header('Content-Length: ' . filesize($filePath));
        header('X-Content-Type-Options: nosniff');
        
        readfile($filePath);
        exit;
    }

    /**
     * POST /api/leave/calculate
     * Calculate eligible days and deduction preview.
     */
    public function calculateAction(): void
    {
        $userId = Auth::getInstance()->id();
        
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
            return;
        }

        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $leaveTypeId = (int) ($_POST['leave_type_id'] ?? 0);
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';

        if (!$employeeId || !$leaveTypeId || !$startDate || !$endDate) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
            return;
        }

        $leaveType = $this->getLeaveType($leaveTypeId);
        if (!$leaveType) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Invalid leave type.']);
            return;
        }

        // Calculate eligible days
        $eligibleDays = $this->calculationService->calculateEligibleDays($startDate, $endDate, $leaveType);

        // Calculate deduction
        $deductionPlan = $this->calculationService->calculateDeductionFromBalances($employeeId, $leaveTypeId, $eligibleDays);

        echo json_encode([
            'success' => true,
            'data' => [
                'eligible_days' => $eligibleDays,
                'deduction_plan' => $deductionPlan,
            ],
        ]);
    }

    /**
     * GET /api/leave/delegates
     * List eligible delegate candidates for current user.
     */
    public function delegatesAction(): void
    {
        $userId = Auth::getInstance()->id();
        
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
            return;
        }

        $delegates = $this->workflowService->getEligibleDelegates($userId);
        
        echo json_encode([
            'success' => true,
            'data' => $delegates,
        ]);
    }

    /**
     * GET /api/leave/types
     * List leave types with balances for an employee.
     */
    public function typesAction(): void
    {
        $userId = Auth::getInstance()->id();
        
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
            return;
        }

        $employeeId = (int) ($_GET['employee_id'] ?? 0);
        if (!$employeeId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing employee_id']);
            return;
        }

        $leaveTypes = $this->getLeaveTypesWithBalances($employeeId);
        
        echo json_encode([
            'success' => true,
            'data' => $leaveTypes,
        ]);
    }

    /**
     * Helper: get leave type.
     */
    private function getLeaveType(int $leaveTypeId): ?array
    {
        $query = "SELECT * FROM leave_types WHERE id = ? AND is_active = 1";
        $stmt = \App\Helpers\Database::getInstance()->getConnection()->prepare($query);
        $stmt->bind_param('i', $leaveTypeId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ?: null;
    }

    /**
     * Get leave types with balances for an employee.
     */
    private function getLeaveTypesWithBalances(int $employeeId): array
    {
        $db = \App\Helpers\Database::getInstance()->getConnection();
        $fyId = $this->getCurrentFinancialYearId();

        $query = "
            SELECT elb.leave_type_id,
                   lt.name               AS leave_type_name,
                   elb.allocated_days,
                   elb.brought_forward_days,
                   elb.accumulated_days,
                   elb.used_days,
                   elb.remaining_days,
                   lt.counts_weekends,
                   lt.count_holidays,
                   lt.deducted_from_annual
            FROM employee_leave_balances elb
            JOIN leave_types lt ON elb.leave_type_id = lt.id
            WHERE elb.employee_id = ?
              AND elb.financial_year_id = ?
              AND lt.is_active = 1
            ORDER BY lt.name
        ";
        $stmt = $db->prepare($query);
        $stmt->bind_param('ii', $employeeId, $fyId);
        $stmt->execute();
        $result = $stmt->get_result();

        $types = [];
        while ($row = $result->fetch_assoc()) {
            $types[] = [
                'leave_type_id'        => (int) $row['leave_type_id'],
                'leave_type_name'      => $row['leave_type_name'],
                'allocated_days'       => (float) $row['allocated_days'],
                'brought_forward_days' => (float) $row['brought_forward_days'],
                'accumulated_days'     => (float) $row['accumulated_days'],
                'used_days'            => (float) $row['used_days'],
                'remaining_days'       => (float) $row['remaining_days'],
                'counts_weekends'      => (int) $row['counts_weekends'],
                'count_holidays'       => (int) $row['count_holidays'],
                'deducted_from_annual' => (int) $row['deducted_from_annual'],
            ];
        }

        return $types;
    }

    /**
     * Get current financial year ID.
     */
    private function getCurrentFinancialYearId(): int
    {
        $db = \App\Helpers\Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT id FROM financial_years WHERE end_date >= CURDATE() ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        if ($result) {
            return (int) $result['id'];
        }
        $stmt = $db->prepare("SELECT id FROM financial_years ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ? (int) $result['id'] : 0;
    }
}

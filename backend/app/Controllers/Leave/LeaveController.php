<?php

declare(strict_types=1);

namespace App\Controllers\Leave;

use App\Services\LeaveApplicationService;
use App\Services\LeaveApprovalService;
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
    private LeaveApprovalService $approvalService;
    private LeaveCalculationService $calculationService;
    private LeaveDocumentService $documentService;
    private LeaveWorkflowService $workflowService;

    public function __construct()
    {
        $this->applicationService = new LeaveApplicationService();
        $this->approvalService = new LeaveApprovalService();
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

        $currentUser = Auth::getInstance()->user();
        $employeeId = $currentUser['employee_id'] ?? null;

        $db = \App\Helpers\Database::getInstance()->getConnection();
        
        // If user has an employee_id, filter to show only their leave applications
        if ($employeeId) {
            $query = "
                SELECT la.*, 
                       e.first_name, e.last_name, e.employee_id,
                       lt.name as leave_type_name,
                       de.first_name as delegate_first_name, de.last_name as delegate_last_name
                FROM leave_applications la
                LEFT JOIN employees e ON la.employee_id = e.id
                LEFT JOIN leave_types lt ON la.leave_type_id = lt.id
                LEFT JOIN employees de ON la.delegate_emp_id = de.id
                WHERE la.employee_id = ?
                ORDER BY la.applied_at DESC
            ";
            $stmt = $db->prepare($query);
            $stmt->bind_param('i', $employeeId);
            $stmt->execute();
            $result = $stmt->get_result();
            $leaves = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        } else {
            // Fallback: show all if no employee_id (shouldn't happen for normal users)
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
        }

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
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_LEAVE,
                \App\Services\AuditService::ACTION_CREATE,
                'Submitted leave application',
                ['target_type' => 'LeaveApplication', 'target_id' => $result['id'] ?? ($result['application_id'] ?? null), 'metadata' => ['leave_type_id' => $leaveTypeId, 'start_date' => $startDate, 'end_date' => $endDate]]
            );
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
     * GET /api/leave/eligible-employees
     * Get employees eligible for selection based on logged-in user's role.
     */
    public function eligibleEmployeesAction(): void
    {
        try {
            $userId = Auth::getInstance()->id();
            
            if (!$userId) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
                return;
            }

            $currentUser = Auth::getInstance()->user();
            $employeeId = $currentUser['employee_id'] ?? null;

            if (!$employeeId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Employee record not found']);
                return;
            }

            $db = \App\Helpers\Database::getInstance()->getConnection();
            
            // Get current user's employee record
            $stmt = $db->prepare("
                SELECT e.*, u.role as user_role 
                FROM employees e 
                JOIN users u ON u.employee_id = e.employee_id 
                WHERE u.id = ? AND e.employee_status = 'active'
            ");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $currentEmployee = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$currentEmployee) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                return;
            }

            $role = $currentEmployee['user_role'] ?? 'officer';
            $employees = [];

            // Filter based on role
            switch ($role) {
                case 'officer':
                    // Officer can only select themselves
                    $employees[] = [
                        'id' => (int) $currentEmployee['id'],
                        'first_name' => $currentEmployee['first_name'],
                        'last_name' => $currentEmployee['last_name'],
                        'employee_id' => $currentEmployee['employee_id'],
                    ];
                    break;

                case 'sub_section_head':
                    // Sub-section head can select employees in their subsection (including self)
                    $stmt = $db->prepare("
                        SELECT e.id, e.first_name, e.last_name, e.employee_id
                        FROM employees e
                        WHERE e.subsection_id = ? 
                        AND e.employee_status = 'active'
                        ORDER BY e.first_name, e.last_name
                    ");
                    $stmt->bind_param('i', $currentEmployee['subsection_id']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $employees[] = $row;
                    }
                    $stmt->close();
                    // Add self
                    $employees[] = [
                        'id' => (int) $currentEmployee['id'],
                        'first_name' => $currentEmployee['first_name'],
                        'last_name' => $currentEmployee['last_name'],
                        'employee_id' => $currentEmployee['employee_id'],
                    ];
                    break;

                case 'section_head':
                    // Section head can select:
                    //  - everyone in their section (incl. self) — sub_section_heads + officers
                    //  - dept_head(s) in their department (so the section_head can apply
                    //    on behalf of the dept_head when needed)
                    $stmt = $db->prepare("
                        SELECT e.id, e.first_name, e.last_name, e.employee_id, u.role
                        FROM employees e
                        LEFT JOIN users u ON u.employee_id = e.employee_id
                        WHERE (e.section_id = ? OR (u.role = 'dept_head' AND e.department_id = ?))
                        AND e.employee_status = 'active'
                        ORDER BY u.role DESC, e.first_name, e.last_name
                    ");
                    $stmt->bind_param('ii', $currentEmployee['section_id'], $currentEmployee['department_id']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $employees[] = $row;
                    }
                    $stmt->close();
                    break;

                case 'dept_head':
                    // Department head can select employees in their department (including self)
                    $stmt = $db->prepare("
                        SELECT e.id, e.first_name, e.last_name, e.employee_id
                        FROM employees e
                        WHERE e.department_id = ? 
                        AND e.employee_status = 'active'
                        ORDER BY e.first_name, e.last_name
                    ");
                    $stmt->bind_param('i', $currentEmployee['department_id']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $employees[] = $row;
                    }
                    $stmt->close();
                    break;

                case 'hr_manager':
                case 'managing_director':
                case 'super_admin':
                    // These roles can see all active employees
                    $stmt = $db->prepare("
                        SELECT e.id, e.first_name, e.last_name, e.employee_id
                        FROM employees e
                        WHERE e.employee_status = 'active'
                        ORDER BY e.first_name, e.last_name
                    ");
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $employees[] = $row;
                    }
                    $stmt->close();
                    break;

                default:
                    // Default: only self
                    $employees[] = [
                        'id' => (int) $currentEmployee['id'],
                        'first_name' => $currentEmployee['first_name'],
                        'last_name' => $currentEmployee['last_name'],
                        'employee_id' => $currentEmployee['employee_id'],
                    ];
            }

            echo json_encode([
                'success' => true,
                'data' => $employees,
            ]);
        } catch (\Exception $e) {
            error_log('Error in eligibleEmployeesAction: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to load employees: ' . $e->getMessage(),
                'data' => [],
            ]);
        }
    }

    /**
     * GET /api/leave/eligible-delegates
     * Get delegates eligible for selection based on logged-in user's role.
     */
    public function eligibleDelegatesAction(): void
    {
        try {
            $userId = Auth::getInstance()->id();
            
            if (!$userId) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
                return;
            }

            $currentUser = Auth::getInstance()->user();
            $employeeId = $currentUser['employee_id'] ?? null;

            if (!$employeeId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Employee record not found']);
                return;
            }

            $db = \App\Helpers\Database::getInstance()->getConnection();
            
            // Get current user's employee record
            $stmt = $db->prepare("
                SELECT e.*, u.role as user_role 
                FROM employees e 
                JOIN users u ON u.employee_id = e.employee_id 
                WHERE u.id = ? AND e.employee_status = 'active'
            ");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $currentEmployee = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$currentEmployee) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                return;
            }

            $role = $currentEmployee['user_role'] ?? 'officer';
            $delegates = [];

            // Filter based on the logged-in user's organizational scope.
            // A delegate is anyone in the user's scope who can cover their duties
            // while on leave — all active employees in that scope, excluding self.
            switch ($role) {
                case 'officer':
                case 'sub_section_head':
                    // Sub-section scope: all active employees in their subsection.
                    // If the user has no subsection assigned, fall back to their section.
                    $subId = $currentEmployee['subsection_id'] ?? 0;
                    $scopeCol = !empty($subId) ? 'e.subsection_id' : 'e.section_id';
                    $scopeVal = !empty($subId) ? $subId : ($currentEmployee['section_id'] ?? 0);
                    if (!empty($scopeVal)) {
                        $stmt = $db->prepare("
                            SELECT e.id, e.first_name, e.last_name, e.employee_id, COALESCE(u.role, 'officer') AS role
                            FROM employees e
                            LEFT JOIN users u ON u.employee_id = e.employee_id
                            WHERE {$scopeCol} = ?
                            AND e.employee_status = 'active'
                            AND e.id != ?
                            ORDER BY e.first_name, e.last_name
                        ");
                        $stmt->bind_param('ii', $scopeVal, $currentEmployee['id']);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        while ($row = $result->fetch_assoc()) {
                            $delegates[] = $row;
                        }
                        $stmt->close();
                    }
                    break;

                case 'section_head':
                    // Section scope: all active employees in their section.
                    $stmt = $db->prepare("
                        SELECT e.id, e.first_name, e.last_name, e.employee_id, COALESCE(u.role, 'officer') AS role
                        FROM employees e
                        LEFT JOIN users u ON u.employee_id = e.employee_id
                        WHERE e.section_id = ?
                        AND e.employee_status = 'active'
                        AND e.id != ?
                        ORDER BY e.first_name, e.last_name
                    ");
                    $stmt->bind_param('ii', $currentEmployee['section_id'], $currentEmployee['id']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $delegates[] = $row;
                    }
                    $stmt->close();
                    break;

                case 'dept_head':
                    // Department scope: all active employees in their department.
                    $stmt = $db->prepare("
                        SELECT e.id, e.first_name, e.last_name, e.employee_id, COALESCE(u.role, 'officer') AS role
                        FROM employees e
                        LEFT JOIN users u ON u.employee_id = e.employee_id
                        WHERE e.department_id = ?
                        AND e.employee_status = 'active'
                        AND e.id != ?
                        ORDER BY e.first_name, e.last_name
                    ");
                    $stmt->bind_param('ii', $currentEmployee['department_id'], $currentEmployee['id']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $delegates[] = $row;
                    }
                    $stmt->close();
                    break;

                case 'hr_manager':
                    // HR scope: all active employees in HR or Admin departments.
                    // Always include the HR manager's own department since it is
                    // the HR department, even if its name does not contain
                    // "hr"/"human resource"/"admin".
                    // Use LEFT JOIN so employees whose department_id has no row
                    // in the departments table (e.g. the "Human Resources" dept)
                    // are still included via the e.department_id = ? condition.
                    $hrDeptId = (int) ($currentEmployee['department_id'] ?? 0);
                    $stmt = $db->prepare("
                        SELECT e.id, e.first_name, e.last_name, e.employee_id, COALESCE(u.role, 'officer') AS role, COALESCE(d.name, '') as department_name
                        FROM employees e
                        LEFT JOIN users u ON u.employee_id = e.employee_id
                        LEFT JOIN departments d ON d.id = e.department_id
                        WHERE e.employee_status = 'active'
                        AND (d.name LIKE '%hr%' OR d.name LIKE '%human resource%' OR d.name LIKE '%admin%'
                             OR e.department_id = ?)
                        ORDER BY d.name, e.first_name, e.last_name
                    ");
                    $stmt->bind_param('i', $hrDeptId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $delegates[] = $row;
                    }
                    $stmt->close();
                    break;

                case 'managing_director':
                    // Can select all department heads.
                    $stmt = $db->prepare("
                        SELECT e.id, e.first_name, e.last_name, e.employee_id, u.role, d.name as department_name
                        FROM employees e
                        JOIN users u ON u.employee_id = e.employee_id
                        JOIN departments d ON d.id = e.department_id
                        WHERE u.role = 'dept_head'
                        AND e.employee_status = 'active'
                        ORDER BY d.name, e.first_name, e.last_name
                    ");
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $delegates[] = $row;
                    }
                    $stmt->close();
                    break;

                case 'super_admin':
                    // Super admin: all active employees.
                    $stmt = $db->prepare("
                        SELECT e.id, e.first_name, e.last_name, e.employee_id, COALESCE(u.role, 'officer') AS role
                        FROM employees e
                        LEFT JOIN users u ON u.employee_id = e.employee_id
                        WHERE e.employee_status = 'active'
                        AND e.id != ?
                        ORDER BY e.first_name, e.last_name
                    ");
                    $stmt->bind_param('i', $currentEmployee['id']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $delegates[] = $row;
                    }
                    $stmt->close();
                    break;

                default:
                    // Unknown roles: fall back to all active employees except self.
                    $stmt = $db->prepare("
                        SELECT e.id, e.first_name, e.last_name, e.employee_id, COALESCE(u.role, 'officer') AS role
                        FROM employees e
                        LEFT JOIN users u ON u.employee_id = e.employee_id
                        WHERE e.employee_status = 'active'
                        AND e.id != ?
                        ORDER BY e.first_name, e.last_name
                    ");
                    $stmt->bind_param('i', $currentEmployee['id']);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()) {
                        $delegates[] = $row;
                    }
                    $stmt->close();
                    break;
            }

            echo json_encode([
                'success' => true,
                'data' => $delegates,
            ]);
        } catch (\Exception $e) {
            error_log('Error in eligibleDelegatesAction: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to load delegates: ' . $e->getMessage(),
                'data' => [],
            ]);
        }
    }

    /**
     * GET /api/leave/manage
     * List pending / approved / rejected leaves for the current approver.
     */
    public function manageAction(): void
    {
        $userId = Auth::getInstance()->id();

        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
            return;
        }

        $pagination = [
            'limit'           => (int) ($_GET['limit'] ?? 15),
            'pending_offset'  => (int) ($_GET['pending_offset']  ?? 0),
            'approved_offset' => (int) ($_GET['approved_offset'] ?? 0),
            'rejected_offset' => (int) ($_GET['rejected_offset'] ?? 0),
        ];

        $result = $this->approvalService->listForApprover($userId, $pagination);

        $httpCode = ($result['success'] ?? false) ? 200 : 400;
        http_response_code($httpCode);
        echo json_encode($result);
    }

    /**
     * PUT /api/leave/applications/{id}/approve
     * Approve the current pending stage of a leave application.
     */
    public function approveAction(int $applicationId): void
    {
        $userId = Auth::getInstance()->id();

        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
            return;
        }

        $result = $this->approvalService->approve($userId, $applicationId);

        if (($result['success'] ?? false)) {
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_LEAVE,
                \App\Services\AuditService::ACTION_APPROVE,
                'Approved leave application',
                ['target_type' => 'LeaveApplication', 'target_id' => $applicationId]
            );
        }

        $httpCode = ($result['success'] ?? false) ? 200 : 400;
        http_response_code($httpCode);
        echo json_encode($result);
    }

    /**
     * PUT /api/leave/applications/{id}/reject
     * Reject a leave application. Requires reason in JSON body or POST.
     */
    public function rejectAction(int $applicationId): void
    {
        $userId = Auth::getInstance()->id();

        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
            return;
        }

        $reason = $this->readReasonFromBody();
        if ($reason === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'A reason is required to reject leave.']);
            return;
        }

        $result = $this->approvalService->reject($userId, $applicationId, $reason);

        if (($result['success'] ?? false)) {
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_LEAVE,
                \App\Services\AuditService::ACTION_REJECT,
                'Rejected leave application',
                ['target_type' => 'LeaveApplication', 'target_id' => $applicationId, 'metadata' => ['reason' => mb_substr($reason, 0, 1000)]]
            );
        }

        $httpCode = ($result['success'] ?? false) ? 200 : 400;
        http_response_code($httpCode);
        echo json_encode($result);
    }

    /**
     * PUT /api/leave/applications/{id}/invalidate
     * Invalidate a leave application. Requires reason in JSON body or POST.
     */
    public function invalidateAction(int $applicationId): void
    {
        $userId = Auth::getInstance()->id();

        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
            return;
        }

        $reason = $this->readReasonFromBody();
        if ($reason === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'A reason is required to invalidate leave.']);
            return;
        }

        $result = $this->approvalService->invalidate($userId, $applicationId, $reason);

        if (($result['success'] ?? false)) {
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_LEAVE,
                \App\Services\AuditService::ACTION_INVALIDATE,
                'Invalidated leave application',
                ['target_type' => 'LeaveApplication', 'target_id' => $applicationId, 'metadata' => ['reason' => mb_substr($reason, 0, 1000)]]
            );
        }

        $httpCode = ($result['success'] ?? false) ? 200 : 400;
        http_response_code($httpCode);
        echo json_encode($result);
    }

    /**
     * PUT /api/leave/applications/{id}/cancel
     * Cancel a still-pending leave application. Only the applicant may cancel.
     */
    public function cancelAction(int $applicationId): void
    {
        $userId = Auth::getInstance()->id();

        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthenticated']);
            return;
        }

        $result = $this->approvalService->cancel($userId, $applicationId);

        if (($result['success'] ?? false)) {
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_LEAVE,
                \App\Services\AuditService::ACTION_UPDATE,
                'Cancelled leave application',
                ['target_type' => 'LeaveApplication', 'target_id' => $applicationId]
            );
        }

        $httpCode = ($result['success'] ?? false) ? 200 : 400;
        http_response_code($httpCode);
        echo json_encode($result);
    }

    /**
     * Accept a reason from either JSON body or form-encoded POST.
     * (PUT bodies may arrive as either depending on the client.)
     */
    private function readReasonFromBody(): string
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $raw = file_get_contents('php://input') ?: '';
        if (stripos($contentType, 'application/json') !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['reason'])) {
                return trim((string) $decoded['reason']);
            }
        }
        if (isset($_POST['reason'])) {
            return trim((string) $_POST['reason']);
        }
        if ($raw !== '') {
            $kv = [];
            parse_str($raw, $kv);
            if (isset($kv['reason'])) {
                return trim((string) $kv['reason']);
            }
        }
        return '';
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

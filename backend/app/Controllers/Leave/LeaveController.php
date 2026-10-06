<?php

declare(strict_types=1);

namespace App\Controllers\Leave;

use App\Controllers\BaseController;

use App\Services\LeaveApplicationService;
use App\Services\LeaveApprovalService;
use App\Services\LeaveCalculationService;
use App\Services\LeaveDocumentService;
use App\Services\LeaveWorkflowService;
use App\Services\LeaveProfileService;
use App\Helpers\Auth;

/**
 * LeaveController
 *
 * Handles leave application API endpoints.
 * Uses new service layer for business logic.
 */
class LeaveController extends BaseController
{
    private LeaveApplicationService $applicationService;
    private LeaveApprovalService $approvalService;
    private LeaveCalculationService $calculationService;
    private LeaveDocumentService $documentService;
    private LeaveWorkflowService $workflowService;
    private LeaveProfileService $profileService;

    public function __construct()
    {
        $this->applicationService = new LeaveApplicationService();
        $this->approvalService = new LeaveApprovalService();
        $this->calculationService = new LeaveCalculationService();
        $this->documentService = new LeaveDocumentService();
        $this->workflowService = new LeaveWorkflowService();
        $this->profileService = new LeaveProfileService();
    }

    /**
     * employees.id (the INT surrogate PK) for the authenticated user, or 0.
     *
     * The single correct way to turn an account into an employee row in this
     * schema. users.employee_id / employees.employee_id hold the BUSINESS CODE
     * ('242'), while every `*.employee_id` foreign key in the leave tables
     * points at employees.id (514). Confusing the two silently reads — and
     * writes — the wrong employee's records.
     *
     * @return int employees.id, or 0 when the account has no employee record
     */
    private function currentEmployeePk(): int
    {
        $userId = (int) (Auth::getInstance()->id() ?? 0);
        if ($userId <= 0) {
            return 0;
        }

        $db = \App\Helpers\Database::getInstance()->getConnection();
        $stmt = $db->prepare('
            SELECT e.id
            FROM employees e
            JOIN users u ON u.employee_id = e.employee_id
            WHERE u.id = ?
            LIMIT 1
        ');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (int) $row['id'] : 0;
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
            \App\Helpers\ApiResponse::error('Unauthenticated', 'UNAUTHENTICATED', [], 401);
            return;
        }

        $db = \App\Helpers\Database::getInstance()->getConnection();

        // Resolve the employee's SURROGATE key.
        //
        // $currentUser['employee_id'] is the employees.employee_id BUSINESS CODE
        // (e.g. '242', 'ADMIN001'), but leave_applications.employee_id is a FK to
        // employees.id — the INT primary key (e.g. 514). Binding the code straight
        // into `WHERE la.employee_id = ?` matched whichever unrelated employee
        // happened to own that numeric id, so this page listed ANOTHER PERSON'S
        // leave applications — and rendered a blank Delegate column, because
        // those rows predate the delegate feature and carry delegate_emp_id =
        // NULL. Every other service resolves the code through the users→
        // employees join first; do the same here.
        $employeePk = $this->currentEmployeePk();

        // Self-service: this page is "My Leave Applications", so a user with no
        // resolvable employee record gets an empty list. The previous fallback
        // returned EVERY leave application in the organisation, which leaked the
        // whole leave book to any account missing an employee link.
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
        $stmt->bind_param('i', $employeePk);
        $stmt->execute();
        $result = $stmt->get_result();
        $leaves = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        // Map to frontend expected format
        $mapped = array_map(function ($row) {
            return [
                'id' => (int) $row['id'],
                'employee_name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                'leave_type' => $row['leave_type_name'] ?? '',
                'start_date' => $row['start_date'] ?? '',
                'end_date' => $row['end_date'] ?? '',
                'days_requested' => (int) ($row['days_requested'] ?? 0),
                // Applications created before the delegate feature (migration
                // 012) have delegate_emp_id = NULL. Say so explicitly rather
                // than shipping an empty string, which renders as a blank cell
                // and looks like a bug instead of a historical fact.
                'delegate_name' => trim(($row['delegate_first_name'] ?? '') . ' ' . ($row['delegate_last_name'] ?? ''))
                    ?: 'Not recorded',
                'has_delegate'  => !empty($row['delegate_emp_id']),
                'status' => $row['status'] ? ucfirst(strtolower($row['status'])) : '',
                'reason' => $row['reason'] ?? '',
            ];
        }, $leaves);

        \App\Helpers\ApiResponse::success($mapped);
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
            \App\Helpers\ApiResponse::error('Unauthenticated', 'UNAUTHENTICATED', [], 401);
            return;
        }

        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $leaveTypeId = (int) ($_POST['leave_type_id'] ?? 0);
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        $delegateEmpId = (int) ($_POST['delegate_emp_id'] ?? 0);

        // Request validation: shape and format only. Business rules (leave
        // balance, eligibility, overlapping applications) are enforced by
        // LeaveApplicationService and intentionally not duplicated here.
        $this->validateRequest(new \App\Validators\LeaveValidator(), [
            'employee_id'   => $employeeId,
            'leave_type_id' => $leaveTypeId,
            'start_date'    => $startDate,
            'end_date'      => $endDate,
            'reason'        => $reason,
        ]);

        if (!$delegateEmpId) {
            \App\Helpers\ApiResponse::error('A delegate employee is required.', 'VALIDATION_ERROR', ['delegate_emp_id' => 'Delegate is required.'], 422);
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
            \App\Helpers\ApiResponse::success($result, 'Leave application submitted successfully.', 201);
        } else {
            \App\Helpers\ApiResponse::error($result['message'] ?? 'Unable to submit leave application.', 'BUSINESS_RULE', ['eligible_days' => $result['eligible_days'] ?? 0], 400);
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
            \App\Helpers\ApiResponse::error('Unauthenticated', 'UNAUTHENTICATED', [], 401);
            return;
        }

        // Scope: applicant / profile scope / authorised approver. The route is
        // authenticated-only by design (config/authz_allowlist.php), so the
        // per-record gate lives here — without it any signed-in user could
        // enumerate any application's supporting documents.
        if (!$this->documentService->canViewDocuments($applicationId, $userId)) {
            http_response_code(403);
            \App\Helpers\ApiResponse::error(
                'You are not authorised to view documents for this leave application.',
                'ACCESS_DENIED',
                [],
                403
            );
            return;
        }

        $documents = $this->documentService->getDocuments($applicationId);
        
        \App\Helpers\ApiResponse::success($documents);
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
            \App\Helpers\ApiResponse::error('Unauthenticated', 'UNAUTHENTICATED', [], 401);
            return;
        }

        // getDocument() applies the same scope as the list endpoint
        // (applicant / profile scope / authorised approver). The
        // application-id match additionally stops a known document id from
        // being fetched under any other application's URL.
        $document = $this->documentService->getDocument($documentId, $userId);

        if (!$document || (int) $document['leave_application_id'] !== $applicationId) {
            http_response_code(404);
            \App\Helpers\ApiResponse::error('Document not found or access denied.', 'NOT_FOUND', [], 404);
            return;
        }

        $filePath = $document['file_path'];
        if (!file_exists($filePath)) {
            http_response_code(404);
            \App\Helpers\ApiResponse::error('File not found on server.', 'NOT_FOUND', [], 404);
            return;
        }

        // The bytes on disk may be an MWSC1 container (storage encryption also
        // covers leave documents, and this table has no is_encrypted stamp) —
        // raw readfile() would hand the browser ciphertext it cannot render.
        // resolveReadableFile() decrypts to a temp file the caller unlinks.
        try {
            $readable = $this->documentService->resolveReadableFile($document);
        } catch (\Throwable $e) {
            \logger()->error('Leave document read failed', [
                'leave_application_id' => $applicationId,
                'document_id'          => $documentId,
                'error'                => $e->getMessage(),
            ]);
            http_response_code(500);
            \App\Helpers\ApiResponse::error('The document could not be opened.', 'DOCUMENT_READ_FAILED', [], 500);
            return;
        }

        // Phase 7 (P7-7): sandbox CSP + nosniff + no-store; inline only for
        // PDF/images (business in-browser preview), attachment otherwise.
        \App\Middleware\SecurityMiddleware::applyStreamHeaders(
            $readable['mime'],
            $document['original_filename'] ?? 'document'
        );
        header('Content-Length: ' . $readable['bytes']);

        try {
            readfile($readable['path']);
        } finally {
            if ($readable['temporary']) {
                @unlink($readable['path']);
            }
        }
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
            \App\Helpers\ApiResponse::error('Unauthenticated', 'UNAUTHENTICATED', [], 401);
            return;
        }

        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $leaveTypeId = (int) ($_POST['leave_type_id'] ?? 0);
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';

        if (!$employeeId || !$leaveTypeId || !$startDate || !$endDate) {
            http_response_code(400);
            \App\Helpers\ApiResponse::error('Missing required fields.', 'VALIDATION_ERROR', [], 422);
            return;
        }

        $leaveType = $this->getLeaveType($leaveTypeId);
        if (!$leaveType) {
            http_response_code(404);
            \App\Helpers\ApiResponse::error('Invalid leave type.', 'VALIDATION_ERROR', ['field' => 'leave_type_id'], 422);
            return;
        }

        // Calculate eligible days
        $eligibleDays = $this->calculationService->calculateEligibleDays($startDate, $endDate, $leaveType);

        // Calculate deduction
        $deductionPlan = $this->calculationService->calculateDeductionFromBalances($employeeId, $leaveTypeId, $eligibleDays);

        \App\Helpers\ApiResponse::success(['eligible_days' => $eligibleDays, 'deduction_plan' => $deductionPlan]);
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
            \App\Helpers\ApiResponse::error('Unauthenticated', 'UNAUTHENTICATED', [], 401);
            return;
        }

        $delegates = $this->workflowService->getEligibleDelegates($userId);
        
        \App\Helpers\ApiResponse::success($delegates);
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
            \App\Helpers\ApiResponse::error('Unauthenticated', 'UNAUTHENTICATED', [], 401);
            return;
        }

        $employeeId = (int) ($_GET['employee_id'] ?? 0);
        if (!$employeeId) {
            http_response_code(400);
            \App\Helpers\ApiResponse::error('Missing employee_id', 'VALIDATION_ERROR', [], 422);
            return;
        }

        $leaveTypes = $this->getLeaveTypesWithBalances($employeeId);
        
        \App\Helpers\ApiResponse::success($leaveTypes);
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
                \App\Helpers\ApiResponse::error('Unauthenticated', 'UNAUTHENTICATED', [], 401);
                return;
            }

            $currentUser = Auth::getInstance()->user();
            $employeeId = $currentUser['employee_id'] ?? null;

            if (!$employeeId) {
                http_response_code(400);
                \App\Helpers\ApiResponse::error('Employee record not found', 'EMPLOYEE_NOT_FOUND', [], 400);
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
                \App\Helpers\ApiResponse::error('Access denied', 'ACCESS_DENIED', [], 403);
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

            \App\Helpers\ApiResponse::success($employees);
        } catch (\Exception $e) {
            error_log('Error in eligibleEmployeesAction: ' . $e->getMessage());
            http_response_code(500);
            \App\Helpers\ApiResponse::error('Failed to load employees.', 'INTERNAL_ERROR', [], 500);
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
                \App\Helpers\ApiResponse::error('Unauthenticated', 'UNAUTHENTICATED', [], 401);
                return;
            }

            $currentUser = Auth::getInstance()->user();
            $employeeId = $currentUser['employee_id'] ?? null;

            if (!$employeeId) {
                http_response_code(400);
                \App\Helpers\ApiResponse::error('Employee record not found', 'EMPLOYEE_NOT_FOUND', [], 400);
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
                \App\Helpers\ApiResponse::error('Access denied', 'ACCESS_DENIED', [], 403);
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

            // Annotate each candidate with whether they are ALREADY committed as
            // somebody else's delegate for the requested window, so the form can
            // show an unavailable person as unavailable instead of letting the
            // application be rejected on submit. Only meaningful once the applicant
            // has chosen dates; with no window we skip the lookup entirely.
            $startDate = trim((string) ($_GET['start_date'] ?? ''));
            $endDate   = trim((string) ($_GET['end_date'] ?? ''));
            $windowGiven = preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) === 1
                        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) === 1;

            if ($windowGiven && $delegates !== []) {
                try {
                    $busy = \App\Services\DelegationService::getInstance()
                        ->busyDelegateEmployeeIds(
                            array_map(static fn(array $d): int => (int) $d['id'], $delegates),
                            $startDate,
                            $endDate
                        );
                    foreach ($delegates as $i => $delegate) {
                        $clash = $busy[(int) $delegate['id']] ?? null;
                        $delegates[$i]['is_available'] = $clash === null;
                        $delegates[$i]['unavailable_reason'] = $clash === null
                            ? null
                            : sprintf(
                                'Already covering %s (%s to %s)',
                                $clash['delegator_name'],
                                $clash['start_date'],
                                $clash['end_date']
                            );
                    }
                } catch (\Throwable $e) {
                    // Best-effort UX hint only — never fail the dropdown.
                    error_log('eligibleDelegatesAction: availability lookup failed: ' . $e->getMessage());
                }
            }

            \App\Helpers\ApiResponse::success($delegates);
        } catch (\Exception $e) {
            error_log('Error in eligibleDelegatesAction: ' . $e->getMessage());
            http_response_code(500);
            \App\Helpers\ApiResponse::error('Failed to load delegates.', 'INTERNAL_ERROR', [], 500);
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
            \App\Helpers\ApiResponse::error('Unauthenticated', 'UNAUTHENTICATED', [], 401);
            return;
        }

        $pagination = [
            'limit'           => (int) ($_GET['limit'] ?? 15),
            'pending_offset'  => (int) ($_GET['pending_offset']  ?? 0),
            'approved_offset' => (int) ($_GET['approved_offset'] ?? 0),
            'rejected_offset' => (int) ($_GET['rejected_offset'] ?? 0),
        ];

        $result = $this->approvalService->listForApprover($userId, $pagination);

        if (($result['success'] ?? false)) {
            \App\Helpers\ApiResponse::success($result['data'] ?? null, $result['message'] ?? 'Operation successful', 200);
        } else {
            \App\Helpers\ApiResponse::error($result['message'] ?? 'Request failed.', 'REQUEST_FAILED', isset($result['data']) && is_array($result['data']) ? $result['data'] : [], 400);
        }
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
            \App\Helpers\ApiResponse::error('Unauthenticated', 'UNAUTHENTICATED', [], 401);
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

        if (($result['success'] ?? false)) {
            \App\Helpers\ApiResponse::success($result['data'] ?? null, $result['message'] ?? 'Operation successful', 200);
        } else {
            $isTransition = ($result['code'] ?? null) === 'INVALID_TRANSITION';
            \App\Helpers\ApiResponse::error(
                $result['message'] ?? 'Request failed.',
                $isTransition ? 'INVALID_TRANSITION' : 'REQUEST_FAILED',
                isset($result['data']) && is_array($result['data']) ? $result['data'] : [],
                $isTransition ? 409 : 400
            );
        }
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
            \App\Helpers\ApiResponse::error('Unauthenticated', 'UNAUTHENTICATED', [], 401);
            return;
        }

        $reason = $this->readReasonFromBody();
        if ($reason === '') {
            http_response_code(400);
            \App\Helpers\ApiResponse::error('A reason is required to reject leave.', 'VALIDATION_ERROR', [], 400);
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

        if (($result['success'] ?? false)) {
            \App\Helpers\ApiResponse::success($result['data'] ?? null, $result['message'] ?? 'Operation successful', 200);
        } else {
            $isTransition = ($result['code'] ?? null) === 'INVALID_TRANSITION';
            \App\Helpers\ApiResponse::error(
                $result['message'] ?? 'Request failed.',
                $isTransition ? 'INVALID_TRANSITION' : 'REQUEST_FAILED',
                isset($result['data']) && is_array($result['data']) ? $result['data'] : [],
                $isTransition ? 409 : 400
            );
        }
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
            \App\Helpers\ApiResponse::error('Unauthenticated', 'UNAUTHENTICATED', [], 401);
            return;
        }

        $reason = $this->readReasonFromBody();
        if ($reason === '') {
            http_response_code(400);
            \App\Helpers\ApiResponse::error('A reason is required to invalidate leave.', 'VALIDATION_ERROR', [], 400);
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

        if (($result['success'] ?? false)) {
            \App\Helpers\ApiResponse::success($result['data'] ?? null, $result['message'] ?? 'Operation successful', 200);
        } else {
            $isTransition = ($result['code'] ?? null) === 'INVALID_TRANSITION';
            \App\Helpers\ApiResponse::error(
                $result['message'] ?? 'Request failed.',
                $isTransition ? 'INVALID_TRANSITION' : 'REQUEST_FAILED',
                isset($result['data']) && is_array($result['data']) ? $result['data'] : [],
                $isTransition ? 409 : 400
            );
        }
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
            \App\Helpers\ApiResponse::error('Unauthenticated', 'UNAUTHENTICATED', [], 401);
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

        if (($result['success'] ?? false)) {
            \App\Helpers\ApiResponse::success($result['data'] ?? null, $result['message'] ?? 'Operation successful', 200);
        } else {
            $isTransition = ($result['code'] ?? null) === 'INVALID_TRANSITION';
            \App\Helpers\ApiResponse::error(
                $result['message'] ?? 'Request failed.',
                $isTransition ? 'INVALID_TRANSITION' : 'REQUEST_FAILED',
                isset($result['data']) && is_array($result['data']) ? $result['data'] : [],
                $isTransition ? 409 : 400
            );
        }
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
            // A malformed body is a client error, not an empty request: the
            // previous bare decode() returned null and the reason was then
            // silently dropped, so a rejection could be recorded with an empty
            // reason. decodeRequest() surfaces it as a 400 instead.
            $decoded = \App\Helpers\Json::decodeRequest($raw, null, 'leave.rejection_reason');
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
                // Backend-owned leave-type business rules (LeaveTypePolicy),
                // delivered so the frontend can mirror them for UX only.
                'policy'               => \App\Services\Leave\LeaveTypePolicy::flags((int) $row['leave_type_id']),
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

    // ───────────────────────────────────────────────────────────────────
    //  Employee Leave Profile endpoints
    // ───────────────────────────────────────────────────────────────────

    /**
     * GET /api/leave/profile/employees
     * Search / list employees eligible for the leave-profile selector.
     * Role-scoped: officers see only themselves; HR sees all.
     */
    public function profileEmployeesAction(): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            http_response_code(401);
            \App\Helpers\ApiResponse::error('Unauthenticated', 'UNAUTHENTICATED', [], 401);
            return;
        }

        $search = trim($_GET['search'] ?? '');
        $employees = $this->profileService->getEligibleEmployees();

        if ($search !== '') {
            $searchLower = strtolower($search);
            $employees = array_filter($employees, function ($e) use ($searchLower) {
                return strpos(strtolower($e['employee_id'] ?? ''), $searchLower) !== false
                    || strpos(strtolower($e['first_name'] ?? ''), $searchLower) !== false
                    || strpos(strtolower($e['last_name'] ?? ''), $searchLower) !== false
                    || strpos(strtolower($e['surname'] ?? ''), $searchLower) !== false
                    || strpos(strtolower($e['department_name'] ?? ''), $searchLower) !== false
                    || strpos(strtolower($e['section_name'] ?? ''), $searchLower) !== false
                    || strpos(strtolower($e['subsection_name'] ?? ''), $searchLower) !== false
                    || strpos(strtolower($e['designation'] ?? ''), $searchLower) !== false;
            });
        }

        \App\Helpers\ApiResponse::success(array_values($employees));
    }

    /**
     * GET /api/leave/profile/{employeeId}
     * Get the complete leave profile for an employee.
     *
     * Query params:
     *   financial_year_id  (optional, defaults to current FY)
     *   status             (optional filter)
     *   leave_type_id      (optional filter)
     *   date_from          (optional filter)
     *   date_to            (optional filter)
     */
    public function profileAction(int $employeeId): void
    {
        try {
            $userId = Auth::getInstance()->id();
            if (!$userId) {
                http_response_code(401);
                \App\Helpers\ApiResponse::error('Unauthenticated', 'UNAUTHENTICATED', [], 401);
                return;
            }

            // Authorisation check — backend must verify
            if (!$this->profileService->canViewProfile($employeeId)) {
                http_response_code(403);
                \App\Helpers\ApiResponse::error("Access denied — you are not authorised to view this employee's leave profile", 'ACCESS_DENIED', [], 403);
                return;
            }

            $financialYearId = (int) ($_GET['financial_year_id'] ?? 0);
            if ($financialYearId === 0) {
                $financialYearId = $this->profileService->getCurrentFinancialYearId();
            }

            $filters = [];
            if (!empty($_GET['status'])) {
                $filters['status'] = $_GET['status'];
            }
            if (!empty($_GET['leave_type_id'])) {
                $filters['leave_type_id'] = (int) $_GET['leave_type_id'];
            }
            if (!empty($_GET['date_from'])) {
                $filters['date_from'] = $_GET['date_from'];
            }
            if (!empty($_GET['date_to'])) {
                $filters['date_to'] = $_GET['date_to'];
            }

            $profile = $this->profileService->getFullProfile($employeeId, $financialYearId, $filters);

            if (!$profile['success']) {
                \App\Helpers\ApiResponse::error($profile['message'] ?? 'Profile not found.', 'NOT_FOUND', [], 404);
            }

            \App\Helpers\ApiResponse::success($profile);
        } catch (\Throwable $e) {
            error_log('LeaveProfile profileAction error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
            http_response_code(500);
            \App\Helpers\ApiResponse::error('Internal server error.', 'INTERNAL_ERROR', [], 500);
        }
    }

    /**
     * GET /api/leave/profile/{employeeId}/balances
     * Get leave balances for an employee in a financial year.
     */
    public function profileBalancesAction(int $employeeId): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            http_response_code(401);
            \App\Helpers\ApiResponse::error('Unauthenticated', 'UNAUTHENTICATED', [], 401);
            return;
        }

        if (!$this->profileService->canViewProfile($employeeId)) {
            http_response_code(403);
            \App\Helpers\ApiResponse::error('Access denied', 'ACCESS_DENIED', [], 403);
            return;
        }

        $financialYearId = (int) ($_GET['financial_year_id'] ?? 0);
        if ($financialYearId === 0) {
            $financialYearId = $this->profileService->getCurrentFinancialYearId();
        }

        $balances = $this->profileService->getLeaveBalances($employeeId, $financialYearId);

        \App\Helpers\ApiResponse::success($balances);
    }

    /**
     * GET /api/leave/profile/{employeeId}/applications
     * Get leave applications for an employee with optional filters.
     */
    public function profileApplicationsAction(int $employeeId): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            http_response_code(401);
            \App\Helpers\ApiResponse::error('Unauthenticated', 'UNAUTHENTICATED', [], 401);
            return;
        }

        if (!$this->profileService->canViewProfile($employeeId)) {
            http_response_code(403);
            \App\Helpers\ApiResponse::error('Access denied', 'ACCESS_DENIED', [], 403);
            return;
        }

        $financialYearId = (int) ($_GET['financial_year_id'] ?? 0);
        if ($financialYearId === 0) {
            $financialYearId = $this->profileService->getCurrentFinancialYearId();
        }

        $filters = [];
        if (!empty($_GET['status'])) {
            $filters['status'] = $_GET['status'];
        }
        if (!empty($_GET['leave_type_id'])) {
            $filters['leave_type_id'] = (int) $_GET['leave_type_id'];
        }
        if (!empty($_GET['date_from'])) {
            $filters['date_from'] = $_GET['date_from'];
        }
        if (!empty($_GET['date_to'])) {
            $filters['date_to'] = $_GET['date_to'];
        }

        $applications = $this->profileService->getLeaveApplications($employeeId, $financialYearId, $filters);

        \App\Helpers\ApiResponse::success($applications);
    }

    /**
     * GET /api/leave/profile/{employeeId}/timeline
     * Get the balance timeline (buildBalanceTimeline) for an employee.
     */
    public function profileTimelineAction(int $employeeId): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            http_response_code(401);
            \App\Helpers\ApiResponse::error('Unauthenticated', 'UNAUTHENTICATED', [], 401);
            return;
        }

        if (!$this->profileService->canViewProfile($employeeId)) {
            http_response_code(403);
            \App\Helpers\ApiResponse::error('Access denied', 'ACCESS_DENIED', [], 403);
            return;
        }

        $financialYearId = (int) ($_GET['financial_year_id'] ?? 0);
        if ($financialYearId === 0) {
            $financialYearId = $this->profileService->getCurrentFinancialYearId();
        }

        $timeline = $this->profileService->buildBalanceTimeline($employeeId, $financialYearId);

        \App\Helpers\ApiResponse::success($timeline);
    }

    /**
     * GET /api/leave/profile/{employeeId}/summary
     * Get summary statistics for an employee's leave account.
     */
    public function profileSummaryAction(int $employeeId): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            http_response_code(401);
            \App\Helpers\ApiResponse::error('Unauthenticated', 'UNAUTHENTICATED', [], 401);
            return;
        }

        if (!$this->profileService->canViewProfile($employeeId)) {
            http_response_code(403);
            \App\Helpers\ApiResponse::error('Access denied', 'ACCESS_DENIED', [], 403);
            return;
        }

        $financialYearId = (int) ($_GET['financial_year_id'] ?? 0);
        if ($financialYearId === 0) {
            $financialYearId = $this->profileService->getCurrentFinancialYearId();
        }

        $summary = $this->profileService->getSummaryStatistics($employeeId, $financialYearId);

        \App\Helpers\ApiResponse::success($summary);
    }

    /**
     * GET /api/leave/profile/{employeeId}/export
     * Export the employee leave account as CSV.
     */
    public function profileExportAction(int $employeeId): void
    {
        $userId = Auth::getInstance()->id();
        if (!$userId) {
            http_response_code(401);
            \App\Helpers\ApiResponse::error('Unauthenticated', 'UNAUTHENTICATED', [], 401);
            return;
        }

        if (!$this->profileService->canViewProfile($employeeId)) {
            http_response_code(403);
            \App\Helpers\ApiResponse::error('Access denied', 'ACCESS_DENIED', [], 403);
            return;
        }

        $financialYearId = (int) ($_GET['financial_year_id'] ?? 0);
        if ($financialYearId === 0) {
            $financialYearId = $this->profileService->getCurrentFinancialYearId();
        }

        $filters = [];
        if (!empty($_GET['status'])) {
            $filters['status'] = $_GET['status'];
        }
        if (!empty($_GET['leave_type_id'])) {
            $filters['leave_type_id'] = (int) $_GET['leave_type_id'];
        }
        if (!empty($_GET['date_from'])) {
            $filters['date_from'] = $_GET['date_from'];
        }
        if (!empty($_GET['date_to'])) {
            $filters['date_to'] = $_GET['date_to'];
        }

        $exportData = $this->profileService->getExportData($employeeId, $financialYearId, $filters);

        if (!$exportData['success']) {
            \App\Helpers\ApiResponse::error($exportData['message'] ?? 'Export data not found.', 'NOT_FOUND', [], 404);
        }

        // If format=csv, output as CSV
        $format = $_GET['format'] ?? 'json';
        if ($format === 'csv') {
            $this->outputCsvExport($exportData);
            return;
        }

        \App\Helpers\ApiResponse::success($exportData);
    }

    /**
     * Output CSV export of the employee leave account.
     */
    private function outputCsvExport(array $exportData): void
    {
        $employee = $exportData['employee'];
        $fy = $exportData['financial_year'];

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="leave_account_' . $employee['employee_id'] . '_' . $fy . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // Header
        fputcsv($output, ['Employee Leave Account Export']);
        fputcsv($output, ['Employee Name', $employee['name']]);
        fputcsv($output, ['Employee ID', $employee['employee_id']]);
        fputcsv($output, ['Employment Type', $employee['employment_type']]);
        fputcsv($output, ['Department', $employee['department']]);
        fputcsv($output, ['Section', $employee['section']]);
        fputcsv($output, ['Sub-Section', $employee['subsection']]);
        fputcsv($output, ['Designation', $employee['designation']]);
        fputcsv($output, ['Financial Year', $fy]);
        fputcsv($output, []);

        // Summary
        fputcsv($output, ['Summary Statistics']);
        fputcsv($output, ['Total Leave Types', $exportData['summary']['total_leave_types']]);
        fputcsv($output, ['Total Allocated Days', $exportData['summary']['total_allocated_days']]);
        fputcsv($output, ['Total Brought Forward', $exportData['summary']['total_brought_forward']]);
        fputcsv($output, ['Total Used Days', $exportData['summary']['total_used_days']]);
        fputcsv($output, ['Total Remaining Days', $exportData['summary']['total_remaining_days']]);
        fputcsv($output, ['Pending Applications', $exportData['summary']['pending_applications']]);
        fputcsv($output, ['Approved Applications', $exportData['summary']['approved_applications']]);
        fputcsv($output, ['Rejected Applications', $exportData['summary']['rejected_applications']]);
        fputcsv($output, []);

        // Data rows
        fputcsv($output, ['Section', 'Leave Type', 'Allocated', 'Brought Forward', 'Used', 'Remaining', 'Start Date', 'End Date', 'Days Requested', 'Status', 'Balance Impact']);
        foreach ($exportData['export_rows'] as $row) {
            fputcsv($output, [
                $row['section'],
                $row['leave_type'],
                $row['allocated'],
                $row['brought_forward'],
                $row['used'],
                $row['remaining'],
                $row['start_date'],
                $row['days_requested'],
                $row['status'],
                $row['balance_impact'],
            ]);
        }

        fclose($output);
        exit;
    }
}



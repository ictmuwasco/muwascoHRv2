<?php

declare(strict_types=1);

namespace App\Controllers\Leave;

use App\Core\Controller;
use App\Services\AuditService;
use App\Services\DelegateService;
use App\Services\LeaveAttachmentService;

class ApplyLeaveController extends Controller
{
    private AuditService $audit;
    private ?DelegateService $delegateService = null;
    private ?LeaveAttachmentService $attachmentService = null;

    public function __construct()
    {
        parent::__construct();
        $this->audit = AuditService::getInstance();
    }

    private function getDelegateService(\mysqli $conn): DelegateService
    {
        if ($this->delegateService === null) {
            $this->delegateService = new DelegateService($conn);
        }
        return $this->delegateService;
    }

    private function getAttachmentService(): LeaveAttachmentService
    {
        if ($this->attachmentService === null) {
            $this->attachmentService = LeaveAttachmentService::getInstance();
        }
        return $this->attachmentService;
    }

    public function indexAction(): void
    {
        $this->ensureAccess();
        $conn = $this->getDbConnection();

        $employees = $this->getEmployees($conn);
        $userEmployee = $this->getUserEmployee($conn);
        $flash = $this->getFlashMessage();

        // Get eligible delegates for the current user's employee record
        $eligibleDelegates = [];
        if ($userEmployee) {
            $delegateService = $this->getDelegateService($conn);
            $eligibleDelegates = $delegateService->getEligibleDelegates((int)$userEmployee['id']);
        }

        // Get leave types for the dropdown
        $leaveTypes = [];
        $result = $conn->query("SELECT id, name FROM leave_types ORDER BY name");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $leaveTypes[(int)$row['id']] = $row['name'];
            }
        }

        // Determine Study Leave and Sick Leave IDs by matching names
        $studyLeaveId = 0;
        $sickLeaveId = 0;
        foreach ($leaveTypes as $id => $name) {
            if (stripos($name, 'study') !== false) {
                $studyLeaveId = $id;
            }
            if (stripos($name, 'sick') !== false) {
                $sickLeaveId = $id;
            }
        }

        $this->audit->logPageView();

        $this->view('leave/apply', [
            'employees' => $employees,
            'userEmployee' => $userEmployee,
            'eligibleDelegates' => $eligibleDelegates,
            'flash' => $flash,
            'csrf_token' => $this->generateCsrfToken(),
            'leaveTypes' => $leaveTypes,
            'studyLeaveId' => $studyLeaveId,
            'sickLeaveId' => $sickLeaveId,
        ]);
    }

    public function submitAction(): void
    {
        $this->ensureAccess();
        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            $this->redirect('leave/apply');
            return;
        }

        $conn = $this->getDbConnection();
        $delegateService = $this->getDelegateService($conn);
        $attachmentService = $this->getAttachmentService();

        $employeeId = (int)($_POST['employee_id'] ?? 0);
        $leaveTypeId = (int)($_POST['leave_type_id'] ?? 0);
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        $reason = $this->sanitize($_POST['reason'] ?? '');
        $delegateId = (int)($_POST['delegate_id'] ?? 0);

        // Server-side validation
        if (!$employeeId || !$leaveTypeId || !$startDate || !$endDate) {
            $_SESSION['flash_error'] = 'Please fill in all required fields.';
            $this->redirect('leave/apply');
            return;
        }

        // Validate delegate if provided
        if ($delegateId > 0) {
            $validation = $delegateService->validateDelegate($employeeId, $delegateId);
            if (!$validation['valid']) {
                $_SESSION['flash_error'] = $validation['message'];
                $this->redirect('leave/apply');
                return;
            }
        }

        try {
            $conn->begin_transaction();

            // Insert leave application with delegate
            $stmt = $conn->prepare("
                INSERT INTO leave_applications 
                (employee_id, leave_type_id, start_date, end_date, days_requested, reason, status, 
                 applied_at, delegated_to, applied_by_user_id)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW(), ?, ?)
            ");

            $days = $this->calculateDays($startDate, $endDate);
            $delegateValue = $delegateId > 0 ? $delegateId : null;
            $userId = (int)($_SESSION['user_id'] ?? 0);

            $stmt->bind_param('iissssii', $employeeId, $leaveTypeId, $startDate, $endDate, 
                            $days, $reason, $delegateValue, $userId);

            if (!$stmt->execute()) {
                throw new \Exception('Failed to create leave application.');
            }

            $applicationId = $stmt->insert_id;

            // Handle conditional file uploads based on leave type
            $this->processFileUploads($applicationId, $leaveTypeId, $userId, $attachmentService, $conn);

            // Audit log
            $auditData = [
                'employee_id' => $employeeId,
                'leave_type_id' => $leaveTypeId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days' => $days,
                'delegated_to' => $delegateValue,
            ];
            $this->audit->logCreate('leave_applications', $applicationId, $auditData, 
                "Leave application #{$applicationId} created" . ($delegateValue ? " with delegate" : ""));

            $conn->commit();

            $_SESSION['flash_message'] = 'Leave application submitted successfully!';
            $_SESSION['flash_type'] = 'success';

        } catch (\Exception $e) {
            $conn->rollback();
            $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
        }

        $this->redirect('leave/apply');
    }

    /**
     * Process conditional file uploads based on leave type.
     * Study Leave requires study_timetable.
     * Sick Leave requires medical_certificate.
     */
    private function processFileUploads(int $applicationId, int $leaveTypeId, int $userId, LeaveAttachmentService $attachmentService, \mysqli $conn): void
    {
        // Dynamically determine which leave types require attachments by querying names
        $studyLeaveId = 0;
        $sickLeaveId = 0;
        $result = $conn->query("SELECT id, name FROM leave_types");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $nameLower = strtolower($row['name']);
                if (strpos($nameLower, 'study') !== false) {
                    $studyLeaveId = (int)$row['id'];
                }
                if (strpos($nameLower, 'sick') !== false) {
                    $sickLeaveId = (int)$row['id'];
                }
            }
        }

        $requiredAttachments = [];
        if ($studyLeaveId > 0) {
            $requiredAttachments[$studyLeaveId] = ['study_timetable', 'study_timetable'];
        }
        if ($sickLeaveId > 0) {
            $requiredAttachments[$sickLeaveId] = ['medical_certificate', 'medical_certificate'];
        }

        // Check if this leave type requires an attachment
        if (array_key_exists($leaveTypeId, $requiredAttachments)) {
            $postKey = $requiredAttachments[$leaveTypeId][0];
            $documentType = $requiredAttachments[$leaveTypeId][1];

            if (isset($_FILES[$postKey]) && $_FILES[$postKey]['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES[$postKey];
                $result = $attachmentService->saveFile(
                    $file,
                    $file['name'],
                    $applicationId,
                    $documentType,
                    $userId
                );

                if (!$result['success']) {
                    throw new \Exception('File upload failed: ' . implode(', ', $result['errors']));
                }
            } else {
                throw new \Exception("Please upload the required {$documentType} document.");
            }
        }
    }

    public function ajaxAction(): void
    {
        $this->ensureAccess();
        $conn = $this->getDbConnection();
        $action = $_GET['action'] ?? '';

        header('Content-Type: application/json');

        switch ($action) {
            case 'get_eligible_delegates':
                $employeeId = (int)($_GET['employee_id'] ?? 0);
                if ($employeeId <= 0) {
                    echo json_encode([]);
                    exit;
                }
                $delegateService = $this->getDelegateService($conn);
                echo json_encode($delegateService->getEligibleDelegates($employeeId));
                exit;

            case 'get_employee_leave_types':
                echo json_encode([]);
                exit;

            case 'get_holidays_in_range':
                echo json_encode([]);
                exit;

            case 'validate_leave_application':
                echo json_encode([]);
                exit;

            default:
                echo json_encode(['error' => 'Invalid action']);
                exit;
        }
    }

    /**
     * Upload attachment for existing leave application (AJAX endpoint)
     */
    public function uploadAttachmentAction(): void
    {
        $this->ensureAccess();
        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
            return;
        }

        $attachmentService = $this->getAttachmentService();
        $leaveApplicationId = (int)($_POST['leave_application_id'] ?? 0);
        $documentType = $_POST['document_type'] ?? '';
        $userId = (int)($_SESSION['user_id'] ?? 0);

        if ($leaveApplicationId <= 0 || !in_array($documentType, ['study_timetable', 'medical_certificate'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
            return;
        }

        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['attachment'];
            $result = $attachmentService->saveFile(
                $file,
                $file['name'],
                $leaveApplicationId,
                $documentType,
                $userId
            );

            if ($result['success']) {
                echo json_encode([
                    'success' => true,
                    'message' => 'File uploaded successfully.',
                    'attachment' => $result
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => implode(', ', $result['errors'])]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'No file uploaded.']);
        }
    }

    /**
     * Download attachment
     */
    public function downloadAttachmentAction(): void
    {
        $this->ensureAccess();
        $attachmentId = (int)($_GET['id'] ?? 0);
        
        if ($attachmentId <= 0) {
            http_response_code(400);
            echo 'Invalid attachment ID.';
            return;
        }

        $attachmentService = $this->getAttachmentService();
        $attachmentService->downloadAttachment($attachmentId);
    }

    private function ensureAccess(): void
    {
        if (!$this->isAuthenticated()) {
            $this->redirect('login');
        }
    }

    private function getEmployees(\mysqli $conn): array
    {
        $role = $_SESSION['user_role'] ?? 'guest';
        $userEmp = $this->getUserEmployee($conn);

        if (in_array($role, ['hr_manager','super_admin','managing_director'])) {
            return $conn->query("SELECT e.*,d.name dept_name,s.name sec_name,ss.name sub_name 
                FROM employees e LEFT JOIN departments d ON e.department_id=d.id
                LEFT JOIN sections s ON e.section_id=s.id 
                LEFT JOIN subsections ss ON e.subsection_id=ss.id ORDER BY e.first_name")->fetch_all(MYSQLI_ASSOC);
        }
        if ($role === 'dept_head' && $userEmp) {
            $stmt = $conn->prepare("SELECT e.*,d.name dept_name,s.name sec_name,ss.name sub_name FROM employees e 
                LEFT JOIN departments d ON e.department_id=d.id LEFT JOIN sections s ON e.section_id=s.id 
                LEFT JOIN subsections ss ON e.subsection_id=ss.id WHERE e.department_id=? ORDER BY e.first_name");
            $stmt->bind_param('i', $userEmp['department_id']);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        if (in_array($role, ['section_head','sub_section_head']) && $userEmp) {
            $col = $role === 'section_head' ? 'section_id' : 'subsection_id';
            $stmt = $conn->prepare("SELECT e.*,d.name dept_name,s.name sec_name,ss.name sub_name FROM employees e 
                LEFT JOIN departments d ON e.department_id=d.id LEFT JOIN sections s ON e.section_id=s.id 
                LEFT JOIN subsections ss ON e.subsection_id=ss.id WHERE e.{$col}=? ORDER BY e.first_name");
            $stmt->bind_param('i', $userEmp[$col]);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        return $userEmp ? [$userEmp] : [];
    }

    private function getUserEmployee(\mysqli $conn): ?array
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $stmt = $conn->prepare("SELECT e.*,d.name dept_name,s.name sec_name,ss.name sub_name 
            FROM employees e LEFT JOIN departments d ON e.department_id=d.id 
            LEFT JOIN sections s ON e.section_id=s.id LEFT JOIN subsections ss ON e.subsection_id=ss.id 
            JOIN users u ON u.employee_id=e.employee_id WHERE u.id=? LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function getFlashMessage(): ?array
    {
        if (!isset($_SESSION['flash_message'])) return null;
        $msg = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        return ['message' => $msg, 'type' => $type];
    }

    private function calculateDays(string $start, string $end): int
    {
        $s = new \DateTime($start);
        $e = new \DateTime($end);
        return (int)$s->diff($e)->days + 1;
    }

    private function sanitize(string $v, int $max = 500): string
    {
        $v = trim(strip_tags($v));
        return strlen($v) > $max ? substr($v, 0, $max) : $v;
    }
}
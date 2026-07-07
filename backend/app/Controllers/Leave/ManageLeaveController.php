<?php

declare(strict_types=1);

namespace App\Controllers\Leave;

use App\Core\Controller;
use App\Services\AuditService;

class ManageLeaveController extends Controller
{
    private AuditService $audit;

    public function __construct()
    {
        parent::__construct();
        $this->audit = AuditService::getInstance();
    }

    public function indexAction(): void
    {
        $this->ensureAccess();
        $conn = $this->getDbConnection();
        
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $role = $_SESSION['user_role'] ?? 'guest';
        
        $userEmployee = $this->getUserEmployee($conn);
        $flash = $this->getFlashMessage();
        
        $pendingLeaves = $this->getPendingLeaves($conn, $role, $userEmployee);
        $approvedLeaves = $this->getApprovedLeaves($conn, $role, $userEmployee);
        $rejectedLeaves = $this->getRejectedLeaves($conn, $role, $userEmployee);
        
        $this->audit->logPageView();
        
        $this->view('leave/manage', [
            'pendingLeaves' => $pendingLeaves,
            'approvedLeaves' => $approvedLeaves,
            'rejectedLeaves' => $rejectedLeaves,
            'userEmployee' => $userEmployee,
            'flash' => $flash,
            'csrf_token' => $this->generateCsrfToken(),
            'userRole' => $role,
        ]);
    }

    public function approveAction(): void
    {
        $this->ensureAccess();
        if (!$this->validateCsrfToken($_GET['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            $this->redirect('leave/manage');
            return;
        }
        
        $conn = $this->getDbConnection();
        $leaveId = (int)($_GET['id'] ?? 0);
        $action = $_GET['action'] ?? '';
        
        try {
            $conn->begin_transaction();
            
            // Approve/Reject/Invalidate logic here
            // ... extracted from manage.php ...
            
            $this->audit->logUpdate('leave_applications', $leaveId, [], ['status' => 'processed']);
            
            $_SESSION['flash_message'] = 'Leave processed successfully.';
            $_SESSION['flash_type'] = 'success';
            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollback();
            $_SESSION['flash_message'] = 'Error: ' . $e->getMessage();
            $_SESSION['flash_type'] = 'danger';
        }
        
        $this->redirect('leave/manage');
    }

    private function ensureAccess(): void
    {
        if (!$this->isAuthenticated()) {
            $this->redirect('login');
        }
        $allowedRoles = ['hr_manager','dept_head','section_head','sub_section_head','manager','managing_director','super_admin'];
        if (!in_array($_SESSION['user_role'] ?? 'guest', $allowedRoles)) {
            $_SESSION['flash_error'] = 'Access denied.';
            $this->redirect('leave/apply');
        }
    }

    private function getUserEmployee(\mysqli $conn): ?array
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $stmt = $conn->prepare("SELECT e.* FROM employees e JOIN users u ON u.employee_id=e.employee_id WHERE u.id=? LIMIT 1");
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

    /**
     * Fetch attachments for a list of leave applications
     */
    private function fetchAttachmentsForLeaves(array $leaves): array
    {
        $attachmentMap = [];
        
        if (empty($leaves)) {
            return $attachmentMap;
        }

        $ids = array_column($leaves, 'id');
        $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
        $idTypes = str_repeat('i', count($ids));
        
        $conn = $this->getDbConnection();
        $stmt = $conn->prepare("
            SELECT la.leave_application_id, la.id as attachment_id, 
                   la.document_type, la.file_name, la.file_path, 
                   la.file_size, la.uploaded_at
            FROM leave_attachments la
            WHERE la.leave_application_id IN ($idPlaceholders)
            ORDER BY la.leave_application_id, la.uploaded_at DESC
        ");
        
        $stmt->bind_param($idTypes, ...$ids);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $appId = (int)$row['leave_application_id'];
            if (!isset($attachmentMap[$appId])) {
                $attachmentMap[$appId] = [];
            }
            $attachmentMap[$appId][] = [
                'id' => $row['attachment_id'],
                'document_type' => $row['document_type'],
                'file_name' => $row['file_name'],
                'file_path' => $row['file_path'],
                'file_size' => $row['file_size'],
                'uploaded_at' => $row['uploaded_at'],
            ];
        }
        
        return $attachmentMap;
    }

    private function getPendingLeaves(\mysqli $conn, string $role, ?array $userEmp): array
    {
        $limit = 15;
        $offset = (int)(($_GET['pending_page'] ?? 1) - 1) * $limit;
        
        $base = "SELECT la.*, e.employee_id, e.first_name, e.last_name, 
                lt.name leave_type_name, d.name department_name, s.name section_name,
                del.first_name AS del_first, del.last_name AS del_last, del.designation AS del_designation,
                del.employee_id AS del_emp_id
                FROM leave_applications la 
                JOIN employees e ON la.employee_id=e.id 
                JOIN leave_types lt ON la.leave_type_id=lt.id 
                LEFT JOIN departments d ON e.department_id=d.id 
                LEFT JOIN sections s ON e.section_id=s.id
                LEFT JOIN employees del ON la.delegated_to = del.id";
        
        switch ($role) {
            case 'sub_section_head':
                $w = "WHERE la.status='pending_subsection_head' AND e.subsection_id=?";
                break;
            case 'section_head':
                $w = "WHERE la.status='pending_section_head' AND e.section_id=?";
                break;
            case 'dept_head':
                $w = "WHERE la.status='pending_dept_head' AND e.department_id=?";
                break;
            case 'managing_director':
                $w = "WHERE la.status='pending_managing_director'";
                break;
            default:
                $w = "WHERE la.status IN ('pending_subsection_head','pending_section_head','pending_dept_head','pending_managing_director','pending_bod_chair','pending_hr')";
        }
        
        $sql = "$base $w ORDER BY la.applied_at DESC LIMIT $limit OFFSET $offset";
        
        if (in_array($role, ['sub_section_head','section_head','dept_head'])) {
            $col = $role === 'sub_section_head' ? 'subsection_id' : ($role === 'section_head' ? 'section_id' : 'department_id');
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $userEmp[$col]);
            $stmt->execute();
            $leaves = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } else {
            $leaves = $conn->query($sql)->fetch_all(MYSQLI_ASSOC) ?: [];
        }
        
        // Fetch attachments for pending leaves
        $attachments = $this->fetchAttachmentsForLeaves($leaves);
        foreach ($leaves as &$leave) {
            $leave['attachments'] = $attachments[$leave['id']] ?? [];
        }
        
        return $leaves;
    }

    private function getApprovedLeaves(\mysqli $conn, string $role, ?array $userEmp): array
    {
        $limit = 15;
        $offset = (int)(($_GET['approved_page'] ?? 1) - 1) * $limit;
        
        $cols = "COALESCE((SELECT CONCAT(u.first_name,' ',u.last_name) FROM users u JOIN employees em ON u.employee_id=em.employee_id WHERE em.id=la.hr_approved_by LIMIT 1),
            (SELECT CONCAT(u.first_name,' ',u.last_name) FROM users u JOIN employees em ON u.employee_id=em.employee_id WHERE em.id=la.managing_director_approved_by LIMIT 1),
            (SELECT CONCAT(u.first_name,' ',u.last_name) FROM users u JOIN employees em ON u.employee_id=em.employee_id WHERE em.id=la.dept_head_approved_by LIMIT 1),
            (SELECT CONCAT(u.first_name,' ',u.last_name) FROM users u JOIN employees em ON u.employee_id=em.employee_id WHERE em.id=la.section_head_approved_by LIMIT 1),
            'System') approver_name,
            COALESCE(la.hr_approved_at,la.managing_director_approved_at,la.dept_head_approved_at,la.section_head_approved_at) action_date";
        
        $base = "SELECT la.*, e.employee_id, e.first_name, e.last_name, lt.name leave_type_name, $cols
                FROM leave_applications la JOIN employees e ON la.employee_id=e.id 
                JOIN leave_types lt ON la.leave_type_id=lt.id WHERE la.status='approved'";
        
        $w = $this->getScopeWhere($role, $userEmp);
        $sql = "$base $w ORDER BY action_date DESC LIMIT $limit OFFSET $offset";
        $leaves = $this->executeScoped($conn, $sql, $role, $userEmp);
        
        // Fetch attachments for approved leaves
        $attachments = $this->fetchAttachmentsForLeaves($leaves);
        foreach ($leaves as &$leave) {
            $leave['attachments'] = $attachments[$leave['id']] ?? [];
        }
        
        return $leaves;
    }

    private function getRejectedLeaves(\mysqli $conn, string $role, ?array $userEmp): array
    {
        $limit = 15;
        $offset = (int)(($_GET['rejected_page'] ?? 1) - 1) * $limit;
        
        $cols = "COALESCE((SELECT CONCAT(u.first_name,' ',u.last_name) FROM users u JOIN employees em ON u.employee_id=em.employee_id WHERE em.id=la.hr_approved_by LIMIT 1),
            (SELECT CONCAT(u.first_name,' ',u.last_name) FROM users u JOIN employees em ON u.employee_id=em.employee_id WHERE em.id=la.managing_director_approved_by LIMIT 1),'System') approver_name,
            COALESCE(la.hr_approved_at,la.managing_director_approved_at,la.dept_head_approved_at,la.section_head_approved_at) action_date";
        
        $base = "SELECT la.*, e.employee_id, e.first_name, e.last_name, lt.name leave_type_name, $cols
                FROM leave_applications la JOIN employees e ON la.employee_id=e.id 
                JOIN leave_types lt ON la.leave_type_id=lt.id WHERE la.status='rejected'";
        
        $w = $this->getScopeWhere($role, $userEmp);
        $sql = "$base $w ORDER BY action_date DESC LIMIT $limit OFFSET $offset";
        $leaves = $this->executeScoped($conn, $sql, $role, $userEmp);
        
        // Fetch attachments for rejected leaves
        $attachments = $this->fetchAttachmentsForLeaves($leaves);
        foreach ($leaves as &$leave) {
            $leave['attachments'] = $attachments[$leave['id']] ?? [];
        }
        
        return $leaves;
    }

    private function getScopeWhere(string $role, ?array $userEmp): string
    {
        if (!$userEmp) return '';
        return match($role) {
            'sub_section_head' => " AND e.subsection_id={$userEmp['subsection_id']}",
            'section_head' => " AND e.section_id={$userEmp['section_id']}",
            'dept_head' => " AND e.department_id={$userEmp['department_id']}",
            default => '',
        };
    }

    private function executeScoped(\mysqli $conn, string $sql, string $role, ?array $userEmp): array
    {
        if (in_array($role, ['sub_section_head','section_head','dept_head'])) {
            $col = match($role) {
                'sub_section_head' => 'subsection_id',
                'section_head' => 'section_id',
                'dept_head' => 'department_id',
                default => 'id',
            };
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('i', $userEmp[$col]);
                $stmt->execute();
                return $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
            }
        }
        $result = $conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}
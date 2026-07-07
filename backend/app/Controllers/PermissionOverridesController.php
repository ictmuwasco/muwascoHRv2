<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Helpers\AuthorizationService;
use App\Models\UserPagePermission;
use App\Models\User;

/**
 * PermissionOverridesController
 *
 * Handles user-specific permission override management for the hybrid
 * authorization system. Only accessible to Super Administrators.
 *
 * Place: backend/app/Controllers/PermissionOverridesController.php
 */
class PermissionOverridesController extends Controller
{
    private AuthorizationService $authService;
    private UserPagePermission $permissionModel;
    private User $userModel;

    public function __construct()
    {
        $this->authService = AuthorizationService::getInstance();
        $this->permissionModel = new UserPagePermission();
        $this->userModel = new User();
    }

    /**
     * Display permission overrides management page
     * GET /admin/permission-overrides
     */
    public function indexAction(): void
    {
        $this->requirePermissionManager();
        
        $conn = $this->getDbConnection();
        
        // Get search and filter parameters
        $search = trim($_GET['search'] ?? '');
        $department = trim($_GET['department'] ?? '');
        $section = trim($_GET['section'] ?? '');
        $role = trim($_GET['role'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        // Build query with filters
        $whereConditions = ["1=1"];
        $params = [];
        $types = '';

        if ($search !== '') {
            $whereConditions[] = "(e.employee_id LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ? OR e.surname LIKE ? OR CONCAT(e.first_name, ' ', e.last_name) LIKE ?)";
            $searchParam = "%{$search}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $types .= 'sssss';
        }

        if ($department !== '') {
            $whereConditions[] = "e.department = ?";
            $params[] = $department;
            $types .= 's';
        }

        if ($section !== '') {
            $whereConditions[] = "e.section = ?";
            $params[] = $section;
            $types .= 's';
        }

        if ($role !== '') {
            $whereConditions[] = "u.role = ?";
            $params[] = $role;
            $types .= 's';
        }

        if ($status !== '') {
            $whereConditions[] = "e.employee_status = ?";
            $params[] = $status;
            $types .= 's';
        }

        $whereClause = implode(' AND ', $whereConditions);

        // Get total count for pagination
        $countQuery = "
            SELECT COUNT(*) as total
            FROM users u
            LEFT JOIN employees e ON u.email = e.email
            WHERE {$whereClause}
        ";
        
        $countStmt = $conn->prepare($countQuery);
        if (!empty($params)) {
            $countStmt->bind_param($types, ...$params);
        }
        $countStmt->execute();
        $totalRecords = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $countStmt->close();
        $totalPages = (int)ceil($totalRecords / $perPage);

        // Get employees with pagination
        $query = "
            SELECT 
                u.id as user_id,
                u.email,
                u.role,
                e.employee_id,
                e.first_name,
                e.last_name,
                e.surname,
                e.department,
                e.section,
                e.employee_status,
                e.gender,
                e.employment_type,
                CONCAT(e.first_name, ' ', e.last_name, IF(e.surname != '' AND e.surname IS NOT NULL, CONCAT(' ', e.surname), '')) as full_name,
                COUNT(DISTINCT upp.id) as override_count
            FROM users u
            LEFT JOIN employees e ON u.email = e.email
            LEFT JOIN user_page_permissions upp ON u.id = upp.user_id AND upp.active = 1
            WHERE {$whereClause}
            GROUP BY u.id, e.employee_id
            ORDER BY e.first_name, e.last_name
            LIMIT ? OFFSET ?
        ";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Get filter options
        $departments = $conn->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department")->fetch_all(MYSQLI_ASSOC);
        $sections = $conn->query("SELECT DISTINCT section FROM employees WHERE section IS NOT NULL AND section != '' ORDER BY section")->fetch_all(MYSQLI_ASSOC);
        $roles = $this->authService->getRoles();

        // Get statistics
        $stats = [
            'total_overrides' => $this->permissionModel->countActive(),
            'allow_overrides' => $this->permissionModel->countByType('allow'),
            'deny_overrides' => $this->permissionModel->countByType('deny'),
        ];

        $this->view('admin/permission_overrides/index', [
            'employees' => $employees,
            'departments' => $departments,
            'sections' => $sections,
            'roles' => $roles,
            'stats' => $stats,
            'search' => $search,
            'department' => $department,
            'section' => $section,
            'role' => $role,
            'status' => $status,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalRecords' => $totalRecords,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }

    /**
     * Manage permissions for a specific employee
     * GET /admin/permission-overrides/manage/{userId}
     */
    public function manageAction(int $userId): void
    {
        $this->requirePermissionManager();

        $conn = $this->getDbConnection();

        // Get user information
        $stmt = $conn->prepare("
            SELECT 
                u.id as user_id,
                u.email,
                u.role,
                e.employee_id,
                e.first_name,
                e.last_name,
                e.surname,
                e.department,
                e.section,
                e.employee_status,
                CONCAT(e.first_name, ' ', e.last_name, IF(e.surname != '' AND e.surname IS NOT NULL, CONCAT(' ', e.surname), '')) as full_name
            FROM users u
            LEFT JOIN employees e ON u.email = e.email
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $employee = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$employee) {
            $_SESSION['flash_error'] = 'Employee not found.';
            $this->redirect('admin/permission-overrides');
            return;
        }

        // Get role permissions (read-only)
        $rolePermissions = $this->authService->getRolePermissions($userId);

        // Get user-specific overrides
        $userOverrides = $this->permissionModel->getByUserId($userId);
        $overrideMap = [];
        foreach ($userOverrides as $override) {
            $overrideMap[$override['page_id']] = $override;
        }

        // Get all available pages
        $allPages = $this->permissionModel->getAllPageIds();
        $pageNames = $this->permissionModel->getPageNames();

        // Build permissions data
        $permissions = [];
        foreach ($allPages as $pageId) {
            $roleHasPermission = isset($rolePermissions[$pageId]) && in_array('view', $rolePermissions[$pageId]);
            $override = $overrideMap[$pageId] ?? null;

            $permissions[] = [
                'page_id' => $pageId,
                'page_name' => $pageNames[$pageId] ?? $pageId,
                'role_has_permission' => $roleHasPermission,
                'override_type' => $override ? $override['permission_type'] : null,
                'override_id' => $override ? $override['id'] : null,
                'override_notes' => $override ? $override['notes'] : null,
            ];
        }

        // Get effective permissions for preview
        $effectivePermissions = $this->authService->getAllEffectivePermissions($userId);

        $this->view('admin/permission_overrides/manage', [
            'employee' => $employee,
            'permissions' => $permissions,
            'effective_permissions' => $effectivePermissions,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }

    /**
     * Save permission overrides for a user
     * POST /admin/permission-overrides/save/{userId}
     */
    public function saveAction(int $userId): void
    {
        $this->requirePermissionManager();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['error' => 'Method not allowed'], 405);
            return;
        }

        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->json(['error' => 'Invalid security token'], 403);
            return;
        }

        $conn = $this->getDbConnection();
        $grantedBy = (int)$_SESSION['user_id'];
        $permissions = $_POST['permissions'] ?? [];

        if (empty($permissions) || !is_array($permissions)) {
            $this->json(['error' => 'No permissions provided'], 400);
            return;
        }

        try {
            $conn->begin_transaction();

            $savedCount = 0;
            $removedCount = 0;

            foreach ($permissions as $pageId => $permissionType) {
                // Get current permission
                $current = $this->permissionModel->getByUserAndPage($userId, $pageId);
                $currentType = $current ? $current['permission_type'] : null;

                if ($permissionType === 'inherit') {
                    // Remove override if it exists
                    if ($current) {
                        $this->permissionModel->removePermission($userId, $pageId);
                        $removedCount++;
                    }
                } else {
                    // Set or update override
                    $this->permissionModel->setPermission($userId, $pageId, $permissionType, $grantedBy);
                    $savedCount++;
                }
            }

            $conn->commit();

            // Clear cache
            $this->authService->clearCache();

            // Log audit
            $this->logAudit('permission_override_updated', [
                'user_id' => $userId,
                'saved_count' => $savedCount,
                'removed_count' => $removedCount,
            ]);

            $this->json([
                'success' => true,
                'message' => "Permissions updated successfully. {$savedCount} override(s) set, {$removedCount} override(s) removed.",
                'saved_count' => $savedCount,
                'removed_count' => $removedCount
            ]);

        } catch (Exception $e) {
            $conn->rollback();
            $this->json(['error' => 'Failed to save permissions: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get effective permissions for a user (AJAX)
     * GET /admin/permission-overrides/effective/{userId}
     */
    public function effectivePermissionsAction(int $userId): void
    {
        $this->requirePermissionManager();

        if (!$this->isAjaxRequest()) {
            $this->json(['error' => 'Invalid request'], 400);
            return;
        }

        $effectivePermissions = $this->authService->getAllEffectivePermissions($userId);
        
        $this->json([
            'success' => true,
            'permissions' => $effectivePermissions
        ]);
    }

    /**
     * Search employees (AJAX)
     * GET /admin/permission-overrides/search
     */
    public function searchAction(): void
    {
        $this->requirePermissionManager();

        if (!$this->isAjaxRequest()) {
            $this->json(['error' => 'Invalid request'], 400);
            return;
        }

        $search = trim($_GET['q'] ?? '');
        
        if (strlen($search) < 2) {
            $this->json(['employees' => []]);
            return;
        }

        $conn = $this->getDbConnection();
        $searchParam = "%{$search}%";

        $stmt = $conn->prepare("
            SELECT 
                u.id as user_id,
                e.employee_id,
                CONCAT(e.first_name, ' ', e.last_name, IF(e.surname != '' AND e.surname IS NOT NULL, CONCAT(' ', e.surname), '')) as full_name,
                e.department,
                e.section,
                u.role
            FROM users u
            LEFT JOIN employees e ON u.email = e.email
            WHERE (e.employee_id LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ? OR e.surname LIKE ?)
            AND e.employee_status = 'active'
            ORDER BY e.first_name, e.last_name
            LIMIT 20
        ");

        $stmt->bind_param('ssss', $searchParam, $searchParam, $searchParam, $searchParam);
        $stmt->execute();
        $employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $this->json([
            'success' => true,
            'employees' => $employees
        ]);
    }

    /**
     * Require permission manager role
     */
    private function requirePermissionManager(): void
    {
        $this->authService->requirePermissionManager();
    }

    /**
     * Check if request is AJAX
     */
    private function isAjaxRequest(): bool
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) 
            && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
    }

    /**
     * Log audit event
     */
    private function logAudit(string $action, array $data = []): void
    {
        if (isset($_SESSION['user_id'])) {
            $conn = $this->getDbConnection();
            $userId = (int)$_SESSION['user_id'];
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

            $stmt = $conn->prepare("
                INSERT INTO audit_logs 
                (user_id, action, module, description, ip_address, user_agent, created_at)
                VALUES (?, ?, 'permission_overrides', ?, ?, ?, NOW())
            ");

            $description = json_encode($data);
            $module = 'permission_overrides';
            
            $stmt->bind_param("issss", $userId, $action, $description, $ipAddress, $userAgent);
            $stmt->execute();
            $stmt->close();
        }
    }
}
<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * RBAC Helper - Role-Based Access Control System
 * 
 * Manages roles, permissions, and access control for the application.
 */
class RBAC
{
    private static ?RBAC $instance = null;

    // Predefined roles - matching database roles
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_HR_MANAGER = 'hr_manager';
    public const ROLE_DEPT_HEAD = 'dept_head';
    public const ROLE_SECTION_HEAD = 'section_head';
    public const ROLE_SUB_SECTION_HEAD = 'sub_section_head';
    public const ROLE_OFFICER = 'officer';
    public const ROLE_EMPLOYEE = 'employee';

    // Permission actions
    public const ACTION_VIEW = 'view';
    public const ACTION_CREATE = 'create';
    public const ACTION_EDIT = 'edit';
    public const ACTION_DELETE = 'delete';
    public const ACTION_APPROVE = 'approve';
    public const ACTION_EXPORT = 'export';
    public const ACTION_MANAGE = 'manage';
    public const ACTION_APPLY = 'apply';

    // Module names - matching application routes
    public const MODULE_DASHBOARD = 'dashboard';
    public const MODULE_EMPLOYEES = 'employees';
    public const MODULE_DEPARTMENTS = 'departments';
    public const MODULE_ATTENDANCE = 'attendance';
    public const MODULE_LEAVE = 'leave';
    public const MODULE_REPORTS = 'reports';
    public const MODULE_USERS = 'users';
    public const MODULE_ADMIN = 'admin';
    public const MODULE_AUDIT = 'audit';
    public const MODULE_PROFILE = 'profile';
    public const MODULE_PERFORMANCE = 'performance';
    public const MODULE_CONSENT = 'consent';
    public const MODULE_PERMISSION_OVERRIDES = 'permission_overrides';

    /**
     * Default role-permission mapping.
     */
    private array $defaultPermissions = [
        self::ROLE_SUPER_ADMIN => [
            self::MODULE_DASHBOARD => ['view'],
            self::MODULE_EMPLOYEES => ['view', 'create', 'edit', 'delete'],
            self::MODULE_DEPARTMENTS => ['view', 'create', 'edit', 'delete'],
            self::MODULE_ATTENDANCE => ['view', 'manage'],
            self::MODULE_LEAVE => ['view', 'apply', 'manage'],
            self::MODULE_REPORTS => ['view', 'export'],
            self::MODULE_USERS => ['view', 'create', 'edit', 'delete'],
            self::MODULE_ADMIN => ['view', 'manage'],
            self::MODULE_AUDIT => ['view'],
            self::MODULE_PROFILE => ['view', 'edit'],
            self::MODULE_PERFORMANCE => ['view', 'manage'],
            self::MODULE_CONSENT => ['view', 'manage'],
            self::MODULE_PERMISSION_OVERRIDES => ['view', 'manage'],
        ],
        self::ROLE_HR_MANAGER => [
            self::MODULE_DASHBOARD => ['view'],
            self::MODULE_EMPLOYEES => ['view', 'create', 'edit', 'delete'],
            self::MODULE_DEPARTMENTS => ['view', 'create', 'edit'],
            self::MODULE_ATTENDANCE => ['view', 'manage'],
            self::MODULE_LEAVE => ['view', 'apply', 'manage'],
            self::MODULE_REPORTS => ['view', 'export'],
            self::MODULE_USERS => ['view', 'create', 'edit'],
            self::MODULE_ADMIN => ['view', 'manage'],
            self::MODULE_AUDIT => ['view'],
            self::MODULE_PROFILE => ['view', 'edit'],
            self::MODULE_PERFORMANCE => ['view', 'manage'],
            self::MODULE_CONSENT => ['view', 'manage'],
            self::MODULE_PERMISSION_OVERRIDES => ['view', 'manage'],
        ],
        self::ROLE_DEPT_HEAD => [
            self::MODULE_DASHBOARD => ['view'],
            self::MODULE_EMPLOYEES => ['view', 'edit'],
            self::MODULE_ATTENDANCE => ['view', 'manage'],
            self::MODULE_LEAVE => ['view', 'apply', 'manage'],
            self::MODULE_REPORTS => ['view', 'export'],
            self::MODULE_PROFILE => ['view', 'edit'],
            self::MODULE_PERFORMANCE => ['view', 'manage'],
        ],
        self::ROLE_SECTION_HEAD => [
            self::MODULE_DASHBOARD => ['view'],
            self::MODULE_EMPLOYEES => ['view'],
            self::MODULE_ATTENDANCE => ['view', 'manage'],
            self::MODULE_LEAVE => ['view', 'apply', 'manage'],
            self::MODULE_REPORTS => ['view'],
            self::MODULE_PROFILE => ['view', 'edit'],
            self::MODULE_PERFORMANCE => ['view'],
        ],
        self::ROLE_SUB_SECTION_HEAD => [
            self::MODULE_DASHBOARD => ['view'],
            self::MODULE_EMPLOYEES => ['view'],
            self::MODULE_ATTENDANCE => ['view', 'manage'],
            self::MODULE_LEAVE => ['view', 'apply', 'manage'],
            self::MODULE_REPORTS => ['view'],
            self::MODULE_PROFILE => ['view', 'edit'],
            self::MODULE_PERFORMANCE => ['view'],
        ],
        self::ROLE_OFFICER => [
            self::MODULE_DASHBOARD => ['view'],
            self::MODULE_EMPLOYEES => ['view'],
            self::MODULE_ATTENDANCE => ['view'],
            self::MODULE_LEAVE => ['view', 'apply'],
            self::MODULE_REPORTS => ['view'],
            self::MODULE_PROFILE => ['view', 'edit'],
            self::MODULE_PERFORMANCE => ['view'],
        ],
        self::ROLE_EMPLOYEE => [
            self::MODULE_DASHBOARD => ['view'],
            self::MODULE_ATTENDANCE => ['view'],
            self::MODULE_LEAVE => ['view', 'apply'],
            self::MODULE_REPORTS => ['view'],
            self::MODULE_PROFILE => ['view', 'edit'],
            self::MODULE_PERFORMANCE => ['view'],
        ],
    ];

    private function __construct() {}

    /**
     * Get the singleton instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Check if a user has permission for a specific action on a module.
     */
    public function hasPermission(string $role, string $module, string $action): bool
    {
        // Super admin has all permissions
        if ($role === self::ROLE_SUPER_ADMIN) {
            return true;
        }

        // Check database permissions first (custom permissions)
        try {
            $db = \db();
            if ($db) {
                $customPermission = $db->fetchOne(
                    "SELECT 1 FROM role_permissions 
                     WHERE role = ? AND module = ? AND action = ? AND is_granted = 1",
                    'sss',
                    [$role, $module, $action]
                );

                if ($customPermission) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            // If database query fails, fall back to default permissions
            error_log("RBAC database query failed: " . $e->getMessage());
        }

        // Fall back to default permissions
        return isset($this->defaultPermissions[$role][$module]) 
            && in_array($action, $this->defaultPermissions[$role][$module], true);
    }

    /**
     * Check if the current user has permission.
     */
    public function currentUserCan(string $module, string $action): bool
    {
        $role = $_SESSION['user_role'] ?? '';
        return $this->hasPermission($role, $module, $action);
    }

    /**
     * Require permission - redirects or returns 403 if not authorized.
     */
    public function requirePermission(string $module, string $action): void
    {
        if (!$this->currentUserCan($module, $action)) {
            if ($this->isApiRequest()) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden: You do not have permission to perform this action.']);
                exit();
            }
            
            // Don't redirect if we're already on dashboard (prevents loops)
            $currentUri = $_SERVER['REQUEST_URI'] ?? '';
            if (str_contains($currentUri, 'route=dashboard')) {
                echo "You do not have permission to access this resource.";
                exit();
            }
            
            $_SESSION['error'] = 'You do not have permission to access this resource.';
            header('Location: /?route=dashboard');
            exit();
        }
    }

    /**
     * Get all permissions for a role.
     */
    public function getRolePermissions(string $role): array
    {
        $db = \db();
        
        // Get custom permissions from database
        $customPermissions = $db->fetchAll(
            "SELECT module, action, is_granted FROM role_permissions WHERE role = ?",
            's',
            [$role]
        );

        // Merge with defaults
        $defaults = $this->defaultPermissions[$role] ?? [];
        
        // Apply custom overrides
        foreach ($customPermissions as $perm) {
            $module = $perm['module'];
            $action = $perm['action'];
            $granted = (bool) $perm['is_granted'];
            
            if (!isset($defaults[$module])) {
                $defaults[$module] = [];
            }
            
            if ($granted) {
                if (!in_array($action, $defaults[$module], true)) {
                    $defaults[$module][] = $action;
                }
            } else {
                $defaults[$module] = array_values(
                    array_filter($defaults[$module], fn($a) => $a !== $action)
                );
            }
        }

        return $defaults;
    }

    /**
     * Get all available roles.
     */
    public function getRoles(): array
    {
        return [
            self::ROLE_SUPER_ADMIN => 'Super Admin',
            self::ROLE_HR_MANAGER => 'HR Manager',
            self::ROLE_DEPT_HEAD => 'Department Head',
            self::ROLE_SECTION_HEAD => 'Section Head',
            self::ROLE_SUB_SECTION_HEAD => 'Sub Section Head',
            self::ROLE_OFFICER => 'Officer',
            self::ROLE_EMPLOYEE => 'Employee',
        ];
    }

    /**
     * Get all available modules.
     */
    public function getModules(): array
    {
        return [
            self::MODULE_DASHBOARD => 'Dashboard',
            self::MODULE_EMPLOYEES => 'Employees',
            self::MODULE_DEPARTMENTS => 'Departments',
            self::MODULE_ATTENDANCE => 'Attendance',
            self::MODULE_LEAVE => 'Leave Management',
            self::MODULE_REPORTS => 'Reports',
            self::MODULE_USERS => 'User Management',
            self::MODULE_ADMIN => 'Admin',
            self::MODULE_AUDIT => 'Audit Trail',
            self::MODULE_PROFILE => 'Profile',
            self::MODULE_PERFORMANCE => 'Performance',
            self::MODULE_CONSENT => 'Consent',
            self::MODULE_PERMISSION_OVERRIDES => 'Permission Overrides',
        ];
    }

    /**
     * Get all available actions.
     */
    public function getActions(): array
    {
        return [
            self::ACTION_VIEW => 'View',
            self::ACTION_CREATE => 'Create',
            self::ACTION_EDIT => 'Edit',
            self::ACTION_DELETE => 'Delete',
            self::ACTION_APPROVE => 'Approve',
            self::ACTION_EXPORT => 'Export',
            self::ACTION_MANAGE => 'Manage',
            self::ACTION_APPLY => 'Apply',
        ];
    }

    /**
     * Check if the current request is an API request.
     */
    private function isApiRequest(): bool
    {
        return str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));
    }

    private function __clone(): void {}
    public function __wakeup(): void
    {
        throw new \RuntimeException('Cannot unserialize singleton');
    }
}

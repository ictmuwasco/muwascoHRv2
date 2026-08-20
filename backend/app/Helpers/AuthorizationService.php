<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Helpers\RBAC;
use App\Models\UserPagePermission;

/**
 * AuthorizationService - Hybrid Authorization System
 *
 * Implements a centralized authorization service that combines:
 * 1. Role-Based Access Control (RBAC) — role_permissions table
 * 2. User-Specific Permission Overrides — user_page_permissions table
 *
 * Authorization Hierarchy (Priority Order):
 * 1. SUPER ADMIN  → ALLOW (handled in RBAC.php)
 * 2. User Override (allow/deny) → ALLOW / DENY
 * 3. Role Permission → ALLOW / DENY
 * 4. Default Deny
 *
 * Permission Override Model:
 * 'allow' = explicitly allow this user for module/action
 * 'deny'  = explicitly deny this user for module/action
 * (no record) = inherit role permission
 *
 * Place: backend/app/Helpers/AuthorizationService.php
 */
class AuthorizationService
{
    private static ?AuthorizationService $instance = null;
    private ?RBAC $rbac = null;
    private ?UserPagePermission $userPagePermissionModel = null;

    /**
     * Cached user-specific overrides for the current user session.
     * Format: ['module|action' => 'allow'|'deny']
     * Only stores explicit overrides (allow/deny), NOT inherited permissions.
     */
    private array $cachedOverrides = [];

    /**
     * Flag to track if overrides have been loaded for current request.
     */
    private bool $overridesLoaded = false;

    /**
     * The user ID for whom overrides were loaded.
     */
    private int $cachedUserId = 0;

    private function __construct()
    {
        $this->rbac = RBAC::getInstance();
        // Only initialize UserPagePermission if table exists
        if ($this->tableExists('user_page_permissions')) {
            $this->userPagePermissionModel = new UserPagePermission();
        } else {
            $this->userPagePermissionModel = null;
        }
    }

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
     * Check if a user has permission for a specific module/action.
     *
     * Authorization Flow:
     *   1. Is user Super Admin? → ALLOW (via RBAC)
     *   2. Does active user override exist?
     *      - allow → ALLOW
     *      - deny  → DENY
     *   3. Check role_permissions for (role, module, action)
     *   4. Default → DENY
     *
     * @param int|null $userId User ID (null for current session user)
     * @param string $module Module name (e.g., 'reports', 'employees')
     * @param string $action Action (e.g., 'view', 'export', 'create')
     * @return bool True if access is granted
     */
    public function hasPermission(?int $userId, string $module, string $action): bool
    {
        if ($userId === null) {
            $userId = (int)($_SESSION['user_id'] ?? 0);
        }

        if ($userId === 0) {
            return false;
        }

        // Load overrides if not already loaded for this user
        if (!$this->overridesLoaded || $this->cachedUserId !== $userId) {
            $this->loadUserPermissionOverrides($userId);
        }

        $key = "{$module}|{$action}";

        // Priority 2: Explicit User Override
        if (isset($this->cachedOverrides[$key])) {
            return $this->cachedOverrides[$key] === 'allow';
        }

        // Priority 1 & 3: Super Admin or Role Permission (RBAC handles super_admin)
        $role = $_SESSION['user_role'] ?? '';
        if ($role === '') {
            // Try to get role from database
            try {
                $userModel = new \App\Models\User();
                $user = $userModel->findById($userId);
                if ($user) {
                    $role = $user['role'] ?? '';
                }
            } catch (\Exception $e) {
                error_log("Failed to load user role: " . $e->getMessage());
            }
        }

        if (empty($role)) {
            return false;
        }

        // RBAC::hasPermission handles super_admin (returns true) and role_permissions lookup
        return $this->rbac->hasPermission($role, $module, $action);
    }

    /**
     * Backward-compatible alias: check page-level access.
     * Calls hasPermission with action='view'.
     *
     * @param int|null $userId User ID (null for current session user)
     * @param string $pageId Page/module identifier
     * @return bool True if access is granted
     */
    public function hasPageAccess(?int $userId, string $pageId): bool
    {
        return $this->hasPermission($userId, $pageId, 'view');
    }

    /**
     * Get the effective permission for a module/action with source information.
     *
     * @param int|null $userId User ID (null for current session user)
     * @param string $module Module name
     * @param string $action Action name
     * @return array ['allowed' => bool, 'source' => string, 'permission_type' => string|null]
     */
    public function getEffectivePermission(?int $userId, string $module, string $action): array
    {
        if ($userId === null) {
            $userId = (int)($_SESSION['user_id'] ?? 0);
        }

        if ($userId === 0) {
            return ['allowed' => false, 'source' => 'no_user', 'permission_type' => null];
        }

        // Load overrides if not already loaded for this user
        if (!$this->overridesLoaded || $this->cachedUserId !== $userId) {
            $this->loadUserPermissionOverrides($userId);
        }

        $key = "{$module}|{$action}";

        // Priority: User Override
        if (isset($this->cachedOverrides[$key])) {
            $override = $this->cachedOverrides[$key];
            return [
                'allowed' => $override === 'allow',
                'source' => $override === 'allow' ? 'User Grant' : 'User Deny',
                'permission_type' => $override,
            ];
        }

        // Check role permissions (RBAC handles super_admin)
        $role = $_SESSION['user_role'] ?? '';
        if ($role === '') {
            try {
                $userModel = new \App\Models\User();
                $user = $userModel->findById($userId);
                if ($user) {
                    $role = $user['role'] ?? '';
                }
            } catch (\Exception $e) {
                error_log("Failed to load user role: " . $e->getMessage());
            }
        }

        if (empty($role)) {
            return ['allowed' => false, 'source' => 'no_role', 'permission_type' => null];
        }

        // Super Admin check (RBAC handles this)
        if ($role === 'super_admin') {
            return ['allowed' => true, 'source' => 'Super Admin', 'permission_type' => null];
        }

        // Check role_permissions
        if ($this->rbac->hasPermission($role, $module, $action)) {
            return ['allowed' => true, 'source' => 'Role', 'permission_type' => null];
        }

        return ['allowed' => false, 'source' => 'Default Deny', 'permission_type' => null];
    }

    /**
     * Load all permission overrides for a user (module/action granularity) into cache.
     * Only active overrides are loaded; inactive overrides are treated as no override.
     *
     * @param int $userId
     */
    private function loadUserPermissionOverrides(int $userId): void
    {
        try {
            $this->cachedOverrides = [];
            $this->overridesLoaded = true;
            $this->cachedUserId = $userId;

            if ($this->userPagePermissionModel === null) {
                return;
            }

            // Get all active user-specific overrides
            $overrides = $this->userPagePermissionModel->getByUserId($userId);

            // Build override map: 'module|action' => 'allow'|'deny'
            foreach ($overrides as $override) {
                $key = "{$override['module']}|{$override['action']}";
                $this->cachedOverrides[$key] = $override['permission_type'];
            }
        } catch (\Throwable $e) {
            error_log("Critical error in loadUserPermissionOverrides for user {$userId}: " . $e->getMessage());
            $this->cachedOverrides = [];
            $this->overridesLoaded = true;
            $this->cachedUserId = $userId;
        }
    }

    /**
     * Clear the permission cache for the current user.
     * Call this when permissions are modified.
     */
    public function clearCache(): void
    {
        $this->cachedOverrides = [];
        $this->overridesLoaded = false;
        $this->cachedUserId = 0;
    }

    /**
     * Set a permission override for a user.
     *
     * @param int $userId Target user
     * @param string $module Module name
     * @param string $action Action name
     * @param string $permissionType 'allow' or 'deny'
     * @param int $grantedBy User who is granting
     * @param int $updatedBy User who is updating (for change tracking)
     * @param string|null $notes Administrative context
     * @return bool Success status
     */
    public function setPermissionOverride(
        int $userId,
        string $module,
        string $action,
        string $permissionType,
        int $grantedBy,
        int $updatedBy,
        ?string $notes = null
    ): bool {
        if ($this->userPagePermissionModel === null) {
            return false;
        }

        $result = $this->userPagePermissionModel->setPermission(
            $userId, $module, $action, $permissionType, $grantedBy, $updatedBy, $notes
        );

        if ($result) {
            // Clear cache to force reload
            $this->clearCache();
        }

        return $result;
    }

    /**
     * Remove a permission override for a user.
     *
     * @param int $userId Target user
     * @param string $module Module name
     * @param string $action Action name
     * @return bool Success status
     */
    public function removePermissionOverride(int $userId, string $module, string $action): bool
    {
        if ($this->userPagePermissionModel === null) {
            return false;
        }

        $result = $this->userPagePermissionModel->removePermission($userId, $module, $action);

        if ($result) {
            // Clear cache to force reload
            $this->clearCache();
        }

        return $result;
    }

    /**
     * Get all effective permissions for a user with source information.
     * Used for displaying the permission management UI.
     *
     * Iterates over all module/action combinations from role_permissions
     * and resolves each through the authorization hierarchy.
     *
     * @param int $userId
     * @return array Array of ['module', 'action', 'allowed', 'source', 'permission_type']
     */
    public function getAllEffectivePermissions(int $userId): array
    {
        $role = $_SESSION['user_role'] ?? '';

        if (empty($role)) {
            try {
                $userModel = new \App\Models\User();
                $user = $userModel->findById($userId);
                if ($user) {
                    $role = $user['role'] ?? '';
                }
            } catch (\Exception $e) {
                error_log("Failed to load user role: " . $e->getMessage());
            }
        }

        if (empty($role)) {
            return [];
        }

        // Get all role permissions (module/action combinations) from RBAC
        $rolePermissions = $this->rbac->getRolePermissions($role);

        // Load user overrides for this user
        $this->overridesLoaded = false;
        $this->loadUserPermissionOverrides($userId);

        $permissions = [];
        $moduleNames = $this->userPagePermissionModel ? $this->userPagePermissionModel->getModuleNames() : [];

        foreach ($rolePermissions as $perm) {
            $module = $perm['module'];
            $action = $perm['action'];
            $effective = $this->getEffectivePermission($userId, $module, $action);
            $permissions[] = [
                'module'            => $module,
                'module_name'       => $moduleNames[$module] ?? $module,
                'action'            => $action,
                'allowed'           => $effective['allowed'],
                'source'            => $effective['source'],
                'permission_type'   => $effective['permission_type'],
            ];
        }

        return $permissions;
    }

    /**
     * Check if the current user is authorized to manage permission overrides.
     * Only Super Administrators and HR Managers can manage permission overrides.
     *
     * @return bool
     */
    public function isPermissionManager(): bool
    {
        if (!Auth::getInstance()->check()) {
            return false;
        }

        // Super Admin and HR Manager can manage permission overrides
        $allowedRoles = ['super_admin', 'hr_manager'];
        $role = $_SESSION['user_role'] ?? '';
        return in_array($role, $allowedRoles, true);
    }

    /**
     * Require permission manager role - returns 403 if not authorized.
     */
    public function requirePermissionManager(): void
    {
        if (!$this->isPermissionManager()) {
            error_log("PERMISSION DEBUG: User role='" . ($_SESSION['user_role'] ?? 'none') . "' denied access to permission-overrides/manage");

            if ($this->isApiRequest()) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden: Only Super Administrators and HR Managers can manage permission overrides.']);
                exit();
            }

            $_SESSION['flash_error'] = 'You do not have permission to access this resource.';
            $baseUrl = defined('BASE_URL') ? BASE_URL : '';
            $redirectUrl = $baseUrl . '/?route=admin/permission-overrides';
            header('Location: ' . $redirectUrl);
            exit();
        }
    }

    /**
     * Check if the current request is an API request.
     */
    private function isApiRequest(): bool
    {
        return str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));
    }

    /**
     * Check if a database table exists.
     *
     * @param string $tableName
     * @return bool
     */
    private function tableExists(string $tableName): bool
    {
        try {
            $conn = \App\Helpers\Database::getInstance()->getConnection();
            $result = $conn->query("SHOW TABLES LIKE '{$tableName}'");
            return $result && $result->num_rows > 0;
        } catch (\Exception $e) {
            error_log("Failed to check if table {$tableName} exists: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Prevent cloning of the singleton instance.
     */
    private function __clone(): void {}

    /**
     * Prevent unserialization of the singleton instance.
     */
    public function __wakeup()
    {
        throw new \RuntimeException('Cannot unserialize singleton');
    }
}

/**
 * Global helper function to check page access.
 * Usage: hasPageAccess(null, 'dashboard') or hasPageAccess($userId, 'payroll')
 */
function hasPageAccess(?int $userId, string $pageId): bool
{
    return AuthorizationService::getInstance()->hasPageAccess($userId, $pageId);
}

/**
 * Global helper function to get effective permission with source.
 */
function getEffectivePermission(?int $userId, string $module, string $action = 'view'): array
{
    return AuthorizationService::getInstance()->getEffectivePermission($userId, $module, $action);
}

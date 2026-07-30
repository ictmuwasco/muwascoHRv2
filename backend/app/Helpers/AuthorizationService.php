<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Models\UserPagePermission;

/**
 * AuthorizationService - Hybrid Authorization System
 * 
 * Implements a centralized authorization service that combines:
 * 1. Role-Based Access Control (RBAC)
 * 2. User-Specific Permission Overrides
 * 
 * Authorization Hierarchy (Priority Order):
 * 1. Explicit User Deny (Highest Priority)
 * 2. Explicit User Grant
 * 3. Role Permissions
 * 4. Default Deny
 * 
 * Place: backend/app/Helpers/AuthorizationService.php
 */
class AuthorizationService
{
    private static ?AuthorizationService $instance = null;
    private ?RBAC $rbac = null;
    private ?UserPagePermission $userPagePermissionModel = null;

    /**
     * Cached permissions for the current user session
     * Format: ['page_id' => 'allow'|'deny'|'role']
     */
    private array $cachedPermissions = [];

    /**
     * Flag to track if permissions have been loaded for current request
     */
    private bool $permissionsLoaded = false;

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
     * Check if a user has access to a specific page/module.
     * 
     * Authorization Flow:
     * 1. Check for explicit user deny → DENY
     * 2. Check for explicit user grant → ALLOW
     * 3. Check role permissions → ALLOW/DENY based on role
     * 4. Default → DENY
     *
     * @param int|null $userId User ID (null for current user)
     * @param string $pageId Page/module identifier
     * @return bool True if access is granted
     */
    public function hasPageAccess(?int $userId, string $pageId): bool
    {
        // If no user ID provided, use current session user
        if ($userId === null) {
            $userId = (int)($_SESSION['user_id'] ?? 0);
        }

        if ($userId === 0) {
            return false;
        }

        // Load permissions if not already loaded for this request
        if (!$this->permissionsLoaded) {
            $this->loadUserPermissions($userId);
        }

        // Check cache first
        if (isset($this->cachedPermissions[$pageId])) {
            // 'allow' (user grant) or 'role' (role permission) both mean access granted
            return $this->cachedPermissions[$pageId] === 'allow' || $this->cachedPermissions[$pageId] === 'role';
        }

        // If not in cache, default to deny
        return false;
    }

    /**
     * Get the effective permission for a page with source information.
     * 
     * @param int|null $userId User ID (null for current user)
     * @param string $pageId Page/module identifier
     * @return array ['allowed' => bool, 'source' => string, 'permission_type' => string|null]
     */
    public function getEffectivePermission(?int $userId, string $pageId): array
    {
        if ($userId === null) {
            $userId = (int)($_SESSION['user_id'] ?? 0);
        }

        if ($userId === 0) {
            return ['allowed' => false, 'source' => 'no_user', 'permission_type' => null];
        }

        // Load permissions if not already loaded
        if (!$this->permissionsLoaded) {
            $this->loadUserPermissions($userId);
        }

        // Check cache
        if (isset($this->cachedPermissions[$pageId])) {
            $source = $this->cachedPermissions[$pageId];
            $allowed = $source === 'allow';
            
            return [
                'allowed' => $allowed,
                'source' => $source === 'role' ? 'Role' : ($source === 'allow' ? 'User Grant' : 'User Deny'),
                'permission_type' => in_array($source, ['allow', 'deny']) ? $source : null
            ];
        }

        // Not in cache = not allowed
        return ['allowed' => false, 'source' => 'Default Deny', 'permission_type' => null];
    }

    /**
     * Load all permissions for a user (role + overrides) into cache.
     * 
     * @param int $userId
     */
    private function loadUserPermissions(int $userId): void
    {
        try {
            $this->cachedPermissions = [];
            $this->permissionsLoaded = true;

            // Get user role from session or database
            $role = $_SESSION['user_role'] ?? '';
            
            if (empty($role)) {
                // Try to get from database
                try {
                    $userModel = new \App\Models\User();
                    $user = $userModel->findById($userId);
                    if ($user) {
                        $role = $user['role'] ?? '';
                    }
                } catch (\Exception $e) {
                    // If User model fails, just use session role
                    error_log("Failed to load user role: " . $e->getMessage());
                }
            }

            if (empty($role)) {
                return; // No role = no permissions
            }

            // Get all page IDs - use hardcoded list if table doesn't exist
            $allPages = $this->getAllPageIds();

            // Get all user-specific overrides (only if table exists)
            $overrideMap = [];
            if ($this->userPagePermissionModel !== null) {
                try {
                    $userOverrides = $this->userPagePermissionModel->getByUserId($userId);
                    
                    // Build override map: page_id => permission_type
                    foreach ($userOverrides as $override) {
                        $overrideMap[$override['page_id']] = $override['permission_type'];
                    }
                } catch (\Exception $e) {
                    error_log("Failed to load user overrides: " . $e->getMessage());
                }
            }

            // Apply authorization hierarchy for each page
            foreach ($allPages as $pageId) {
                // Priority 1: Explicit User Deny
                if (isset($overrideMap[$pageId]) && $overrideMap[$pageId] === 'deny') {
                    $this->cachedPermissions[$pageId] = 'deny';
                    continue;
                }

                // Priority 2: Explicit User Grant
                if (isset($overrideMap[$pageId]) && $overrideMap[$pageId] === 'allow') {
                    $this->cachedPermissions[$pageId] = 'allow';
                    continue;
                }

                // Priority 3: Role Permissions
                // Check if role has view permission for this module
                try {
                    if ($this->rbac->hasPermission($role, $pageId, 'view')) {
                        $this->cachedPermissions[$pageId] = 'role';
                        continue;
                    }
                } catch (\Exception $e) {
                    error_log("Failed to check RBAC permission for role={$role}, page={$pageId}: " . $e->getMessage());
                }

                // Priority 4: Default Deny (not in cache = denied)
            }
        } catch (\Exception $e) {
            error_log("Critical error in loadUserPermissions for user {$userId}: " . $e->getMessage());
            // Set empty cache on critical error
            $this->cachedPermissions = [];
            $this->permissionsLoaded = true;
        }
    }

    /**
     * Clear the permission cache for the current user.
     * Call this when permissions are modified.
     */
    public function clearCache(): void
    {
        $this->cachedPermissions = [];
        $this->permissionsLoaded = false;
    }

    /**
     * Set a permission override for a user.
     * 
     * @param int $userId
     * @param string $pageId
     * @param string $permissionType 'allow' or 'deny'
     * @param int $grantedBy
     * @param string|null $notes
     * @return bool Success status
     */
    public function setPermissionOverride(int $userId, string $pageId, string $permissionType, int $grantedBy, ?string $notes = null): bool
    {
        $result = $this->userPagePermissionModel->setPermission($userId, $pageId, $permissionType, $grantedBy, $notes);
        
        if ($result) {
            // Clear cache to force reload
            $this->clearCache();
        }

        return $result;
    }

    /**
     * Remove a permission override for a user.
     * 
     * @param int $userId
     * @param string $pageId
     * @return bool Success status
     */
    public function removePermissionOverride(int $userId, string $pageId): bool
    {
        $result = $this->userPagePermissionModel->removePermission($userId, $pageId);
        
        if ($result) {
            // Clear cache to force reload
            $this->clearCache();
        }

        return $result;
    }

    /**
     * Get all effective permissions for a user with source information.
     * Used for displaying the permission preview.
     * 
     * @param int $userId
     * @return array Array of ['page_id', 'page_name', 'allowed', 'source']
     */
    public function getAllEffectivePermissions(int $userId): array
    {
        $allPages = $this->getAllPageIds();
        $pageNames = $this->getPageNames();

        // Temporarily load permissions for this user
        $this->permissionsLoaded = false;
        $this->loadUserPermissions($userId);

        $permissions = [];
        foreach ($allPages as $pageId) {
            $effective = $this->getEffectivePermission($userId, $pageId);
            $permissions[] = [
                'page_id' => $pageId,
                'page_name' => $pageNames[$pageId] ?? $pageId,
                'allowed' => $effective['allowed'],
                'source' => $effective['source'],
                'permission_type' => $effective['permission_type']
            ];
        }

        return $permissions;
    }

    /**
     * Get role permissions for a user.
     * 
     * @param int|null $userId
     * @return array Array of module => [actions]
     */
    public function getRolePermissions(?int $userId = null): array
    {
        if ($userId === null) {
            $userId = (int)($_SESSION['user_id'] ?? 0);
        }

        $role = $_SESSION['user_role'] ?? '';
        
        if (empty($role) && $userId > 0) {
            $userModel = new \App\Models\User();
            $user = $userModel->findById($userId);
            if ($user) {
                $role = $user['role'] ?? '';
            }
        }

        if (empty($role)) {
            return [];
        }

        return $this->rbac->getRolePermissions($role);
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

        // Only super_admin and hr_manager have permission_overrides permissions
        $allowedRoles = ['super_admin', 'hr_manager'];
        return in_array($_SESSION['user_role'], $allowedRoles);
    }

    /**
     * Require permission manager role - redirects or returns 403 if not authorized.
     */
    public function requirePermissionManager(): void
    {
        if (!$this->isPermissionManager()) {
            // DEBUG: Log the redirect attempt
            error_log("PERMISSION DEBUG: User role='" . ($_SESSION['user_role'] ?? 'none') . "' denied access to permission-overrides/manage");
            
            if ($this->isApiRequest()) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden: Only Super Administrators can manage permission overrides.']);
                exit();
            }
            
            $_SESSION['flash_error'] = 'You do not have permission to access this resource.';
            // Build redirect URL with BASE_URL prefix
            $baseUrl = defined('BASE_URL') ? BASE_URL : '';
            $redirectUrl = $baseUrl . '/?route=admin/permission-overrides';
            
            // DEBUG: Log the redirect destination
            error_log("PERMISSION DEBUG: Redirecting to: {$redirectUrl}");
            
            header('Location: ' . $redirectUrl);
            exit();
        } else {
            // DEBUG: Log successful access
            error_log("PERMISSION DEBUG: User role='" . ($_SESSION['user_role'] ?? 'none') . "' GRANTED access to permission-overrides/manage");
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
     * Get all available page IDs from the system.
     *
     * @return array Array of page IDs
     */
    private function getAllPageIds(): array
    {
        return [
            'dashboard',
            'employees',
            'departments',
            'attendance',
            'leave',
            'reports',
            'users',
            'admin',
            'audit',
            'profile',
            'performance',
            'consent',
            'permission_overrides'
        ];
    }

    /**
     * Get page display names.
     *
     * @return array Array of page_id => display_name
     */
    private function getPageNames(): array
    {
        return [
            'dashboard' => 'Dashboard',
            'employees' => 'Employees',
            'departments' => 'Departments',
            'attendance' => 'Attendance',
            'leave' => 'Leave Management',
            'reports' => 'Reports',
            'users' => 'User Management',
            'admin' => 'Admin',
            'audit' => 'Audit Trail',
            'profile' => 'Profile',
            'performance' => 'Performance',
            'consent' => 'Consent',
            'permission_overrides' => 'Permission Overrides'
        ];
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
function getEffectivePermission(?int $userId, string $pageId): array
{
    return AuthorizationService::getInstance()->getEffectivePermission($userId, $pageId);
}
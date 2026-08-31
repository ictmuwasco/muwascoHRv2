<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Helpers\RBAC;
use App\Models\UserPagePermission;

/**
 * AuthorizationService - Hybrid Authorization System (THE single authorization engine)
 *
 * Implements a centralized authorization service that combines:
 * 1. Role-Based Access Control (RBAC) — role_permissions table
 * 2. User-Specific Permission Overrides — user_page_permissions table
 *
 * Authorization Hierarchy (Priority Order — authoritative as of Phase 2):
 *   1. Unauthenticated / unknown user        → DENY
 *   2. SUPER ADMIN (role resolved from
 *      trusted context)                      → ALLOW (never overridden, never denied)
 *   3. Explicit User Override (allow/deny)   → ALLOW / DENY
 *   4. Self-service own-profile exception    → ALLOW
 *   5. Role Permission                       → ALLOW / DENY
 *   6. No rule matched                       → DEFAULT DENY
 *
 * Super Admin policy: the super_admin role always has full access. Overrides
 * can never restrict a super_admin — the administration UI refuses to create
 * overrides for super_admin accounts (PermissionService::setOverride) and any
 * stray rows in user_page_permissions are ignored here. This rule is enforced
 * consistently in RBAC, in this service and in the permission UI.
 *
 * Permission Override Model:
 * 'allow' = explicitly allow this user for module/action
 * 'deny'  = explicitly deny this user for module/action
 * (no record) = inherit role permission
 *
 * Data scope (WHICH records a user may access) is deliberately NOT answered
 * here — authorization answers "CAN the user do this action?"; organizational
 * scope lives in reusable scope policies (App\Helpers\OrgScope and the
 * per-module workflow services).
 *
 * Callers ALWAYS pass a user ID (or null for the session user). Roles are
 * derived from trusted context in resolveUserRole() — never passed in.
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

        // Priority 1: Unauthenticated / unknown user → DENY
        if ($userId <= 0) {
            return false;
        }

        // Resolve the role from TRUSTED context: the session role for the
        // authenticated user, the database record for explicit other-user
        // checks. A role string must never travel through the $userId
        // parameter (that mismatch broke the legacy middleware/policy code).
        $role = $this->resolveUserRole($userId);

        if ($role === '') {
            return false;
        }

        // Priority 2: SUPER ADMIN → ALLOW.
        // Documented policy: super_admin can never be restricted — not by
        // role_permissions, not by user_page_permissions overrides (which the
        // admin UI refuses to create for super_admin accounts anyway).
        if ($role === 'super_admin') {
            return true;
        }

        // Load overrides if not already loaded for this user
        if (!$this->overridesLoaded || $this->cachedUserId !== $userId) {
            $this->loadUserPermissionOverrides($userId);
        }

        $key = "{$module}|{$action}";

        // Priority 3: Explicit User Override (allow/deny beats role permission)
        if (isset($this->cachedOverrides[$key])) {
            return $this->cachedOverrides[$key] === 'allow';
        }

        // Priority 4: Self-Service Profile — any authenticated user may view
        // and edit their OWN profile. This is fundamental self-service and must
        // not depend on role_permissions seeding (a missing/un-granted row for
        // 'profile:view' previously denied access → 403 on GET /api/profile).
        // The profile controller resolves the record to $userId, so this can
        // never expose another user's data. A USER-DENY override (checked above)
        // still takes precedence.
        if ($module === 'profile') {
            return true;
        }

        // Priority 5: Role Permission → ALLOW / DENY (RBAC default-denies)
        return $this->rbac->hasPermission($role, $module, $action);
    }

    /**
     * Resolve the role for a user from a trusted source.
     *
     * - Session user: the session role populated by AuthService::login() and
     *   refreshed from the database by Auth::authenticateFromToken().
     * - Any other explicit user id: the database record. The acting user's
     *   session role must never be used to authorize somebody else.
     *
     * This is the ONLY place authorization derives a role.
     */
    private function resolveUserRole(int $userId): string
    {
        $sessionUserId = (int)($_SESSION['user_id'] ?? 0);
        $sessionRole = (string)($_SESSION['user_role'] ?? '');

        if ($sessionRole !== '' && $sessionUserId === $userId) {
            return $sessionRole;
        }

        try {
            $userModel = new \App\Models\User();
            $user = $userModel->findById($userId);
            if ($user) {
                return (string)($user['role'] ?? '');
            }
        } catch (\Exception $e) {
            error_log("Failed to load user role for user {$userId}: " . $e->getMessage());
        }

        return '';
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

        if ($userId <= 0) {
            return ['allowed' => false, 'source' => 'no_user', 'permission_type' => null];
        }

        // Role for the TARGET user from trusted context (session for self,
        // database for explicit other-user checks — never the acting
        // session's role).
        $role = $this->resolveUserRole($userId);
        if ($role === '') {
            return ['allowed' => false, 'source' => 'no_role', 'permission_type' => null];
        }

        // Super Admin → always allowed (documented policy; cannot be overridden)
        if ($role === 'super_admin') {
            return ['allowed' => true, 'source' => 'Super Admin', 'permission_type' => null];
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

        // Self-service own-profile exception
        if ($module === 'profile') {
            return ['allowed' => true, 'source' => 'Self Service', 'permission_type' => null];
        }

        // Check role permissions
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
        // The role MUST be resolved for the TARGET user ($userId) — never from
        // the session, which holds the acting administrator's role. This was
        // a live bug: an admin inspecting another user's permissions saw the
        // admin's own role permission set.
        $role = $this->resolveUserRole($userId);

        if ($role === '') {
            return [];
        }

        // Load user overrides for this user
        $this->overridesLoaded = false;
        $this->loadUserPermissionOverrides($userId);

        $moduleNames = $this->userPagePermissionModel ? $this->userPagePermissionModel->getModuleNames() : [];

        // super_admin effectively holds every permission defined in the
        // catalog (RBAC bypasses role_permissions for super_admin), so
        // enumerate the catalog instead of the (partial) seed rows.
        if ($role === 'super_admin') {
            $permissions = [];
            $catalog = config('permissions.modules', []);
            foreach ($catalog as $moduleKey => $moduleDef) {
                foreach (($moduleDef['actions'] ?? []) as $actionDef) {
                    $permissions[] = [
                        'module'            => $moduleKey,
                        'module_name'       => $moduleNames[$moduleKey] ?? ($moduleDef['label'] ?? $moduleKey),
                        'action'            => $actionDef['key'],
                        'allowed'           => true,
                        'source'            => 'Super Admin',
                        'permission_type'   => null,
                    ];
                }
            }
            return $permissions;
        }

        // Get all role permissions (module/action combinations) from RBAC
        $rolePermissions = $this->rbac->getRolePermissions($role);

        $permissions = [];
        foreach ($rolePermissions as $perm) {
            $module = $perm['module'];
            $action = $perm['action'];
            $key = "{$module}|{$action}";

            // Resolve in-memory (overrides already loaded) instead of calling
            // getEffectivePermission() per row — that would re-resolve the
            // target user's role from the database for every combination.
            $allowed = false;
            $source = 'Default Deny';
            $permissionType = null;

            if (isset($this->cachedOverrides[$key])) {
                $permissionType = $this->cachedOverrides[$key];
                $allowed = $permissionType === 'allow';
                $source = $allowed ? 'User Grant' : 'User Deny';
            } elseif ($module === 'profile') {
                $allowed = true;
                $source = 'Self Service';
            } elseif ($this->rbac->hasPermission($role, $module, $action)) {
                $allowed = true;
                $source = 'Role';
            }

            $permissions[] = [
                'module'            => $module,
                'module_name'       => $moduleNames[$module] ?? $module,
                'action'            => $action,
                'allowed'           => $allowed,
                'source'            => $source,
                'permission_type'   => $permissionType,
            ];
        }

        return $permissions;
    }

    /**
     * Compact list of the user's effective permission keys ("module:action").
     *
     * Consumed by GET /api/auth/user to drive the frontend permission context
     * (sidebar visibility, route guards, action buttons). The frontend uses
     * this for UX ONLY — the backend always enforces authorization again.
     *
     * @param int $userId
     * @return array<int, string> e.g. ['employees:view', 'reports:export']
     */
    public function getAllowedPermissionKeys(int $userId): array
    {
        $keys = [];
        foreach ($this->getAllEffectivePermissions($userId) as $perm) {
            if (!empty($perm['allowed'])) {
                $keys[] = $perm['module'] . ':' . $perm['action'];
            }
        }
        return $keys;
    }

    /**
     * Effective permission strings ("module:action") for the frontend
     * permission context (sidebar visibility, button rendering, route guards).
     *
     * UX ONLY — the backend always enforces authorization independently on
     * every request; this list must never be treated as a security boundary.
     *
     * Resolution mirrors hasPermission(): super_admin → full catalog (documented
     * policy), then explicit user overrides, then the self-service profile
     * exception, then role permissions. The TARGET user's role is resolved from
     * trusted context — never the acting session's role.
     *
     * @param int|null $userId User ID (null for the current session user)
     * @return string[] e.g. ["employees:view", "leave:approve"]
     */
    public function getEffectivePermissionStrings(?int $userId): array
    {
        if ($userId === null) {
            $userId = (int)($_SESSION['user_id'] ?? 0);
        }
        if ($userId <= 0) {
            return [];
        }

        $catalog = $this->permissionCatalog();
        if ($catalog === []) {
            return [];
        }

        $role = $this->resolveUserRole($userId);
        // RBAC normalizes the legacy 'admin' alias to super_admin.
        if ($role === 'admin') {
            $role = 'super_admin';
        }
        if ($role === '') {
            return [];
        }

        // Documented super-admin policy: full access to every catalog entry.
        if ($role === 'super_admin') {
            $all = [];
            foreach ($catalog as $module) {
                foreach ($module['actions'] as $action) {
                    $all[] = $module['key'] . ':' . $action['key'];
                }
            }
            return $all;
        }

        // Load overrides once for this user.
        if (!$this->overridesLoaded || $this->cachedUserId !== $userId) {
            $this->loadUserPermissionOverrides($userId);
        }

        // Role permissions in one query (instead of one lookup per action).
        $granted = [];
        try {
            foreach ($this->rbac->getRolePermissions($role) as $row) {
                if ((int)($row['is_granted'] ?? 0) === 1) {
                    $granted[$row['module'] . '|' . $row['action']] = true;
                }
            }
        } catch (\Throwable $e) {
            error_log("Failed to load role permissions for role {$role}: " . $e->getMessage());
        }

        $out = [];
        foreach ($catalog as $module) {
            foreach ($module['actions'] as $action) {
                $key = $module['key'] . '|' . $action['key'];

                // Explicit user override wins (allow/deny).
                if (isset($this->cachedOverrides[$key])) {
                    if ($this->cachedOverrides[$key] === 'allow') {
                        $out[] = $module['key'] . ':' . $action['key'];
                    }
                    continue;
                }

                // Self-service own-profile exception (matches hasPermission()).
                if ($module['key'] === 'profile') {
                    $out[] = $module['key'] . ':' . $action['key'];
                    continue;
                }

                if (isset($granted[$key])) {
                    $out[] = $module['key'] . ':' . $action['key'];
                }
            }
        }

        return $out;
    }

    /**
     * Load the permission catalog module map (cached per request).
     *
     * @return array[] list of module definitions
     */
    private function permissionCatalog(): array
    {
        static $modules = null;
        if ($modules !== null) {
            return $modules;
        }

        $catalog = null;
        if (function_exists('config')) {
            $catalog = config('permissions');
        }
        if (!is_array($catalog) || empty($catalog['modules'])) {
            $path = defined('CONFIG_PATH')
                ? CONFIG_PATH . '/permissions.php'
                : __DIR__ . '/../../config/permissions.php';
            $catalog = is_file($path) ? require $path : ['modules' => []];
        }

        $modules = array_values($catalog['modules'] ?? []);

        return $modules;
    }

    /**
     * Check if the current user is authorized to manage permission overrides.
     *
     * Single authorization rule (Phase 2): the ability to manage permission
     * overrides is the `permission_overrides:manage` permission from the
     * catalog, resolved through the same hierarchy as every other check
     * (super_admin policy + role permissions + user overrides). It is seeded
     * to super_admin and hr_manager (migration 004). The previous hardcoded
     * role list could disagree with the permission checks the API performs.
     *
     * @return bool
     */
    public function isPermissionManager(): bool
    {
        if (!Auth::getInstance()->check()) {
            return false;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        return $this->hasPermission($userId, 'permission_overrides', 'manage');
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

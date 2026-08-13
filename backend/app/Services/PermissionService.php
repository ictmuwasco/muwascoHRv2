<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AuthorizationService;
use App\Helpers\RBAC;
use App\Models\UserPagePermission;
use App\Services\AuditService;

/**
 * PermissionService
 *
 * Centralized service for managing user permission overrides.
 * Provides the API layer between the Permission Management UI and the
 * hybrid authorization system (RBAC + user overrides).
 *
 * Authorization Hierarchy:
 *   1. Super Admin (handled in RBAC.php - always ALLOW)
 *   2. User-Specific Override (user_page_permissions table)
 *   3. Role Permission (role_permissions table)
 *   4. Default Deny
 *
 * Permission Override Model:
 *   'allow'  = explicitly allow this user for module/action
 *   'deny'   = explicitly deny this user for module/action
 *   (no record) = inherit role permission
 *
 * Place: backend/app/Services/PermissionService.php
 */
class PermissionService
{
    private static ?PermissionService $instance = null;
    private AuthorizationService $authService;
    private UserPagePermission $userPagePermissionModel;
    private RBAC $rbac;
    private AuditService $auditService;

    private function __construct()
    {
        $this->authService = AuthorizationService::getInstance();
        $this->userPagePermissionModel = new UserPagePermission();
        $this->rbac = RBAC::getInstance();
        $this->auditService = AuditService::getInstance();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get the permission catalog from config.
     *
     * @return array The permission catalog with modules and actions
     */
    public function getPermissionCatalog(): array
    {
        $catalog = config('permissions', []);
        return $catalog['modules'] ?? [];
    }

    /**
     * Get all available roles with labels.
     *
     * @return array Array of ['key' => role, 'label' => display_name]
     */
    public function getRoles(): array
    {
        $catalog = config('permissions', []);
        $roles = $catalog['roles'] ?? [];
        $labels = $catalog['role_labels'] ?? [];

        $result = [];
        foreach ($roles as $role) {
            $result[] = [
                'key'   => $role,
                'label' => $labels[$role] ?? ucwords(str_replace('_', ' ', $role)),
            ];
        }
        return $result;
    }

    /**
     * Get all users for the permission management UI.
     *
     * @param string $search Optional search term
     * @param int $page Page number
     * @param int $perPage Items per page
     * @return array Paginated user list
     */
    public function getUsers(string $search = '', int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));

        try {
            $conn = db()->getConnection();

            $where = '';
            $params = [];
            $types = '';

            if ($search !== '') {
                $where = " WHERE (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.surname LIKE ?)";
                $like = '%' . $search . '%';
                $types = 'ssss';
                $params = [$like, $like, $like, $like];
            }

            // Count total
            $total = (int) db()->fetchValue(
                "SELECT COUNT(*) FROM users u{$where}",
                $types,
                $params
            );

            $offset = ($page - 1) * $perPage;
            $sql = "SELECT u.id, u.email, u.first_name, u.last_name, u.surname, u.role, u.is_active,
                           u.designation, u.employee_id
                    FROM users u{$where}
                    ORDER BY u.first_name, u.last_name
                    LIMIT ? OFFSET ?";
            $types .= 'ii';
            $params = array_merge($params, [$perPage, $offset]);

            $rows = db()->fetchAll($sql, $types, $params);

            return [
                'data'     => $rows,
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / $perPage),
            ];
        } catch (\Throwable $e) {
            error_log('[PermissionService] getUsers failed: ' . $e->getMessage());
            return ['data' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'pages' => 0];
        }
    }

    /**
     * Get a user's role permissions (from role_permissions table).
     *
     * @param int $userId
     * @return array User info + role permissions
     */
    public function getUserRolePermissions(int $userId): array
    {
        try {
            $conn = db()->getConnection();

            // Get user info
            $stmt = $conn->prepare("SELECT id, email, first_name, last_name, surname, role, is_active, designation, employee_id FROM users WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();

            if (!$user) {
                return ['user' => null, 'role_permissions' => []];
            }

            // Get role permissions
            $rolePermissions = $this->rbac->getRolePermissions($user['role']);

            return [
                'user'             => $user,
                'role_permissions' => $rolePermissions,
            ];
        } catch (\Throwable $e) {
            error_log('[PermissionService] getUserRolePermissions failed: ' . $e->getMessage());
            return ['user' => null, 'role_permissions' => []];
        }
    }

    /**
     * Get all user-specific permission overrides for a user.
     *
     * @param int $userId
     * @return array Array of override records
     */
    public function getUserOverrides(int $userId): array
    {
        return $this->userPagePermissionModel->getByUserId($userId);
    }

    /**
     * Get all effective permissions for a user.
     * Combines role permissions with user overrides to show the final state.
     *
     * @param int $userId
     * @return array Array of effective permissions with source info
     */
    public function getEffectivePermissions(int $userId): array
    {
        return $this->authService->getAllEffectivePermissions($userId);
    }

    /**
     * Set a permission override for a user.
     *
     * @param int $userId Target user
     * @param string $module Module name
     * @param string $action Action name
     * @param string $permissionType 'allow' or 'deny'
     * @param int $grantedBy User who is granting
     * @param string|null $notes Administrative context
     * @return array Result with success status and message
     */
    public function setOverride(
        int $userId,
        string $module,
        string $action,
        string $permissionType,
        int $grantedBy,
        ?string $notes = null
    ): array {
        // Validate permission type
        if (!in_array($permissionType, ['allow', 'deny'], true)) {
            return ['success' => false, 'message' => 'Permission type must be "allow" or "deny"'];
        }

        // Validate module/action exist in catalog
        $catalog = $this->getPermissionCatalog();
        if (!isset($catalog[$module])) {
            return ['success' => false, 'message' => "Module '{$module}' not found in permission catalog"];
        }

        $validActions = array_column($catalog[$module]['actions'], 'key');
        if (!in_array($action, $validActions, true)) {
            return ['success' => false, 'message' => "Action '{$action}' not valid for module '{$module}'"];
        }

        // Check if user exists
        $user = db()->fetchOne('SELECT id, role FROM users WHERE id = ?', 'i', [$userId]);
        if (!$user) {
            return ['success' => false, 'message' => 'User not found'];
        }

        // Super Admin cannot be overridden (they always have full access)
        if ($user['role'] === 'super_admin') {
            return ['success' => false, 'message' => 'Super Admin permissions cannot be overridden'];
        }

        // Get previous permission for audit
        $previous = $this->userPagePermissionModel->getByUserAndModuleAction($userId, $module, $action);

        // Set the override
        $result = $this->userPagePermissionModel->setPermission(
            $userId,
            $module,
            $action,
            $permissionType,
            $grantedBy,
            $grantedBy,
            $notes
        );

        if (!$result) {
            return ['success' => false, 'message' => 'Failed to save permission override'];
        }

        // Clear authorization cache
        $this->authService->clearCache();

        // Log to audit trail
        $this->auditService->log(
            AuditService::MODULE_SETTINGS,
            AuditService::ACTION_PERMISSION_CHANGE,
            "Permission override set: {$module}:{$action} = {$permissionType} for user #{$userId}",
            [
                'target_type' => 'User',
                'target_id'   => $userId,
                'target_name' => $user['email'] ?? "User #{$userId}",
                'old_values'  => $previous ? [
                    'permission_type' => $previous['permission_type'],
                    'active'          => $previous['active'],
                ] : null,
                'new_values'  => [
                    'module'          => $module,
                    'action'          => $action,
                    'permission_type' => $permissionType,
                    'active'          => 1,
                ],
                'metadata'    => [
                    'notes' => $notes,
                ],
            ]
        );

        return ['success' => true, 'message' => 'Permission override saved successfully'];
    }

    /**
     * Remove (deactivate) a permission override for a user.
     * Inactive overrides are treated as if no override exists (inherit role).
     *
     * @param int $userId Target user
     * @param string $module Module name
     * @param string $action Action name
     * @param int $updatedBy User who is removing
     * @return array Result with success status and message
     */
    public function removeOverride(int $userId, string $module, string $action, int $updatedBy): array
    {
        // Get previous permission for audit
        $previous = $this->userPagePermissionModel->getByUserAndModuleAction($userId, $module, $action);

        if (!$previous) {
            return ['success' => false, 'message' => 'No active override found for this user/module/action'];
        }

        $result = $this->userPagePermissionModel->removePermission($userId, $module, $action);

        if (!$result) {
            return ['success' => false, 'message' => 'Failed to remove permission override'];
        }

        // Clear authorization cache
        $this->authService->clearCache();

        // Log to audit trail
        $user = db()->fetchOne('SELECT id, email FROM users WHERE id = ?', 'i', [$userId]);
        $this->auditService->log(
            AuditService::MODULE_SETTINGS,
            AuditService::ACTION_PERMISSION_CHANGE,
            "Permission override removed: {$module}:{$action} for user #{$userId}",
            [
                'target_type' => 'User',
                'target_id'   => $userId,
                'target_name' => $user['email'] ?? "User #{$userId}",
                'old_values'  => [
                    'permission_type' => $previous['permission_type'],
                    'active'          => $previous['active'],
                ],
                'new_values'  => [
                    'active' => 0,
                ],
            ]
        );

        return ['success' => true, 'message' => 'Permission override removed successfully'];
    }

    /**
     * Get all permission overrides with user info for the management UI.
     *
     * @param array $filters Optional filters
     * @return array Array of overrides with user details
     */
    public function getAllOverrides(array $filters = []): array
    {
        return $this->userPagePermissionModel->getAllWithUserInfo($filters);
    }

    /**
     * Get permission statistics for the dashboard.
     *
     * @return array Statistics
     */
    public function getStatistics(): array
    {
        return [
            'total_overrides' => $this->userPagePermissionModel->countActive(),
            'allow_count'     => $this->userPagePermissionModel->countByType('allow'),
            'deny_count'      => $this->userPagePermissionModel->countByType('deny'),
            'total_users'     => (int) db()->fetchValue('SELECT COUNT(*) FROM users'),
            'total_roles'     => count($this->getRoles()),
            'total_modules'   => count($this->getPermissionCatalog()),
        ];
    }

    /**
     * Check if the current user is authorized to manage permissions.
     *
     * @return bool
     */
    public function isPermissionManager(): bool
    {
        return $this->authService->isPermissionManager();
    }

    private function __clone(): void {}

    public function __wakeup()
    {
        throw new \RuntimeException('Cannot unserialize singleton');
    }
}
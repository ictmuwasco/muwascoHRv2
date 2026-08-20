<?php

declare(strict_types=1);

namespace App\Models;

/**
 * UserPagePermission Model
 *
 * Handles database operations for user-specific permission overrides.
 * This model manages the hybrid authorization system where users can have
 * explicit allow/deny permissions for specific module/action combinations
 * that override their role-based permissions.
 *
 * Authorization Hierarchy:
 *   1. Super Admin (handled in RBAC.php)
 *   2. User-Specific Override (this model / user_page_permissions table)
 *   3. Role Permission (role_permissions table)
 *   4. Default Deny
 *
 * Permission Model:
 *   'allow'  = explicitly allow this user for module/action
 *   'deny'   = explicitly deny this user for module/action
 *   (no record) = inherit role permission
 *
 * Place: backend/app/Models/UserPagePermission.php
 */
class UserPagePermission
{
    /**
     * Get all permission overrides for a specific user (active only).
     *
     * @param int $userId
     * @return array Array of permission overrides with module/action granularity
     */
    public function getByUserId(int $userId): array
    {
        try {
            $conn = \App\Helpers\Database::getInstance()->getConnection();

            $stmt = $conn->prepare("
                SELECT id, user_id, module, action, permission_type, granted_by,
                       updated_by, granted_at, updated_at, active, notes
                FROM user_page_permissions
                WHERE user_id = ? AND active = 1
                ORDER BY module ASC, action ASC
            ");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $permissions = $result->fetch_all(\MYSQLI_ASSOC);
            $stmt->close();

            return $permissions;
        } catch (\Exception $e) {
            error_log("Failed to load user permissions for user {$userId}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get a specific permission override for a user and module/action.
     *
     * @param int $userId
     * @param string $module
     * @param string $action
     * @return array|null Permission record or null if not found / inactive
     */
    public function getByUserAndModuleAction(int $userId, string $module, string $action): ?array
    {
        try {
            $conn = \App\Helpers\Database::getInstance()->getConnection();

            $stmt = $conn->prepare("
                SELECT id, user_id, module, action, permission_type, granted_by,
                       updated_by, granted_at, updated_at, active, notes
                FROM user_page_permissions
                WHERE user_id = ? AND module = ? AND action = ? AND active = 1
                LIMIT 1
            ");
            $stmt->bind_param("iss", $userId, $module, $action);
            $stmt->execute();
            $result = $stmt->get_result();
            $permission = $result->fetch_assoc();
            $stmt->close();

            return $permission ?: null;
        } catch (\Exception $e) {
            error_log("Failed to load permission for user {$userId}, module {$module}, action {$action}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create or update a permission override.
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE to prevent duplicate
     * (user_id, module, action) records (Phase 13 - unique constraint).
     *
     * @param int $userId
     * @param string $module
     * @param string $action
     * @param string $permissionType 'allow' or 'deny'
     * @param int $grantedBy
     * @param int $updatedBy
     * @param string|null $notes
     * @return bool Success status
     */
    public function setPermission(
        int $userId,
        string $module,
        string $action,
        string $permissionType,
        int $grantedBy,
        int $updatedBy,
        ?string $notes = null
    ): bool {
        try {
            $conn = \App\Helpers\Database::getInstance()->getConnection();

            $stmt = $conn->prepare("
                INSERT INTO user_page_permissions
                    (user_id, module, action, permission_type, granted_by, updated_by, notes, granted_at, updated_at, active)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 1)
                ON DUPLICATE KEY UPDATE
                    permission_type = VALUES(permission_type),
                    granted_by      = VALUES(granted_by),
                    updated_by      = VALUES(updated_by),
                    notes           = VALUES(notes),
                    active          = 1,
                    updated_at      = NOW()
            ");
            $stmt->bind_param("isssiis", $userId, $module, $action, $permissionType, $grantedBy, $updatedBy, $notes);
            $result = $stmt->execute();
            $stmt->close();

            return $result;
        } catch (\Exception $e) {
            error_log("Failed to set permission for user {$userId}, module {$module}, action {$action}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove a permission override (set to inactive).
     * Inactive overrides are treated as if no override exists (Phase 12).
     *
     * @param int $userId
     * @param string $module
     * @param string $action
     * @return bool Success status
     */
    public function removePermission(int $userId, string $module, string $action): bool
    {
        try {
            $conn = \App\Helpers\Database::getInstance()->getConnection();

            $stmt = $conn->prepare("
                UPDATE user_page_permissions
                SET active = 0, updated_at = NOW()
                WHERE user_id = ? AND module = ? AND action = ? AND active = 1
            ");
            $stmt->bind_param("iss", $userId, $module, $action);
            $result = $stmt->execute();
            $stmt->close();

            return $result;
        } catch (\Exception $e) {
            error_log("Failed to remove permission for user {$userId}, module {$module}, action {$action}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove all permission overrides for a user.
     *
     * @param int $userId
     * @return bool Success status
     */
    public function removeAllPermissions(int $userId): bool
    {
        try {
            $conn = \App\Helpers\Database::getInstance()->getConnection();

            $stmt = $conn->prepare("
                UPDATE user_page_permissions
                SET active = 0, updated_at = NOW()
                WHERE user_id = ? AND active = 1
            ");
            $stmt->bind_param("i", $userId);
            $result = $stmt->execute();
            $stmt->close();

            return $result;
        } catch (\Exception $e) {
            error_log("Failed to remove all permissions for user {$userId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all permission overrides with user and grantor information for audit purposes.
     *
     * @param array $filters Optional filters (user_id, module, action, permission_type)
     * @return array Array of permissions with user and grantor details
     */
    public function getAllWithUserInfo(array $filters = []): array
    {
        $conn = \App\Helpers\Database::getInstance()->getConnection();

        $query = "
            SELECT
                upp.id,
                upp.user_id,
                upp.module,
                upp.action,
                upp.permission_type,
                upp.granted_by,
                upp.updated_by,
                upp.granted_at,
                upp.updated_at,
                upp.active,
                upp.notes,
                u.email as user_email,
                u.first_name as user_first_name,
                u.last_name as user_last_name,
                u.role as user_role,
                e.employee_id,
                d.name as department,
                s.name as section,
                CONCAT(gu.first_name, ' ', gu.last_name) as granted_by_name,
                CONCAT(uu.first_name, ' ', uu.last_name) as updated_by_name
            FROM user_page_permissions upp
            JOIN users u ON upp.user_id = u.id
            LEFT JOIN employees e ON u.email = e.email
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN sections s ON e.section_id = s.id
            LEFT JOIN users gu ON upp.granted_by = gu.id
            LEFT JOIN users uu ON upp.updated_by = uu.id
            WHERE upp.active = 1
        ";

        $params = [];
        $types = '';

        if (isset($filters['user_id'])) {
            $query .= " AND upp.user_id = ?";
            $params[] = $filters['user_id'];
            $types .= 'i';
        }

        if (isset($filters['module'])) {
            $query .= " AND upp.module = ?";
            $params[] = $filters['module'];
            $types .= 's';
        }

        if (isset($filters['action'])) {
            $query .= " AND upp.action = ?";
            $params[] = $filters['action'];
            $types .= 's';
        }

        if (isset($filters['permission_type'])) {
            $query .= " AND upp.permission_type = ?";
            $params[] = $filters['permission_type'];
            $types .= 's';
        }

        $query .= " ORDER BY u.first_name, u.last_name, upp.module, upp.action";

        $stmt = $conn->prepare($query);

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $permissions = $result->fetch_all(\MYSQLI_ASSOC);
        $stmt->close();

        return $permissions;
    }

    /**
     * Get all available module names from the application.
     * Derived from the existing role_permissions table and application routes.
     *
     * @return array Array of module names
     */
    public function getAllModules(): array
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
            'permission_overrides',
            'financial_year',
            'holidays',
        ];
    }

    /**
     * Get module display names.
     *
     * @return array Array of module => display_name
     */
    public function getModuleNames(): array
    {
        return [
            'dashboard'            => 'Dashboard',
            'employees'            => 'Employees',
            'departments'          => 'Departments',
            'attendance'           => 'Attendance',
            'leave'                => 'Leave Management',
            'reports'              => 'Reports',
            'users'                => 'User Management',
            'admin'                => 'Admin',
            'audit'                => 'Audit Trail',
            'profile'              => 'Profile',
            'performance'          => 'Performance Appraisal',
            'consent'              => 'Consent',
            'permission_overrides' => 'Permission Overrides',
            'financial_year'       => 'Financial Year',
            'holidays'             => 'Holidays',
        ];
    }

    /**
     * Check if a user has an explicit permission override for a module/action.
     *
     * @param int $userId
     * @param string $module
     * @param string $action
     * @return string|null 'allow', 'deny', or null if no override exists (inherit)
     */
    public function getExplicitPermission(int $userId, string $module, string $action): ?string
    {
        $permission = $this->getByUserAndModuleAction($userId, $module, $action);

        if (!$permission) {
            return null;
        }

        return $permission['permission_type'];
    }

    /**
     * Count total active permission overrides.
     *
     * @return int Total count
     */
    public function countActive(): int
    {
        try {
            $conn = \App\Helpers\Database::getInstance()->getConnection();

            $result = $conn->query("
                SELECT COUNT(*) as count
                FROM user_page_permissions
                WHERE active = 1
            ");

            $row = $result->fetch_assoc();
            return (int)($row['count'] ?? 0);
        } catch (\Exception $e) {
            error_log("Failed to count active permissions: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Count overrides by type.
     *
     * @param string $type 'allow' or 'deny'
     * @return int Count
     */
    public function countByType(string $type): int
    {
        try {
            $conn = \App\Helpers\Database::getInstance()->getConnection();

            $stmt = $conn->prepare("
                SELECT COUNT(*) as count
                FROM user_page_permissions
                WHERE active = 1 AND permission_type = ?
            ");
            $stmt->bind_param("s", $type);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();

            return (int)($row['count'] ?? 0);
        } catch (\Exception $e) {
            error_log("Failed to count permissions by type {$type}: " . $e->getMessage());
            return 0;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

/**
 * UserPagePermission Model
 *
 * Handles database operations for user-specific page permission overrides.
 * This model manages the hybrid authorization system where users can have
 * explicit allow/deny permissions that override their role-based permissions.
 *
 * Place: backend/app/Models/UserPagePermission.php
 */
class UserPagePermission
{
    /**
     * Action used for page-level (whole module) overrides.
     */
    public const DEFAULT_ACTION = 'view';

    /**
     * Get all permission overrides for a specific user.
     *
     * @param int $userId
     * @return array Array of permission overrides
     */
    public function getByUserId(int $userId): array
    {
        try {
            $conn = \App\Helpers\Database::getInstance()->getConnection();

            $stmt = $conn->prepare("
                SELECT id, user_id, module, action, page_id, permission_type, granted_by,
                       updated_by, granted_at, updated_at, active, notes
                FROM user_page_permissions
                WHERE user_id = ? AND active = 1
                ORDER BY module ASC, action ASC
            ");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $permissions = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            return $permissions;
        } catch (\Exception $e) {
            error_log("Failed to load user permissions for user {$userId}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get a specific permission override for a user, module and action.
     *
     * @param int $userId
     * @param string $module
     * @param string $action
     * @return array|null Permission record or null if not found
     */
    public function getByUserAndModuleAction(int $userId, string $module, string $action): ?array
    {
        try {
            $conn = \App\Helpers\Database::getInstance()->getConnection();

            $stmt = $conn->prepare("
                SELECT id, user_id, module, action, page_id, permission_type, granted_by,
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
            error_log("Failed to load permission for user {$userId}, {$module}:{$action}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get a specific page-level (module, 'view') permission override for a user.
     *
     * @param int $userId
     * @param string $pageId
     * @return array|null Permission record or null if not found
     */
    public function getByUserAndPage(int $userId, string $pageId): ?array
    {
        return $this->getByUserAndModuleAction($userId, $pageId, self::DEFAULT_ACTION);
    }

    /**
     * Create or update a permission override.
     *
     * @param int $userId
     * @param string $module
     * @param string $action
     * @param string $permissionType 'allow' or 'deny'
     * @param int $grantedBy
     * @param int|null $updatedBy
     * @param string|null $notes
     * @return bool Success status
     */
    public function setPermission(
        int $userId,
        string $module,
        string $action,
        string $permissionType,
        int $grantedBy,
        ?int $updatedBy = null,
        ?string $notes = null
    ): bool {
        try {
            $conn = \App\Helpers\Database::getInstance()->getConnection();
            $updatedBy = $updatedBy ?? $grantedBy;

            // A previously removed override is reactivated rather than duplicated,
            // since (user_id, module, action) is unique regardless of `active`.
            $stmt = $conn->prepare("
                INSERT INTO user_page_permissions
                    (user_id, module, action, page_id, permission_type, granted_by, updated_by,
                     notes, active, granted_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    permission_type = VALUES(permission_type),
                    updated_by      = VALUES(updated_by),
                    notes           = VALUES(notes),
                    active          = 1,
                    updated_at      = NOW()
            ");
            $stmt->bind_param(
                "issssiis",
                $userId,
                $module,
                $action,
                $module,
                $permissionType,
                $grantedBy,
                $updatedBy,
                $notes
            );

            $result = $stmt->execute();
            $stmt->close();

            return $result;
        } catch (\Exception $e) {
            error_log("Failed to set permission for user {$userId}, {$module}:{$action}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove a permission override (set to inactive).
     *
     * @param int $userId
     * @param string $module
     * @param string $action
     * @return bool Success status
     */
    public function removePermission(int $userId, string $module, string $action = self::DEFAULT_ACTION): bool
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
            error_log("Failed to remove permission for user {$userId}, {$module}:{$action}: " . $e->getMessage());
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
    }

    /**
     * Get all permissions with user information for audit purposes.
     *
     * @param array $filters Optional filters
     * @return array Array of permissions with user details
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
                upp.page_id,
                upp.permission_type,
                upp.granted_by,
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
                CONCAT(g.first_name, ' ', g.last_name) as granted_by_name
            FROM user_page_permissions upp
            JOIN users u ON upp.user_id = u.id
            LEFT JOIN employees e ON u.email = e.email
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN sections s ON e.section_id = s.id
            JOIN users g ON upp.granted_by = g.id
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
        $permissions = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $permissions;
    }

    /**
     * Get all available page IDs from the system.
     *
     * @return array Array of page IDs
     */
    public function getAllPageIds(): array
    {
        return [
            'dashboard',
            'employees',
            'attendance',
            'leave',
            'payroll',
            'complaints',
            'reports',
            'appraisal',
            'users',
            'roles',
            'settings',
            'audit',
            'notifications'
        ];
    }

    /**
     * Get page display names.
     *
     * @return array Array of page_id => display_name
     */
    public function getPageNames(): array
    {
        return [
            'dashboard' => 'Dashboard',
            'employees' => 'Employees',
            'attendance' => 'Attendance',
            'leave' => 'Leave Management',
            'payroll' => 'Payroll',
            'complaints' => 'Complaints',
            'reports' => 'Reports',
            'appraisal' => 'Performance Appraisal',
            'users' => 'User Management',
            'roles' => 'Roles & Permissions',
            'settings' => 'Settings',
            'audit' => 'Audit Trail',
            'notifications' => 'Notifications'
        ];
    }

    /**
     * Check if a user has an explicit permission override for a page.
     *
     * @param int $userId
     * @param string $pageId
     * @return string|null 'allow', 'deny', or null if no override
     */
    public function getExplicitPermission(int $userId, string $pageId): ?string
    {
        $permission = $this->getByUserAndPage($userId, $pageId);
        
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
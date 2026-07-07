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
                SELECT id, user_id, page_id, permission_type, granted_by, 
                       granted_at, updated_at, active, notes
                FROM user_page_permissions
                WHERE user_id = ? AND active = 1
                ORDER BY page_id ASC
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
     * Get a specific permission override for a user and page.
     *
     * @param int $userId
     * @param string $pageId
     * @return array|null Permission record or null if not found
     */
    public function getByUserAndPage(int $userId, string $pageId): ?array
    {
        try {
            $conn = \App\Helpers\Database::getInstance()->getConnection();

            $stmt = $conn->prepare("
                SELECT id, user_id, page_id, permission_type, granted_by, 
                       granted_at, updated_at, active, notes
                FROM user_page_permissions
                WHERE user_id = ? AND page_id = ? AND active = 1
                LIMIT 1
            ");
            $stmt->bind_param("is", $userId, $pageId);
            $stmt->execute();
            $result = $stmt->get_result();
            $permission = $result->fetch_assoc();
            $stmt->close();

            return $permission ?: null;
        } catch (\Exception $e) {
            error_log("Failed to load permission for user {$userId}, page {$pageId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create or update a permission override.
     *
     * @param int $userId
     * @param string $pageId
     * @param string $permissionType 'allow' or 'deny'
     * @param int $grantedBy
     * @param string|null $notes
     * @return bool Success status
     */
    public function setPermission(int $userId, string $pageId, string $permissionType, int $grantedBy, ?string $notes = null): bool
    {
        try {
            $conn = \App\Helpers\Database::getInstance()->getConnection();

            // Check if permission already exists
            $existing = $this->getByUserAndPage($userId, $pageId);

            if ($existing) {
                // Update existing permission
                $stmt = $conn->prepare("
                    UPDATE user_page_permissions
                    SET permission_type = ?, granted_by = ?, updated_at = NOW(), notes = ?
                    WHERE user_id = ? AND page_id = ? AND active = 1
                ");
                $stmt->bind_param("sisis", $permissionType, $grantedBy, $notes, $userId, $pageId);
            } else {
                // Insert new permission
                $stmt = $conn->prepare("
                    INSERT INTO user_page_permissions 
                    (user_id, page_id, permission_type, granted_by, notes, granted_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->bind_param("issis", $userId, $pageId, $permissionType, $grantedBy, $notes);
            }

            $result = $stmt->execute();
            $stmt->close();

            return $result;
        } catch (\Exception $e) {
            error_log("Failed to set permission for user {$userId}, page {$pageId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove a permission override (set to inactive).
     *
     * @param int $userId
     * @param string $pageId
     * @return bool Success status
     */
    public function removePermission(int $userId, string $pageId): bool
    {
        try {
            $conn = \App\Helpers\Database::getInstance()->getConnection();

            $stmt = $conn->prepare("
                UPDATE user_page_permissions
                SET active = 0, updated_at = NOW()
                WHERE user_id = ? AND page_id = ? AND active = 1
            ");
            $stmt->bind_param("is", $userId, $pageId);
            $result = $stmt->execute();
            $stmt->close();

            return $result;
        } catch (\Exception $e) {
            error_log("Failed to remove permission for user {$userId}, page {$pageId}: " . $e->getMessage());
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
                e.department,
                e.section,
                CONCAT(g.first_name, ' ', g.last_name) as granted_by_name
            FROM user_page_permissions upp
            JOIN users u ON upp.user_id = u.id
            LEFT JOIN employees e ON u.email = e.email
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

        if (isset($filters['page_id'])) {
            $query .= " AND upp.page_id = ?";
            $params[] = $filters['page_id'];
            $types .= 's';
        }

        if (isset($filters['permission_type'])) {
            $query .= " AND upp.permission_type = ?";
            $params[] = $filters['permission_type'];
            $types .= 's';
        }

        $query .= " ORDER BY u.first_name, u.last_name, upp.page_id";

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
<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Helpers\Auth;
use App\Helpers\AuthorizationService;
use App\Repositories\UserRepository;

/**
 * EmployeePolicy — object-level authorization for employee resources.
 *
 * Determines WHO can do WHAT with a specific employee record. This is the
 * authoritative server-side authorization — the frontend may hide/show UI
 * elements, but this policy is the security enforcement.
 *
 * Authorization rules:
 *   - Super Admin: full access to all employees
 *   - HR Manager: full access to all employees
 *   - User themselves: can view their own profile
 *   - Dept Head: can view employees in their department
 *   - Others: denied
 */
final class EmployeePolicy
{
    private static ?UserRepository $userRepository = null;
    /**
     * Get the UserRepository instance (lazy initialization).
     */
    private static function getUserRepository(): UserRepository
    {
        if (self::$userRepository === null) {
            self::$userRepository = new UserRepository();
        }
        return self::$userRepository;
    }

    /**
     * Can the given user view this employee's profile?
     */
    public static function canView(int $userId, array $employee): bool
    {
        if ($userId <= 0 || empty($employee)) return false;

        // Super admin and HR manager can view all
        if (self::isHrOrAdmin($userId)) return true;

        // User can view their own profile
        if (self::isSelf($userId, $employee)) return true;

        // Dept head can view employees in their department
        if (self::isSameDepartmentHead($userId, $employee)) return true;

        return false;
    }

    /**
     * Can the given user edit this employee's profile?
     */
    public static function canEdit(int $userId, array $employee): bool
    {
        if ($userId <= 0 || empty($employee)) return false;

        // Only HR and admin can edit other employees
        if (self::isHrOrAdmin($userId)) return true;

        // Users can edit their own limited profile (via /profile endpoint, not /employees/{id})
        // This policy is for the /employees/{id} endpoint which is HR-only for edits
        return false;
    }

    /**
     * Can the given user view sensitive information (salary, national_id, etc.)?
     */
    public static function canViewSensitive(int $userId, array $employee): bool
    {
        if ($userId <= 0 || empty($employee)) return false;
        return self::isHrOrAdmin($userId);
    }

    /**
     * Can the given user delete this employee?
     */
    public static function canDelete(int $userId, array $employee): bool
    {
        if ($userId <= 0 || empty($employee)) return false;
        // Only super_admin can delete
        return AuthorizationService::getInstance()->hasPermission($userId, 'employees', 'delete')
            && self::isSuperAdmin($userId);
    }

    private static function isHrOrAdmin(int $userId): bool
    {
        return AuthorizationService::getInstance()->hasPermission($userId, 'employees', 'view')
            && (
                AuthorizationService::getInstance()->hasPermission($userId, 'employees', 'edit')
                || self::isSuperAdmin($userId)
            );
    }

    private static function isSuperAdmin(int $userId): bool
    {
        try {
            $user = self::getUserRepository()->findById($userId);
            return $user && ($user['role'] === 'super_admin' || $user['role'] === 'admin');
        } catch (\Throwable $e) { return false; }
    }

    private static function isSelf(int $userId, array $employee): bool
    {
        // Check if the employee record belongs to this user
        // Relationship: users.employee_id → employees.employee_id
        try {
            $user = self::getUserRepository()->findById($userId);
            if (!$user || empty($user['employee_id'])) return false;
            return isset($employee['employee_id']) && $user['employee_id'] === $employee['employee_id'];
        } catch (\Throwable $e) { return false; }
    }

    private static function isSameDepartmentHead(int $userId, array $employee): bool
    {
        try {
            $user = self::getUserRepository()->findById($userId);
            if (!$user || $user['role'] !== 'dept_head') return false;
            // Check if employee is in the same department
            // Note: users table doesn't have department_id, so we check employee record
            $userEmployee = !empty($user['employee_id']) ? self::getUserRepository()->findEmployeeByEmployeeId($user['employee_id']) : null;
            $userDeptId = $userEmployee['department_id'] ?? null;
            return isset($employee['department_id']) && $userDeptId !== null
                && (int) $employee['department_id'] === (int) $userDeptId;
        } catch (\Throwable $e) { return false; }
    }
}

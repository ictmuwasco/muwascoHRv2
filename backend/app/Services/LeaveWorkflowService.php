<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;

/**
 * LeaveWorkflowService
 *
 * Handles approval workflow logic for leave applications.
 * Extracts existing approval hierarchy rules from legacy code.
 */
class LeaveWorkflowService
{
    private \mysqli $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Determine initial approval status based on applicant and target employee.
     */
    public function determineInitialWorkflowStatus(int $targetEmployeeId, int $applicantUserId): string
    {
        $targetEmployee = $this->getTargetEmployee($targetEmployeeId);
        if (!$targetEmployee) {
            return 'pending_hr';
        }

        $isSelfApplication = ($targetEmployeeId === $this->getEmployeeIdFromUserId($applicantUserId));
        $managers = $this->getManagers($targetEmployeeId);

        if ($targetEmployee['user_role'] === 'managing_director' && $isSelfApplication) {
            return 'pending_bod_chair';
        }
        if ($targetEmployee['user_role'] === 'dept_head' && $isSelfApplication) {
            return 'pending_managing_director';
        }
        if ($targetEmployee['user_role'] === 'section_head' && $isSelfApplication) {
            return 'pending_dept_head';
        }
        if ($targetEmployee['user_role'] === 'sub_section_head' && $isSelfApplication) {
            return 'pending_section_head';
        }

        if ($targetEmployee['user_role'] === 'officer' && $managers['subsection_id'] && $managers['subsection_head_emp_id']) {
            return 'pending_subsection_head';
        }
        if ($targetEmployee['user_role'] === 'officer' && !$managers['subsection_id'] && $managers['section_id'] && $managers['section_head_emp_id']) {
            return 'pending_section_head';
        }
        if ($targetEmployee['user_role'] === 'sub_section_head' && !$isSelfApplication) {
            return 'pending_section_head';
        }
        if ($targetEmployee['user_role'] === 'section_head' && !$isSelfApplication) {
            return 'pending_dept_head';
        }
        if (($targetEmployee['user_role'] === 'dept_head' || $targetEmployee['user_role'] === 'manager') && !$isSelfApplication) {
            return 'pending_managing_director';
        }
        if ($targetEmployee['user_role'] === 'hr_manager') {
            return 'pending_managing_director';
        }
        if ($targetEmployee['user_role'] === 'managing_director' && !$isSelfApplication) {
            return 'pending_bod_chair';
        }

        if ($managers['subsection_id'] && $managers['subsection_head_emp_id']) {
            return 'pending_subsection_head';
        }
        if ($managers['section_id'] && $managers['section_head_emp_id']) {
            return 'pending_section_head';
        }
        if ($managers['department_id'] && $managers['dept_head_emp_id']) {
            return 'pending_dept_head';
        }

        return 'pending_hr';
    }

    /**
     * Get next approver user IDs for notifications.
     */
    public function getApproverUserIds(string $status, array $managers): array
    {
        $approverUserIds = [];

        switch ($status) {
            case 'pending_subsection_head':
                if ($managers['subsection_head_emp_id']) {
                    $approverUserIds = $this->getUserIdsByEmployeeId($managers['subsection_head_emp_id']);
                }
                break;
            case 'pending_section_head':
                if ($managers['section_head_emp_id']) {
                    $approverUserIds = $this->getUserIdsByEmployeeId($managers['section_head_emp_id']);
                }
                break;
            case 'pending_dept_head':
                if ($managers['dept_head_emp_id']) {
                    $approverUserIds = $this->getUserIdsByEmployeeId($managers['dept_head_emp_id']);
                }
                break;
            case 'pending_managing_director':
                $approverUserIds = $this->getUserIdsByRole('managing_director');
                break;
            case 'pending_bod_chair':
                $approverUserIds = $this->getUserIdsByRole('bod_chair');
                break;
            case 'pending_hr':
                $approverUserIds = $this->getUserIdsByRole('hr_manager');
                break;
        }

        return $approverUserIds;
    }

    /**
     * Get eligible delegate candidates for an applicant.
     *
     * A delegate is anyone in the logged-in user's organisational scope who can
     * cover their duties while on leave:
     *   - officer / sub_section_head: everyone in the same subsection
     *   - section_head:               everyone in the same section
     *   - dept_head:                  everyone in the same department
     *   - hr_manager:                 everyone in HR / Admin departments
     *   - managing_director:          all department heads
     *   - super_admin:                all active employees
     * The applicant (self) is excluded.
     */
    public function getEligibleDelegates(int $applicantUserId): array
    {
        $employeeId = $this->getEmployeeIdFromUserId($applicantUserId);
        if (!$employeeId) {
            return [];
        }

        $targetEmployee = $this->getTargetEmployee($employeeId);
        if (!$targetEmployee) {
            return [];
        }

        $role = $targetEmployee['user_role'] ?? 'employee';

        $delegates = [];
        switch ($role) {
            case 'managing_director':
                $delegates = $this->getEmployeesByRole('dept_head');
                break;
            case 'super_admin':
                $delegates = $this->getAllEmployeesExcept($employeeId);
                break;
            case 'dept_head':
                $delegates = $this->getEmployeesInDepartmentExcept((int) $targetEmployee['department_id'], $employeeId);
                break;
            case 'section_head':
                $delegates = $this->getEmployeesInSectionExcept((int) $targetEmployee['section_id'], $employeeId);
                break;
            case 'sub_section_head':
                // Same subsection; fall back to same section when no subsection is assigned.
                $subId = (int) ($targetEmployee['subsection_id'] ?? 0);
                if ($subId > 0) {
                    $delegates = $this->getEmployeesInSubsectionExcept($subId, $employeeId);
                } else {
                    $delegates = $this->getEmployeesInSectionExcept((int) ($targetEmployee['section_id'] ?? 0), $employeeId);
                }
                break;
            case 'hr_manager':
                // HR manager can select anyone in HR or Admin departments.
                // Always include the HR manager's own department since it is
                // the HR department, even if its name does not contain
                // "hr"/"human resource"/"admin".
                $hrDeptId = $this->getDepartmentIdByNamePattern("hr", "human resource");
                $adminDeptId = $this->getDepartmentIdByNamePattern("admin");
                $ownDeptId = (int) ($targetEmployee['department_id'] ?? 0);
                $delegates = $this->getEmployeesInDepartmentsExcept(array_filter([$ownDeptId, $hrDeptId, $adminDeptId]), $employeeId);
                break;
            default:
                $delegates = $this->getEmployeesInSubsectionExcept((int) ($targetEmployee['subsection_id'] ?? 0), $employeeId);
        }

        return $delegates;
    }

    /**
     * Get all employees except the given one (fallback for delegate selection).
     */
    private function getAllEmployeesExcept(int $excludeEmployeeId): array
    {
        $stmt = $this->db->prepare("
            SELECT e.id, e.first_name, e.last_name, e.employee_id, u.role
            FROM employees e
            JOIN users u ON u.employee_id = e.employee_id
            WHERE e.id != ?
            ORDER BY e.first_name, e.last_name
        ");
        $stmt->bind_param('i', $excludeEmployeeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $employees = [];
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
        return $employees;
    }

    private function getEmployeesByRole(string $role): array
    {
        $stmt = $this->db->prepare("
            SELECT e.id, e.first_name, e.last_name, e.employee_id, u.role
            FROM employees e
            JOIN users u ON u.employee_id = e.employee_id
            WHERE u.role = ?
            ORDER BY e.first_name, e.last_name
        ");
        $stmt->bind_param('s', $role);
        $stmt->execute();
        $result = $stmt->get_result();
        $employees = [];
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
        return $employees;
    }

    private function getEmployeesByRoleInDepartment(string $role, int $departmentId): array
    {
        $stmt = $this->db->prepare("
            SELECT e.id, e.first_name, e.last_name, e.employee_id, u.role
            FROM employees e
            JOIN users u ON u.employee_id = e.employee_id
            WHERE u.role = ? AND e.department_id = ?
            ORDER BY e.first_name, e.last_name
        ");
        $stmt->bind_param('si', $role, $departmentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $employees = [];
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
        return $employees;
    }

    /**
     * Get employees by role in any of the given departments.
     */
    private function getEmployeesByRoleInDepartments(string $role, array $departmentIds): array
    {
        $departmentIds = array_values(array_filter(array_map('intval', $departmentIds)));
        if ($departmentIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($departmentIds), '?'));
        $types = 's' . str_repeat('i', count($departmentIds));
        $stmt = $this->db->prepare("
            SELECT e.id, e.first_name, e.last_name, e.employee_id, u.role
            FROM employees e
            JOIN users u ON u.employee_id = e.employee_id
            WHERE u.role = ? AND e.department_id IN ({$placeholders})
            ORDER BY e.first_name, e.last_name
        ");
        $bindParams = array_merge([$types, $role], $departmentIds);
        $stmt->bind_param(...$bindParams);
        $stmt->execute();
        $result = $stmt->get_result();
        $employees = [];
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
        return $employees;
    }

    /**
     * Get a department ID by fuzzy name patterns.
     */
    private function getDepartmentIdByNamePattern(string ...$patterns): int
    {
        if ($patterns === []) {
            return 0;
        }
        $likeClauses = [];
        $params = [];
        foreach ($patterns as $pattern) {
            $likeClauses[] = "name LIKE ?";
            $params[] = '%' . $pattern . '%';
        }
        $stmt = $this->db->prepare("
            SELECT id FROM departments
            WHERE " . implode(' OR ', $likeClauses) . "
            LIMIT 1
        ");
        $types = str_repeat('s', count($params));
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ? (int) $result['id'] : 0;
    }

    private function getEmployeesByRoleInSection(string $role, int $sectionId): array
    {
        $stmt = $this->db->prepare("
            SELECT e.id, e.first_name, e.last_name, e.employee_id, u.role
            FROM employees e
            JOIN users u ON u.employee_id = e.employee_id
            WHERE u.role = ? AND e.section_id = ?
            ORDER BY e.first_name, e.last_name
        ");
        $stmt->bind_param('si', $role, $sectionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $employees = [];
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
        return $employees;
    }

    private function getEmployeesByRoleInSubsection(string $role, int $subsectionId): array
    {
        $stmt = $this->db->prepare("
            SELECT e.id, e.first_name, e.last_name, e.employee_id, u.role
            FROM employees e
            JOIN users u ON u.employee_id = e.employee_id
            WHERE u.role = ? AND e.subsection_id = ?
            ORDER BY e.first_name, e.last_name
        ");
        $stmt->bind_param('si', $role, $subsectionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $employees = [];
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
        return $employees;
    }

    /**
     * Get all active employees in a subsection, excluding the given employee.
     * Employees without a user account are treated as officers so they can be delegates.
     */
    private function getEmployeesInSubsectionExcept(int $subsectionId, int $excludeEmployeeId): array
    {
        if ($subsectionId <= 0) {
            return [];
        }
        $stmt = $this->db->prepare("
            SELECT e.id, e.first_name, e.last_name, e.employee_id, COALESCE(u.role, 'officer') AS role
            FROM employees e
            LEFT JOIN users u ON u.employee_id = e.employee_id
            WHERE e.subsection_id = ?
            AND e.employee_status = 'active'
            AND e.id != ?
            ORDER BY e.first_name, e.last_name
        ");
        $stmt->bind_param('ii', $subsectionId, $excludeEmployeeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $employees = [];
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
        return $employees;
    }

    /**
     * Get all active employees in a section, excluding the given employee.
     */
    private function getEmployeesInSectionExcept(int $sectionId, int $excludeEmployeeId): array
    {
        if ($sectionId <= 0) {
            return [];
        }
        $stmt = $this->db->prepare("
            SELECT e.id, e.first_name, e.last_name, e.employee_id, COALESCE(u.role, 'officer') AS role
            FROM employees e
            LEFT JOIN users u ON u.employee_id = e.employee_id
            WHERE e.section_id = ?
            AND e.employee_status = 'active'
            AND e.id != ?
            ORDER BY e.first_name, e.last_name
        ");
        $stmt->bind_param('ii', $sectionId, $excludeEmployeeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $employees = [];
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
        return $employees;
    }

    /**
     * Get all active employees in a department, excluding the given employee.
     */
    private function getEmployeesInDepartmentExcept(int $departmentId, int $excludeEmployeeId): array
    {
        if ($departmentId <= 0) {
            return [];
        }
        $stmt = $this->db->prepare("
            SELECT e.id, e.first_name, e.last_name, e.employee_id, COALESCE(u.role, 'officer') AS role
            FROM employees e
            LEFT JOIN users u ON u.employee_id = e.employee_id
            WHERE e.department_id = ?
            AND e.employee_status = 'active'
            AND e.id != ?
            ORDER BY e.first_name, e.last_name
        ");
        $stmt->bind_param('ii', $departmentId, $excludeEmployeeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $employees = [];
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
        return $employees;
    }

    /**
     * Get all active employees in any of the given departments, excluding the given employee.
     */
    private function getEmployeesInDepartmentsExcept(array $departmentIds, int $excludeEmployeeId): array
    {
        $departmentIds = array_values(array_filter(array_map('intval', $departmentIds)));
        if ($departmentIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($departmentIds), '?'));
        $types = str_repeat('i', count($departmentIds)) . 'i';
        $stmt = $this->db->prepare("
            SELECT e.id, e.first_name, e.last_name, e.employee_id, COALESCE(u.role, 'officer') AS role
            FROM employees e
            LEFT JOIN users u ON u.employee_id = e.employee_id
            WHERE e.department_id IN ({$placeholders})
            AND e.employee_status = 'active'
            AND e.id != ?
            ORDER BY e.first_name, e.last_name
        ");
        $bindParams = array_merge([$types], $departmentIds, [$excludeEmployeeId]);
        $stmt->bind_param(...$bindParams);
        $stmt->execute();
        $result = $stmt->get_result();
        $employees = [];
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
        return $employees;
    }

    private function getTargetEmployee(int $employeeId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT e.*, u.role as user_role
            FROM employees e
            LEFT JOIN users u ON u.employee_id = e.employee_id
            WHERE e.id = ?
        ");
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ?: null;
    }

    private function getUserRole(int $userId): string
    {
        $stmt = $this->db->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result['role'] ?? '';
    }

    private function getEmployeeIdFromUserId(int $userId): ?int
    {
        $stmt = $this->db->prepare("
            SELECT e.id FROM employees e
            JOIN users u ON u.employee_id = e.employee_id
            WHERE u.id = ?
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ? (int) $result['id'] : null;
    }

    public function getManagers(int $employeeId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                e.subsection_id,
                e.section_id,
                e.department_id,
                (SELECT e2.id FROM employees e2
                 JOIN users u2 ON u2.employee_id = e2.employee_id
                 WHERE e2.subsection_id = e.subsection_id AND u2.role = 'sub_section_head'
                 LIMIT 1) as subsection_head_emp_id,
                (SELECT e3.id FROM employees e3
                 JOIN users u3 ON u3.employee_id = e3.employee_id
                 WHERE e3.section_id = e.section_id AND u3.role = 'section_head'
                 LIMIT 1) as section_head_emp_id,
                (SELECT e4.id FROM employees e4
                 JOIN users u4 ON u4.employee_id = e4.employee_id
                 WHERE e4.department_id = e.department_id AND u4.role = 'dept_head'
                 LIMIT 1) as dept_head_emp_id
            FROM employees e
            WHERE e.id = ?
        ");
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ?: [];
    }

    private function getUserIdsByEmployeeId(int $employeeId): array
    {
        $stmt = $this->db->prepare("
            SELECT u.id FROM users u
            WHERE u.employee_id = (SELECT employee_id FROM employees WHERE id = ?)
        ");
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $userIds = [];
        while ($row = $result->fetch_assoc()) {
            $userIds[] = (int) $row['id'];
        }
        return $userIds;
    }

    private function getUserIdsByRole(string $role): array
    {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE role = ?");
        $stmt->bind_param('s', $role);
        $stmt->execute();
        $result = $stmt->get_result();
        $userIds = [];
        while ($row = $result->fetch_assoc()) {
            $userIds[] = (int) $row['id'];
        }
        return $userIds;
    }
}
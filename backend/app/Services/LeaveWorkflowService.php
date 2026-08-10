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
            case 'dept_head':
                $delegates = $this->getEmployeesByRoleInDepartment('section_head', (int) $targetEmployee['department_id']);
                break;
            case 'section_head':
                $delegates = $this->getEmployeesByRoleInSection('sub_section_head', (int) $targetEmployee['section_id']);
                break;
            case 'sub_section_head':
                $delegates = $this->getEmployeesByRoleInSubsection('employee', (int) $targetEmployee['subsection_id']);
                break;
            default:
                $delegates = $this->getEmployeesByRoleInSubsection('employee', (int) ($targetEmployee['subsection_id'] ?? 0));
        }

        // Fallback: if no role-specific delegates found, return all employees
        // except the current user so the delegate dropdown is never empty.
        if (empty($delegates)) {
            $delegates = $this->getAllEmployeesExcept($employeeId);
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
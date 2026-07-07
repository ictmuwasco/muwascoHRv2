<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\Department;

/**
 * Employee Service - Business logic for employee management.
 * 
 * Handles employee CRUD operations, validation, user account creation,
 * payroll initialization, and audit logging.
 */
class EmployeeService
{
    private static ?EmployeeService $instance = null;
    private AuditService $audit;

    private function __construct()
    {
        $this->audit = AuditService::getInstance();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Create a new employee with user account and payroll entry.
     */
    public function create(array $data): array
    {
        $db = \db();

        // Validate leadership uniqueness
        $this->validateLeadershipUniqueness(
            $data['employee_type'],
            $data['department_id'] ?? null,
            $data['section_id'] ?? null,
            $data['subsection_id'] ?? null
        );

        // Prepare next of kin
        $nextOfKin = Employee::prepareNextOfKin(
            $data['next_of_kin_name'] ?? [],
            $data['next_of_kin_relationship'] ?? [],
            $data['next_of_kin_contact'] ?? []
        );

        $db->beginTransaction();

        try {
            // 1. Insert employee
            $employeeId = $db->insert('employees', [
                'employee_id' => $data['employee_id'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'surname' => $data['surname'] ?? '',
                'gender' => $data['gender'] ?? '',
                'national_id' => $data['national_id'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'date_of_birth' => $data['date_of_birth'],
                'address' => $data['address'] ?? '',
                'designation' => $data['designation'],
                'department_id' => !empty($data['department_id']) ? (int) $data['department_id'] : null,
                'section_id' => !empty($data['section_id']) ? (int) $data['section_id'] : null,
                'subsection_id' => !empty($data['subsection_id']) ? (int) $data['subsection_id'] : null,
                'office_id' => !empty($data['office_id']) ? (int) $data['office_id'] : null,
                'employee_type' => $data['employee_type'],
                'employment_type' => $data['employment_type'] ?? 'permanent',
                'employee_status' => 'active',
                'hire_date' => $data['hire_date'],
                'scale_id' => $data['job_group'] ?? null,
                'next_of_kin' => $nextOfKin,
                'profile_token' => hash('sha256', random_bytes(32) . time()),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            // 2. Create payroll entry
            $db->insert('payroll', [
                'emp_id' => $employeeId,
                'employment_type' => $data['employment_type'] ?? 'permanent',
                'status' => 'active',
                'job_group' => $data['job_group'] ?? null,
                'total_allowances' => 0.00,
                'total_deductions' => 0.00,
                'salary' => null,
                'Gross_pay' => null,
                'net_pay' => null,
            ]);

            // 3. Create user account
            $userRole = Employee::getUserRoleFromType($data['employee_type']);
            $hashedPassword = password_hash($data['employee_id'], PASSWORD_DEFAULT);

            $db->insert('users', [
                'email' => $data['email'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'gender' => $data['gender'] ?? '',
                'password' => $hashedPassword,
                'role' => $userRole,
                'phone' => $data['phone'],
                'address' => $data['address'] ?? '',
                'employee_id' => $data['employee_id'],
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $db->commit();

            // Audit log
            $this->audit->logCreate('employees', $employeeId, $data, "Created new employee: {$data['first_name']} {$data['last_name']}");

            return ['success' => true, 'employee_id' => $employeeId, 'message' => 'Employee created successfully. Default password is the Employee ID.'];

        } catch (\Exception $e) {
            $db->rollback();
            \logger()->error('Employee creation failed', ['error' => $e->getMessage(), 'data' => $data]);
            throw $e;
        }
    }

    /**
     * Update an existing employee.
     */
    public function update(int $id, array $data): array
    {
        $db = \db();

        // Get old employee data
        $oldEmployee = Employee::find($id);
        if (!$oldEmployee) {
            throw new \RuntimeException('Employee not found');
        }

        // Validate leadership uniqueness (excluding self)
        $this->validateLeadershipUniqueness(
            $data['employee_type'],
            $data['department_id'] ?? null,
            $data['section_id'] ?? null,
            $data['subsection_id'] ?? null,
            $id
        );

        // Prepare next of kin
        $nextOfKin = Employee::prepareNextOfKin(
            $data['next_of_kin_name'] ?? [],
            $data['next_of_kin_relationship'] ?? [],
            $data['next_of_kin_contact'] ?? []
        );

        $db->beginTransaction();

        try {
            // 1. Update employee
            $db->update('employees', [
                'employee_id' => $data['employee_id'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'surname' => $data['surname'] ?? '',
                'gender' => $data['gender'] ?? '',
                'national_id' => $data['national_id'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'date_of_birth' => $data['date_of_birth'],
                'address' => $data['address'] ?? '',
                'designation' => $data['designation'],
                'department_id' => !empty($data['department_id']) ? (int) $data['department_id'] : null,
                'section_id' => !empty($data['section_id']) ? (int) $data['section_id'] : null,
                'subsection_id' => !empty($data['subsection_id']) ? (int) $data['subsection_id'] : null,
                'office_id' => !empty($data['office_id']) ? (int) $data['office_id'] : null,
                'employee_type' => $data['employee_type'],
                'employment_type' => $data['employment_type'],
                'employee_status' => $data['employee_status'] ?? 'active',
                'hire_date' => $data['hire_date'],
                'scale_id' => $data['job_group'] ?? null,
                'next_of_kin' => $nextOfKin,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', 'i', [$id]);

            // 2. Update payroll
            $payrollStatus = ($data['employee_status'] ?? 'active') === 'active' ? 'active' : 'inactive';
            $db->update('payroll', [
                'job_group' => $data['job_group'] ?? null,
                'employment_type' => $data['employment_type'],
                'status' => $payrollStatus,
            ], 'emp_id = ?', 'i', [$id]);

            // 3. Update user account
            $userRole = Employee::getUserRoleFromType($data['employee_type']);
            $db->update('users', [
                'email' => $data['email'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'gender' => $data['gender'] ?? '',
                'role' => $userRole,
                'phone' => $data['phone'],
                'address' => $data['address'] ?? '',
                'employee_id' => $data['employee_id'],
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'employee_id = ?', 's', [$oldEmployee['employee_id']]);

            $db->commit();

            // Audit log
            $this->audit->logUpdate('employees', $id, $oldEmployee, $data, "Updated employee: {$data['first_name']} {$data['last_name']}");

            return ['success' => true, 'message' => 'Employee updated successfully.'];

        } catch (\Exception $e) {
            $db->rollback();
            \logger()->error('Employee update failed', ['error' => $e->getMessage(), 'id' => $id]);
            throw $e;
        }
    }

    /**
     * Delete an employee and associated records.
     */
    public function delete(int $id): array
    {
        $db = \db();

        $employee = Employee::find($id);
        if (!$employee) {
            throw new \RuntimeException('Employee not found');
        }

        $db->beginTransaction();

        try {
            // Delete payroll entry
            $db->delete('payroll', 'emp_id = ?', 'i', [$id]);

            // Delete user account
            $db->delete('users', 'employee_id = ?', 's', [$employee['employee_id']]);

            // Delete employee
            $db->delete('employees', 'id = ?', 'i', [$id]);

            $db->commit();

            // Audit log
            $this->audit->logDelete('employees', $id, $employee, "Deleted employee: {$employee['first_name']} {$employee['last_name']}");

            return ['success' => true, 'message' => 'Employee deleted successfully.'];

        } catch (\Exception $e) {
            $db->rollback();
            \logger()->error('Employee deletion failed', ['error' => $e->getMessage(), 'id' => $id]);
            throw $e;
        }
    }

    /**
     * Get employees with search, filters, and pagination.
     */
    public function list(array $params): array
    {
        $search = $params['search'] ?? '';
        $filters = [];
        
        if (!empty($params['department_id'])) $filters['department_id'] = $params['department_id'];
        if (!empty($params['section_id'])) $filters['section_id'] = $params['section_id'];
        if (!empty($params['employee_type'])) $filters['employee_type'] = $params['employee_type'];
        if (!empty($params['employee_status'])) $filters['employee_status'] = $params['employee_status'];
        
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 30)));

        $result = Employee::search($search, $filters, $page, $perPage);

        // Audit log
        $this->audit->log('VIEW', "Viewed employee list with {$result['total']} total records");

        return $result;
    }

    /**
     * Get a single employee with all related data.
     */
    public function get(int $id): ?array
    {
        $employee = Employee::find($id);
        if (!$employee) return null;

        // Parse next of kin
        $employee['next_of_kin_array'] = Employee::parseNextOfKin($employee['next_of_kin'] ?? null);

        // Get department info
        if ($employee['department_id']) {
            $employee['department'] = Department::find((int) $employee['department_id']);
        }

        return $employee;
    }

    /**
     * Get all reference data for forms.
     */
    public function getReferenceData(): array
    {
        $db = \db();

        return [
            'departments' => $db->fetchAll("SELECT * FROM departments ORDER BY name"),
            'sections' => $db->fetchAll("
                SELECT s.*, d.name as department_name 
                FROM sections s 
                LEFT JOIN departments d ON s.department_id = d.id 
                ORDER BY d.name, s.name
            "),
            'subsections' => $db->fetchAll("
                SELECT ss.*, s.name as section_name, d.name as department_name 
                FROM subsections ss 
                LEFT JOIN sections s ON ss.section_id = s.id 
                LEFT JOIN departments d ON ss.department_id = d.id 
                ORDER BY d.name, s.name, ss.name
            "),
            'offices' => $db->fetchAll("SELECT * FROM offices ORDER BY name"),
            'employee_types' => Employee::EMPLOYEE_TYPES,
            'employment_types' => Employee::EMPLOYMENT_TYPES,
            'employee_statuses' => Employee::EMPLOYEE_STATUSES,
            'job_groups' => Employee::JOB_GROUPS,
        ];
    }

    /**
     * Validate that no other employee holds the same leadership role in the same scope.
     */
    private function validateLeadershipUniqueness(
        string $employeeType,
        ?int $departmentId = null,
        ?int $sectionId = null,
        ?int $subsectionId = null,
        ?int $excludeId = null
    ): void {
        $scope = Employee::getLeadershipScope($employeeType);
        if (!$scope) return; // Not a leadership role

        $db = \db();
        $sql = "SELECT id FROM employees WHERE employee_type = ?";
        $types = 's';
        $params = [$employeeType];

        if ($scope === 'department' && $departmentId) {
            $sql .= " AND department_id = ?";
            $types .= 'i';
            $params[] = $departmentId;
        } elseif ($scope === 'section' && $sectionId) {
            $sql .= " AND section_id = ?";
            $types .= 'i';
            $params[] = $sectionId;
        } elseif ($scope === 'subsection' && $subsectionId) {
            $sql .= " AND subsection_id = ?";
            $types .= 'i';
            $params[] = $subsectionId;
        }

        if ($excludeId) {
            $sql .= " AND id != ?";
            $types .= 'i';
            $params[] = $excludeId;
        }

        $existing = $db->fetchOne($sql, $types, $params);
        if ($existing) {
            $roleName = ucwords(str_replace('_', ' ', $employeeType));
            $unit = match ($scope) {
                'organization' => 'the organization',
                'department' => 'this department',
                'section' => 'this section',
                'subsection' => 'this subsection',
                default => 'this unit',
            };
            throw new \RuntimeException("A {$roleName} already exists in {$unit}. Only one is allowed.");
        }
    }

    private function __clone(): void {}
    public function __wakeup(): void
    {
        throw new \RuntimeException('Cannot unserialize singleton');
    }
}
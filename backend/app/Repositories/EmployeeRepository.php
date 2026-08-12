<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Helpers\Database;

/**
 * Employee Repository
 *
 * Handles all database operations for employees.
 * Encapsulates all SQL queries related to employee management.
 */
class EmployeeRepository implements EmployeeRepositoryInterface
{
    private \mysqli $conn;

    public function __construct()
    {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT e.*, 
                   COALESCE(e.first_name, '') as first_name,
                   COALESCE(e.last_name, '') as last_name,
                   d.name as department_name,
                   s.name as section_name,
                   o.name as office_name
            FROM employees e
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN sections s ON e.section_id = s.id
            LEFT JOIN offices o ON e.office_id = o.id
            WHERE e.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $employee = $result->fetch_assoc();
        $stmt->close();

        return $employee ?: null;
    }

    public function findAll(): array
    {
        $result = $this->conn->query("
            SELECT e.*, 
                   d.name as department_name,
                   s.name as section_name
            FROM employees e
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN sections s ON e.section_id = s.id
            ORDER BY e.created_at DESC
        ");

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function create(array $data): int
    {
        $fields = array_keys($data);
        $placeholders = array_fill(0, count($fields), '?');
        $types = '';
        $values = [];

        foreach ($data as $value) {
            if ($value === null) {
                $types .= 's';
                $values[] = null;
            } elseif (is_int($value)) {
                $types .= 'i';
                $values[] = $value;
            } elseif (is_float($value)) {
                $types .= 'd';
                $values[] = $value;
            } else {
                $types .= 's';
                $values[] = $value;
            }
        }

        $sql = "INSERT INTO employees (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $insertId = (int)$this->conn->insert_id;
        $stmt->close();

        return $insertId;
    }

    public function update(int $id, array $data): bool
    {
        $fields = array_keys($data);
        $setClause = implode(' = ?, ', $fields) . ' = ?';
        $types = '';
        $values = [];

        foreach ($data as $value) {
            if ($value === null) {
                $types .= 's';
                $values[] = null;
            } elseif (is_int($value)) {
                $types .= 'i';
                $values[] = $value;
            } elseif (is_float($value)) {
                $types .= 'd';
                $values[] = $value;
            } else {
                $types .= 's';
                $values[] = $value;
            }
        }

        $values[] = $id;
        $types .= 'i';

        $sql = "UPDATE employees SET {$setClause} WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->conn->prepare("DELETE FROM employees WHERE id = ?");
        $stmt->bind_param('i', $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    public function exists(int $id): bool
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM employees WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)$result['count'] > 0;
    }

    public function count(): int
    {
        $result = $this->conn->query("SELECT COUNT(*) as count FROM employees");
        $row = $result->fetch_assoc();

        return (int)$row['count'];
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT e.*, d.name as department_name, s.name as section_name
            FROM employees e
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN sections s ON e.section_id = s.id
            WHERE e.email = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $employee = $result->fetch_assoc();
        $stmt->close();

        return $employee ?: null;
    }

    public function findByEmployeeId(string $employeeId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT e.*, d.name as department_name, s.name as section_name
            FROM employees e
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN sections s ON e.section_id = s.id
            WHERE e.employee_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $employeeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $employee = $result->fetch_assoc();
        $stmt->close();

        return $employee ?: null;
    }

    public function findByUserId(int $userId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT e.*, d.name as department_name, s.name as section_name,
                   ss.name as subsection_name, o.name as office_name
            FROM employees e
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN sections s ON e.section_id = s.id
            LEFT JOIN subsections ss ON e.subsection_id = ss.id
            LEFT JOIN offices o ON e.office_id = o.id
            JOIN users u ON u.employee_id = e.employee_id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $employee = $result->fetch_assoc();
        $stmt->close();

        if (!$employee) {
            return null;
        }

        // Fetch next of kin from separate table
        $employee['next_of_kin_data'] = $this->getNextOfKinForEmployee((int)$employee['id']);
        
        // Fetch dependants from separate table
        $employee['dependants_data'] = $this->getDependantsForEmployee((int)$employee['id']);

        // Fetch documents
        $employee['documents'] = $this->getDocumentsForEmployee((int)$employee['id']);

        return $employee;
    }

    public function search(array $filters, int $page = 1, int $limit = 30): array
    {
        $whereConditions = ["1=1"];
        $params = [];
        $types = '';

        if (!empty($filters['search'])) {
            $searchParam = "%{$filters['search']}%";
            $whereConditions[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.surname LIKE ? OR e.employee_id LIKE ? OR e.email LIKE ? OR e.designation LIKE ? OR d.name LIKE ? OR s.name LIKE ?)";
            $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
            $types .= 'ssssssss';
        }

        if (!empty($filters['department_id'])) {
            $whereConditions[] = "e.department_id = ?";
            $params[] = $filters['department_id'];
            $types .= 'i';
        }

        if (!empty($filters['section_id'])) {
            $whereConditions[] = "e.section_id = ?";
            $params[] = $filters['section_id'];
            $types .= 'i';
        }

        if (!empty($filters['employee_type'])) {
            $whereConditions[] = "e.employee_type = ?";
            $params[] = $filters['employee_type'];
            $types .= 's';
        }

        if (!empty($filters['employee_status'])) {
            $whereConditions[] = "e.employee_status = ?";
            $params[] = $filters['employee_status'];
            $types .= 's';
        }

        $whereClause = implode(" AND ", $whereConditions);
        $offset = ($page - 1) * $limit;

        // Count total - needs same JOINs for search conditions
        $countQuery = "
            SELECT COUNT(*) as total 
            FROM employees e
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN sections s ON e.section_id = s.id
            WHERE {$whereClause}
        ";
        $countStmt = $this->conn->prepare($countQuery);
        if (!empty($params)) {
            $countStmt->bind_param($types, ...$params);
        }
        $countStmt->execute();
        $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        // Fetch employees
        $query = "
            SELECT e.*,
                   COALESCE(e.first_name, '') as first_name,
                   COALESCE(e.last_name, '') as last_name,
                   d.name as department_name,
                   s.name as section_name,
                   o.name as office_name
            FROM employees e
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN sections s ON e.section_id = s.id
            LEFT JOIN offices o ON e.office_id = o.id
            WHERE {$whereClause}
            ORDER BY e.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return [
            'data' => $employees,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => (int)ceil($total / $limit),
        ];
    }

    public function getAllDepartments(): array
    {
        $result = $this->conn->query("SELECT * FROM departments ORDER BY name");
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function getSectionsByDepartment(int $departmentId): array
    {
        $stmt = $this->conn->prepare("
            SELECT id, name FROM sections 
            WHERE department_id = ? 
            ORDER BY name
        ");
        $stmt->bind_param('i', $departmentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $sections = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $sections;
    }

    public function getSubsectionsBySection(int $sectionId): array
    {
        $stmt = $this->conn->prepare("
            SELECT id, name FROM subsections 
            WHERE section_id = ? 
            ORDER BY name
        ");
        $stmt->bind_param('i', $sectionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $subsections = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $subsections;
    }

    public function getAllOffices(): array
    {
        $result = $this->conn->query("SELECT * FROM offices ORDER BY name");
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function findWithDetails(int $id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT e.*, 
                   COALESCE(e.first_name, '') as first_name,
                   COALESCE(e.last_name, '') as last_name,
                   d.name as department_name,
                   s.name as section_name,
                   ss.name as subsection_name,
                   o.name as office_name,
                    u.email as user_email,
                    u.role as user_role,
                    u.is_active as user_is_active
            FROM employees e
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN sections s ON e.section_id = s.id
            LEFT JOIN subsections ss ON e.subsection_id = ss.id
            LEFT JOIN offices o ON e.office_id = o.id
            LEFT JOIN users u ON u.email = e.email
            WHERE e.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $employee = $result->fetch_assoc();
        $stmt->close();

        if (!$employee) {
            return null;
        }

        // Fetch next of kin from separate table
        $employee['next_of_kin_data'] = $this->getNextOfKinForEmployee($id);
        
        // Fetch dependants from separate table
        $employee['dependants_data'] = $this->getDependantsForEmployee($id);
        
        // Fetch documents
        $employee['documents'] = $this->getDocumentsForEmployee($id);

        return $employee;
    }

    public function employeeIdExists(string $employeeId, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) as count FROM employees WHERE employee_id = ?";
        $params = [$employeeId];
        $types = 's';

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
            $types .= 'i';
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)$result['count'] > 0;
    }

    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) as count FROM employees WHERE email = ?";
        $params = [$email];
        $types = 's';

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
            $types .= 'i';
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)$result['count'] > 0;
    }

    public function nationalIdExists(string $nationalId, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) as count FROM employees WHERE national_id = ?";
        $params = [$nationalId];
        $types = 's';

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
            $types .= 'i';
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)$result['count'] > 0;
    }

    /**
     * Get next of kin records for an employee from the next_of_kin table.
     */
    private function getNextOfKinForEmployee(int $employeeId): array
    {
        $stmt = $this->conn->prepare("
            SELECT id, employee_id, name, relationship, contact, created_at, updated_at
            FROM next_of_kin
            WHERE employee_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $records = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $records;
    }

    /**
     * Get dependants for an employee from the dependencies table.
     */
    private function getDependantsForEmployee(int $employeeId): array
    {
        $stmt = $this->conn->prepare("
            SELECT id, employee_id, name, relationship, date_of_birth, gender, id_no, contact, created_at
            FROM dependencies
            WHERE employee_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $records = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $records;
    }

    /**
     * Save next of kin records for an employee (replaces all records).
     */
    public function saveNextOfKin(int $employeeId, array $records): bool
    {
        // Delete existing records
        $stmt = $this->conn->prepare("DELETE FROM next_of_kin WHERE employee_id = ?");
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $stmt->close();

        // Insert new records
        foreach ($records as $record) {
            $name = $record['name'] ?? '';
            $relationship = $record['relationship'] ?? '';
            $contact = $record['contact'] ?? $record['phone'] ?? '';
            $now = date('Y-m-d H:i:s');

            $stmt = $this->conn->prepare("INSERT INTO next_of_kin (employee_id, name, relationship, contact, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('isssss', $employeeId, $name, $relationship, $contact, $now, $now);
            $stmt->execute();
            $stmt->close();
        }

        return true;
    }

    /**
     * Save dependants for an employee (replaces all records).
     */
    public function saveDependants(int $employeeId, array $records): bool
    {
        // Delete existing records
        $stmt = $this->conn->prepare("DELETE FROM dependencies WHERE employee_id = ?");
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $stmt->close();

        // Insert new records
        foreach ($records as $record) {
            $name = $record['name'] ?? '';
            $relationship = $record['relationship'] ?? '';
            $dateOfBirth = $record['date_of_birth'] ?? null;
            $gender = $record['gender'] ?? null;
            $idNo = $record['id_no'] ?? null;
            $contact = $record['contact'] ?? null;
            $now = date('Y-m-d H:i:s');

            $stmt = $this->conn->prepare("INSERT INTO dependencies (employee_id, name, relationship, date_of_birth, gender, id_no, contact, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('isssssss', $employeeId, $name, $relationship, $dateOfBirth, $gender, $idNo, $contact, $now);
            $stmt->execute();
            $stmt->close();
        }

        return true;
    }

    /**
     * Get documents for an employee.
     */
    private function getDocumentsForEmployee(int $employeeId): array
    {
        $stmt = $this->conn->prepare("
            SELECT id, employee_id, document_name as name, category as type, file_name, uploaded_at
            FROM employee_documents
            WHERE employee_id = ?
            ORDER BY uploaded_at DESC
        ");
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $documents = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $documents;
    }

    public function getOrganizationHierarchy(): array
    {
        $departments = [];
        $sections = [];
        $subsections = [];
        $offices = [];

        try {
            $result = $this->conn->query("
                SELECT id, name FROM departments 
                ORDER BY name
            ");
            $departments = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        } catch (\Throwable $e) {
            \logger()->error('Hierarchy departments error', ['error' => $e->getMessage()]);
        }

        try {
            $result = $this->conn->query("
                SELECT id, name, department_id FROM sections 
                ORDER BY name
            ");
            $sections = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        } catch (\Throwable $e) {
            \logger()->error('Hierarchy sections error', ['error' => $e->getMessage()]);
        }

        try {
            // Subsections table doesn't have a status column, so no status filter
            $result = $this->conn->query("
                SELECT id, name, section_id, department_id FROM subsections 
                ORDER BY name
            ");
            $subsections = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        } catch (\Throwable $e) {
            \logger()->error('Hierarchy subsections error', ['error' => $e->getMessage()]);
        }

        try {
            $result = $this->conn->query("
                SELECT id, name FROM offices 
                ORDER BY name
            ");
            $offices = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        } catch (\Throwable $e) {
            \logger()->error('Hierarchy offices error', ['error' => $e->getMessage()]);
        }

        return [
            'departments' => $departments,
            'sections' => $sections,
            'subsections' => $subsections,
            'offices' => $offices,
        ];
    }

    public function getByRole(string $role): array
    {
        $stmt = $this->conn->prepare("
            SELECT e.*, d.name as department_name, s.name as section_name
            FROM employees e
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN sections s ON e.section_id = s.id
            JOIN users u ON u.email = e.email
            WHERE u.role = ?
            ORDER BY e.first_name
        ");
        $stmt->bind_param('s', $role);
        $stmt->execute();
        $result = $stmt->get_result();
        $employees = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $employees;
    }

    public function getByDepartment(int $departmentId): array
    {
        $stmt = $this->conn->prepare("
            SELECT e.*, d.name as department_name, s.name as section_name
            FROM employees e
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN sections s ON e.section_id = s.id
            WHERE e.department_id = ?
            ORDER BY e.first_name
        ");
        $stmt->bind_param('i', $departmentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $employees = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $employees;
    }

    public function getBySection(int $sectionId): array
    {
        $stmt = $this->conn->prepare("
            SELECT e.*, d.name as department_name, s.name as section_name
            FROM employees e
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN sections s ON e.section_id = s.id
            WHERE e.section_id = ?
            ORDER BY e.first_name
        ");
        $stmt->bind_param('i', $sectionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $employees = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $employees;
    }
}
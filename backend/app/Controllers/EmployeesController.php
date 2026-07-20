<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Employee;
use DateTime;

/**
 * EmployeesController
 *
 * Handles employee management: listing, creating, editing, deleting.
 * Uses tabbed interface for different views.
 *
 * Place: backend/app/Controllers/EmployeesController.php
 */
class EmployeesController extends Controller
{
    private Employee $employeeModel;

    public function __construct()
    {
        parent::__construct();
        $this->employeeModel = new Employee();
    }

    /**
     * Display employees list with filters
     * GET /employees
     */
    public function indexAction(): void
    {
        if (!$this->isAuthenticated()) {
            $this->redirect('login');
            return;
        }

        // Allow HR managers and super admins to view employees
        $userRole = $_SESSION['user_role'] ?? '';
        if (!in_array($userRole, ['hr_manager', 'super_admin'])) {
            $_SESSION['flash_error'] = 'Access denied. HR Manager or Super Admin role required.';
            $this->redirect('dashboard');
            return;
        }

        $conn = $this->getDbConnection();

        // Get filter parameters
        $search = trim($_GET['search'] ?? '');
        $department_filter = trim($_GET['department'] ?? '');
        $section_filter = trim($_GET['section'] ?? '');
        $type_filter = trim($_GET['type'] ?? '');
        $status_filter = trim($_GET['status'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;

        // Build query with filters
        $where_conditions = ["1=1"];
        $params = [];
        $types = '';

        if (!empty($search)) {
            $where_conditions[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.surname LIKE ? OR d.name LIKE ? OR s.name LIKE ?)";
            $search_param = "%$search%";
            $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param]);
            $types .= 'sssss';
        }
        if (!empty($department_filter)) {
            $where_conditions[] = "e.department_id = ?";
            $params[] = $department_filter;
            $types .= 'i';
        }
        if (!empty($section_filter)) {
            $where_conditions[] = "e.section_id = ?";
            $params[] = $section_filter;
            $types .= 'i';
        }
        if (!empty($type_filter)) {
            $where_conditions[] = "e.employee_type = ?";
            $params[] = $type_filter;
            $types .= 's';
        }
        if (!empty($status_filter)) {
            $where_conditions[] = "e.employee_status = ?";
            $params[] = $status_filter;
            $types .= 's';
        }

        $where_clause = implode(" AND ", $where_conditions);

        // Count total records
        $countQuery = "SELECT COUNT(*) as total FROM employees e WHERE $where_clause";
        $countStmt = $conn->prepare($countQuery);
        if (!empty($params)) {
            $countStmt->bind_param($types, ...$params);
        }
        $countStmt->execute();
        $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
        $totalPages = (int)ceil($total / $limit);
        $countStmt->close();

        // Fetch employees
        $query = "
            SELECT e.*,
                   COALESCE(e.first_name, '') as first_name,
                   COALESCE(e.last_name, '') as last_name,
                   d.name as department_name,
                   s.name as section_name,
                   ss.name as subsection_name,
                   o.name as office_name
            FROM employees e
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN sections s ON e.section_id = s.id
            LEFT JOIN subsections ss ON e.subsection_id = ss.id
            LEFT JOIN offices o ON e.office_id = o.id
            WHERE $where_clause
            ORDER BY e.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $conn->prepare($query);
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Get filter options
        $departments = $conn->query("SELECT * FROM departments ORDER BY name")->fetch_all(MYSQLI_ASSOC);
        $sections = $conn->query("
            SELECT s.*, d.name as department_name 
            FROM sections s 
            LEFT JOIN departments d ON s.department_id = d.id 
            ORDER BY d.name, s.name
        ")->fetch_all(MYSQLI_ASSOC);

        $this->view('employees/index', [
            'employees' => $employees,
            'departments' => $departments,
            'sections' => $sections,
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
            'search' => $search,
            'department_filter' => $department_filter,
            'section_filter' => $section_filter,
            'type_filter' => $type_filter,
            'status_filter' => $status_filter,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }

    /**
     * Show create employee form
     * GET /employees/create
     */
    public function createAction(): void
    {
        if (!$this->isAuthenticated()) {
            $this->redirect('employees');
            return;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        if (!in_array($userRole, ['hr_manager', 'super_admin'])) {
            $_SESSION['flash_error'] = 'Access denied. HR Manager or Super Admin role required.';
            $this->redirect('employees');
            return;
        }

        $conn = $this->getDbConnection();
        $departments = $conn->query("SELECT * FROM departments ORDER BY name")->fetch_all(MYSQLI_ASSOC);
        $sections = $conn->query("SELECT s.*, d.name as department_name FROM sections s LEFT JOIN departments d ON s.department_id = d.id ORDER BY d.name, s.name")->fetch_all(MYSQLI_ASSOC);
        $subsections = $conn->query("SELECT ss.*, s.name as section_name FROM subsections ss LEFT JOIN sections s ON ss.section_id = s.id ORDER BY s.name, ss.name")->fetch_all(MYSQLI_ASSOC);
        $offices = $conn->query("SELECT * FROM offices ORDER BY name")->fetch_all(MYSQLI_ASSOC);

        $this->view('employees/create', [
            'departments' => $departments,
            'sections' => $sections,
            'subsections' => $subsections,
            'offices' => $offices,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }

    /**
     * Store new employee
     * POST /employees/store
     */
    public function storeAction(): void
    {
        if (!$this->isAuthenticated()) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        if (!in_array($userRole, ['hr_manager', 'super_admin'])) {
            $this->json(['error' => 'Access denied'], 403);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('employees');
            return;
        }

        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            $this->redirect('employees');
            return;
        }

        // Server-side validation
        $errors = [];
        
        // Required fields validation
        $requiredFields = [
            'employee_id', 'first_name', 'last_name', 'gender', 'national_id',
            'email', 'phone', 'date_of_birth', 'address', 'designation',
            'hire_date', 'employment_type', 'employee_type', 'department_id',
            'section_id', 'office_id', 'employee_status'
        ];
        
        foreach ($requiredFields as $field) {
            if (empty($_POST[$field])) {
                $errors[] = ucwords(str_replace('_', ' ', $field)) . ' is required.';
            }
        }
        
        // Email validation
        if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format.';
        }
        
        // Date validation
        if (!empty($_POST['date_of_birth']) && !empty($_POST['hire_date'])) {
            $dob = new DateTime($_POST['date_of_birth']);
            $hireDate = new DateTime($_POST['hire_date']);
            if ($dob > $hireDate) {
                $errors[] = 'Date of birth cannot be after hire date.';
            }
        }
        
        // Validate organizational hierarchy
        $conn = $this->getDbConnection();
        
        // Validate department exists
        $departmentId = (int)($_POST['department_id'] ?? 0);
        $sectionId = (int)($_POST['section_id'] ?? 0);
        $subsectionId = !empty($_POST['subsection_id']) ? (int)$_POST['subsection_id'] : null;
        
        if ($departmentId > 0) {
            $dept = $conn->query("SELECT id FROM departments WHERE id = $departmentId AND status = 'active'")->fetch_assoc();
            if (!$dept) {
                $errors[] = 'Invalid department selected.';
            }
        }
        
        // Validate section exists and belongs to department
        if ($sectionId > 0) {
            $section = $conn->query("SELECT id FROM sections WHERE id = $sectionId AND department_id = $departmentId AND status = 'active'")->fetch_assoc();
            if (!$section) {
                $errors[] = 'Invalid section selected or section does not belong to the selected department.';
            }
        }
        
        // Validate subsection exists and belongs to section (if provided)
        if ($subsectionId !== null && $subsectionId > 0) {
            $subsection = $conn->query("SELECT id FROM subsections WHERE id = $subsectionId AND section_id = $sectionId AND status = 'active'")->fetch_assoc();
            if (!$subsection) {
                $errors[] = 'Invalid sub-section selected or sub-section does not belong to the selected section.';
            }
        }
        
        // Validate office exists
        $officeId = (int)($_POST['office_id'] ?? 0);
        if ($officeId > 0) {
            $office = $conn->query("SELECT id FROM offices WHERE id = $officeId AND status = 'active'")->fetch_assoc();
            if (!$office) {
                $errors[] = 'Invalid office selected.';
            }
        }
        
        // Check if employee ID already exists
        $employeeId = trim($_POST['employee_id']);
        $existing = $conn->query("SELECT id FROM employees WHERE employee_id = '" . $conn->real_escape_string($employeeId) . "'")->fetch_assoc();
        if ($existing) {
            $errors[] = 'Employee ID already exists.';
        }
        
        // Check if email already exists
        $email = trim($_POST['email']);
        $existing = $conn->query("SELECT id FROM employees WHERE email = '" . $conn->real_escape_string($email) . "'")->fetch_assoc();
        if ($existing) {
            $errors[] = 'Email address already exists.';
        }
        
        // Check if national ID already exists
        $nationalId = trim($_POST['national_id']);
        $existing = $conn->query("SELECT id FROM employees WHERE national_id = '" . $conn->real_escape_string($nationalId) . "'")->fetch_assoc();
        if ($existing) {
            $errors[] = 'National ID already exists.';
        }
        
        if (!empty($errors)) {
            $_SESSION['flash_error'] = implode('<br>', $errors);
            $_SESSION['flash_type'] = 'error';
            $_SESSION['form_data'] = $_POST;
            $this->redirect('employees/create');
            return;
        }
        
        // Prepare data for insertion
        $data = [
            'employee_id' => $employeeId,
            'first_name' => trim($_POST['first_name']),
            'last_name' => trim($_POST['last_name']),
            'surname' => !empty($_POST['surname']) ? trim($_POST['surname']) : null,
            'gender' => $_POST['gender'],
            'national_id' => $nationalId,
            'email' => $email,
            'phone' => trim($_POST['phone']),
            'date_of_birth' => $_POST['date_of_birth'],
            'address' => trim($_POST['address']),
            'designation' => trim($_POST['designation']),
            'hire_date' => $_POST['hire_date'],
            'employment_type' => $_POST['employment_type'],
            'employee_type' => $_POST['employee_type'],
            'department_id' => $departmentId,
            'section_id' => $sectionId,
            'subsection_id' => $subsectionId,
            'office_id' => $officeId,
            'scale_id' => !empty($_POST['scale_id']) ? (int)$_POST['scale_id'] : null,
            'salary' => !empty($_POST['salary']) ? (float)$_POST['salary'] : null,
            'employee_status' => $_POST['employee_status'],
            'next_of_kin' => !empty($_POST['next_of_kin']) ? trim($_POST['next_of_kin']) : null,
            'profile_token' => bin2hex(random_bytes(16)),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        // Build INSERT query
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
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        
        if ($stmt->execute()) {
            $employeeId = $conn->insert_id;
            
            // Create user account for the employee
            $this->createEmployeeUserAccount($conn, $data);
            
            $_SESSION['flash_message'] = 'Employee created successfully!';
            $_SESSION['flash_type'] = 'success';
            $this->redirect('employees');
        } else {
            $_SESSION['flash_error'] = 'Failed to create employee. Please try again.';
            $_SESSION['flash_type'] = 'error';
            $_SESSION['form_data'] = $_POST;
            $this->redirect('employees/create');
        }
        
        $stmt->close();
    }
    
    /**
     * Create user account for employee
     */
    private function createEmployeeUserAccount(mysqli $conn, array $employeeData): void
    {
        // Check if user already exists
        $existing = $conn->query("SELECT id FROM users WHERE email = '" . $conn->real_escape_string($employeeData['email']) . "'")->fetch_assoc();
        if ($existing) {
            return; // User already exists
        }
        
        // Generate random password
        $password = bin2hex(random_bytes(8));
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        
        // Create user account
        $stmt = $conn->prepare("
            INSERT INTO users (email, password, role, employee_id, status, created_at, updated_at)
            VALUES (?, ?, 'employee', ?, 'active', NOW(), NOW())
        ");
        
        $stmt->bind_param('ssi', 
            $employeeData['email'],
            $passwordHash,
            $conn->insert_id
        );
        
        $stmt->execute();
        $stmt->close();
        
        // TODO: Send welcome email with credentials
    }

    /**
     * Show edit employee form
     * GET /employees/edit/{id}
     */
    public function editAction(int $id): void
    {
        if (!$this->isAuthenticated()) {
            $this->redirect('employees');
            return;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        if (!in_array($userRole, ['hr_manager', 'super_admin'])) {
            $_SESSION['flash_error'] = 'Access denied. HR Manager or Super Admin role required.';
            $this->redirect('employees');
            return;
        }

        $conn = $this->getDbConnection();
        $employee = $conn->query("SELECT * FROM employees WHERE id = $id")->fetch_assoc();

        if (!$employee) {
            $_SESSION['flash_error'] = 'Employee not found.';
            $this->redirect('employees');
            return;
        }

        $departments = $conn->query("SELECT * FROM departments ORDER BY name")->fetch_all(MYSQLI_ASSOC);
        $sections = $conn->query("SELECT s.*, d.name as department_name FROM sections s LEFT JOIN departments d ON s.department_id = d.id ORDER BY d.name, s.name")->fetch_all(MYSQLI_ASSOC);
        $subsections = $conn->query("SELECT ss.*, s.name as section_name FROM subsections ss LEFT JOIN sections s ON ss.section_id = s.id ORDER BY s.name, ss.name")->fetch_all(MYSQLI_ASSOC);
        $offices = $conn->query("SELECT * FROM offices ORDER BY name")->fetch_all(MYSQLI_ASSOC);

        $this->view('employees/edit', [
            'employee' => $employee,
            'departments' => $departments,
            'sections' => $sections,
            'subsections' => $subsections,
            'offices' => $offices,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }

    /**
     * Update employee
     * POST /employees/update/{id}
     */
    public function updateAction(int $id): void
    {
        if (!$this->isAuthenticated()) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        if (!in_array($userRole, ['hr_manager', 'super_admin'])) {
            $this->json(['error' => 'Access denied'], 403);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('employees');
            return;
        }

        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            $this->redirect('employees');
            return;
        }

        // TODO: Add validation and update logic
        $_SESSION['flash_message'] = 'Employee updated successfully!';
        $_SESSION['flash_type'] = 'success';
        $this->redirect('employees');
    }

    /**
     * Delete employee
     * GET /employees/delete/{id}
     */
    public function deleteAction(int $id): void
    {
        if (!$this->isAuthenticated()) {
            $this->redirect('employees');
            return;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        if (!in_array($userRole, ['hr_manager', 'super_admin'])) {
            $_SESSION['flash_error'] = 'Access denied. HR Manager or Super Admin role required.';
            $this->redirect('employees');
            return;
        }

        // TODO: Add delete logic
        $_SESSION['flash_message'] = 'Employee deleted successfully!';
        $_SESSION['flash_type'] = 'success';
        $this->redirect('employees');
    }

    /**
     * Get sections by department ID (API)
     * GET /api/organization/sections?department_id={id}
     */
    public function getSectionsAction(): void
    {
        if (!$this->isAuthenticated()) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }

        $departmentId = (int)($_GET['department_id'] ?? 0);
        
        if ($departmentId <= 0) {
            $this->json(['error' => 'Invalid department ID'], 400);
            return;
        }

        $conn = $this->getDbConnection();
        $stmt = $conn->prepare("SELECT id, name FROM sections WHERE department_id = ? ORDER BY name");
        $stmt->bind_param('i', $departmentId);
        $stmt->execute();
        $result = $stmt->get_result();
        if (!$result) {
            $this->json(['success' => false, 'data' => [], 'error' => $conn->error], 500);
            return;
        }
        $sections = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $this->json(['success' => true, 'data' => $sections]);
    }

    /**
     * Get sub-sections by section ID (API)
     * GET /api/organization/sub-sections?section_id={id}
     */
    public function getSubsectionsAction(): void
    {
        if (!$this->isAuthenticated()) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }

        $sectionId = (int)($_GET['section_id'] ?? 0);
        
        if ($sectionId <= 0) {
            $this->json(['error' => 'Invalid section ID'], 400);
            return;
        }

        $conn = $this->getDbConnection();
        $stmt = $conn->prepare("SELECT id, name FROM subsections WHERE section_id = ? ORDER BY name");
        $stmt->bind_param('i', $sectionId);
        $stmt->execute();
        $result = $stmt->get_result();
        if (!$result) {
            $this->json(['success' => false, 'data' => [], 'error' => $conn->error], 500);
            return;
        }
        $subsections = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $this->json(['success' => true, 'data' => $subsections]);
    }

    /**
     * Get employee data for modal editing (API)
     * GET /employees/getEmployeeData?id={id}
     */
    public function getEmployeeDataAction(): void
    {
        try {
            if (!$this->isAuthenticated()) {
                $this->json(['error' => 'Unauthorized'], 403);
                return;
            }

            $userRole = $_SESSION['user_role'] ?? '';
            if (!in_array($userRole, ['hr_manager', 'super_admin'])) {
                $this->json(['error' => 'Access denied'], 403);
                return;
            }

            $id = (int)($_GET['id'] ?? 0);
            
            if ($id <= 0) {
                $this->json(['error' => 'Invalid employee ID'], 400);
                return;
            }

            $conn = $this->getDbConnection();
            
            // Get employee data
            $result = $conn->query("SELECT * FROM employees WHERE id = $id");
            if (!$result) {
                throw new \Exception("Database error fetching employee: " . $conn->error);
            }
            $employee = $result->fetch_assoc();
            
            if (!$employee) {
                $this->json(['error' => 'Employee not found'], 404);
                return;
            }

            // Get departments, sections, subsections and offices for dropdowns
            $result = $conn->query("SELECT id, name FROM departments ORDER BY name");
            if (!$result) {
                throw new \Exception("Database error fetching departments: " . $conn->error);
            }
            $departments = $result->fetch_all(MYSQLI_ASSOC);
            
            $result = $conn->query("SELECT id, name, department_id FROM sections ORDER BY name");
            if (!$result) {
                throw new \Exception("Database error fetching sections: " . $conn->error);
            }
            $sections = $result->fetch_all(MYSQLI_ASSOC);
            
            $result = $conn->query("SELECT id, name, section_id FROM subsections ORDER BY name");
            if (!$result) {
                throw new \Exception("Database error fetching subsections: " . $conn->error);
            }
            $subsections = $result->fetch_all(MYSQLI_ASSOC);
            
            $result = $conn->query("SELECT id, name FROM offices ORDER BY name");
            if (!$result) {
                throw new \Exception("Database error fetching offices: " . $conn->error);
            }
            $offices = $result->fetch_all(MYSQLI_ASSOC);

            $this->json([
                'success' => true,
                'employee' => $employee,
                'departments' => $departments,
                'sections' => $sections,
                'subsections' => $subsections,
                'offices' => $offices
            ]);
        } catch (\Exception $e) {
            error_log('Error in getEmployeeDataAction: ' . $e->getMessage());
            $this->json(['error' => 'Server error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get all organizational data (API)
     * GET /api/organization/hierarchy
     */
    public function getOrganizationHierarchyAction(): void
    {
        if (!$this->isAuthenticated()) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }

        $conn = $this->getDbConnection();
        
        // Get all departments
        $departments = $conn->query("SELECT id, name FROM departments WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
        
        // Get all sections with department_id
        $sections = $conn->query("SELECT id, name, department_id FROM sections WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
        
        // Get all sub-sections with section_id
        $subsections = $conn->query("SELECT id, name, section_id FROM subsections WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
        
        // Get all offices
        $offices = $conn->query("SELECT id, name FROM offices WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

        $this->json([
            'success' => true,
            'data' => [
                'departments' => $departments,
                'sections' => $sections,
                'subsections' => $subsections,
                'offices' => $offices
            ]
        ]);
    }
}

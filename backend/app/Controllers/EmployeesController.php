<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Employee;

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
            $where_conditions[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ? OR e.email LIKE ?)";
            $search_param = "%$search%";
            $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
            $types .= 'ssss';
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

        // TODO: Add validation and save logic
        $_SESSION['flash_message'] = 'Employee created successfully!';
        $_SESSION['flash_type'] = 'success';
        $this->redirect('employees');
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
}
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;

/**
 * DepartmentsController
 *
 * Handles department, section, and subsection management.
 * Uses the same pattern as EmployeesController.
 *
 * Place: backend/app/Controllers/DepartmentsController.php
 */
class DepartmentsController extends Controller
{
    /**
     * Display departments, sections, and subsections
     * GET /departments
     */
    public function indexAction(): void
    {
        if (!$this->isAuthenticated()) {
            $this->redirect('login');
            return;
        }

        // Only HR managers and super admins can view departments
        $auth = \App\Helpers\Auth::getInstance();
        $userRole = $_SESSION['user_role'] ?? '';
        if (!in_array($userRole, ['hr_manager', 'super_admin'])) {
            $_SESSION['flash_error'] = 'You do not have permission to access this resource.';
            $this->redirect('dashboard');
            return;
        }

        $conn = $this->getDbConnection();

        // Fetch data
        try {
            $departments_result = $conn->query("SELECT * FROM departments ORDER BY name");
            $departments = $departments_result ? $departments_result->fetch_all(MYSQLI_ASSOC) : [];

            $sections_result = $conn->query(
                "SELECT s.*, d.name as department_name
                 FROM sections s
                 LEFT JOIN departments d ON s.department_id = d.id
                 ORDER BY d.name, s.name"
            );
            $sections = $sections_result ? $sections_result->fetch_all(MYSQLI_ASSOC) : [];

            $subsections_result = $conn->query(
                "SELECT ss.*, s.name as section_name, d.name as department_name
                 FROM subsections ss
                 LEFT JOIN sections s ON ss.section_id = s.id
                 LEFT JOIN departments d ON ss.department_id = d.id
                 ORDER BY d.name, s.name, ss.name"
            );
            $subsections = $subsections_result ? $subsections_result->fetch_all(MYSQLI_ASSOC) : [];

        } catch (\Exception $e) {
            $departments = $sections = $subsections = [];
            $error = 'Error fetching data: ' . $e->getMessage();
        }

        $this->view('departments/index', [
            'departments' => $departments,
            'sections' => $sections,
            'subsections' => $subsections,
            'error' => $error ?? null,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }

    /**
     * Add department
     * POST /departments/add
     */
    public function addDepartmentAction(): void
    {
        $this->handleDepartmentRequest('add');
    }

    /**
     * Edit department
     * POST /departments/edit
     */
    public function editDepartmentAction(): void
    {
        $this->handleDepartmentRequest('edit');
    }

    /**
     * Delete department
     * POST /departments/delete
     */
    public function deleteDepartmentAction(): void
    {
        $this->handleDepartmentRequest('delete');
    }

    /**
     * Add section
     * POST /departments/section/add
     */
    public function addSectionAction(): void
    {
        $this->handleSectionRequest('add');
    }

    /**
     * Edit section
     * POST /departments/section/edit
     */
    public function editSectionAction(): void
    {
        $this->handleSectionRequest('edit');
    }

    /**
     * Delete section
     * POST /departments/section/delete
     */
    public function deleteSectionAction(): void
    {
        $this->handleSectionRequest('delete');
    }

    /**
     * Add subsection
     * POST /departments/subsection/add
     */
    public function addSubsectionAction(): void
    {
        $this->handleSubsectionRequest('add');
    }

    /**
     * Edit subsection
     * POST /departments/subsection/edit
     */
    public function editSubsectionAction(): void
    {
        $this->handleSubsectionRequest('edit');
    }

    /**
     * Delete subsection
     * POST /departments/subsection/delete
     */
    public function deleteSubsectionAction(): void
    {
        $this->handleSubsectionRequest('delete');
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function handleDepartmentRequest(string $action): void
    {
        if (!$this->isAuthenticated()) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        if (!in_array($userRole, ['hr_manager', 'super_admin'])) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('departments');
            return;
        }

        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            $this->redirect('departments');
            return;
        }

        $conn = $this->getDbConnection();
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        try {
            if ($action === 'add') {
                $stmt = $conn->prepare("INSERT INTO departments (name, description) VALUES (?, ?)");
                $stmt->bind_param("ss", $name, $description);
                $stmt->execute();
                $stmt->close();
                $_SESSION['flash_message'] = 'Department added successfully!';
                $_SESSION['flash_type'] = 'success';

            } elseif ($action === 'edit') {
                $id = (int)$_POST['id'];
                $stmt = $conn->prepare("UPDATE departments SET name=?, description=?, updated_at=NOW() WHERE id=?");
                $stmt->bind_param("ssi", $name, $description, $id);
                $stmt->execute();
                $stmt->close();
                $_SESSION['flash_message'] = 'Department updated successfully!';
                $_SESSION['flash_type'] = 'success';

            } elseif ($action === 'delete') {
                $id = (int)$_POST['id'];

                // Check if department has employees
                $stmt = $conn->prepare("SELECT COUNT(*) FROM employees WHERE department_id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->bind_result($employeeCount);
                $stmt->fetch();
                $stmt->close();

                if ($employeeCount > 0) {
                    $_SESSION['flash_error'] = "Cannot delete department: It has {$employeeCount} employees assigned to it.";
                    $this->redirect('departments');
                    return;
                }

                // Delete related data
                $conn->query("DELETE FROM subsections WHERE department_id = {$id}");
                $conn->query("DELETE FROM sections WHERE department_id = {$id}");
                $conn->query("DELETE FROM departments WHERE id = {$id}");

                $_SESSION['flash_message'] = 'Department deleted successfully!';
                $_SESSION['flash_type'] = 'success';
            }
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
        }

        $this->redirect('departments');
    }

    private function handleSectionRequest(string $action): void
    {
        if (!$this->isAuthenticated()) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        if (!in_array($userRole, ['hr_manager', 'super_admin'])) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('departments');
            return;
        }

        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            $this->redirect('departments');
            return;
        }

        $conn = $this->getDbConnection();
        $name = trim($_POST['section_name'] ?? '');
        $description = trim($_POST['section_description'] ?? '');
        $department_id = (int)($_POST['department_id'] ?? 0);

        try {
            if ($action === 'add') {
                $stmt = $conn->prepare("INSERT INTO sections (name, description, department_id) VALUES (?, ?, ?)");
                $stmt->bind_param("ssi", $name, $description, $department_id);
                $stmt->execute();
                $stmt->close();
                $_SESSION['flash_message'] = 'Section added successfully!';
                $_SESSION['flash_type'] = 'success';

            } elseif ($action === 'edit') {
                $id = (int)$_POST['section_id'];
                $stmt = $conn->prepare("UPDATE sections SET name=?, description=?, department_id=?, updated_at=NOW() WHERE id=?");
                $stmt->bind_param("ssii", $name, $description, $department_id, $id);
                $stmt->execute();
                $stmt->close();
                $_SESSION['flash_message'] = 'Section updated successfully!';
                $_SESSION['flash_type'] = 'success';

            } elseif ($action === 'delete') {
                $id = (int)$_POST['id'];

                // Check if section has employees
                $stmt = $conn->prepare("SELECT COUNT(*) FROM employees WHERE section_id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->bind_result($employeeCount);
                $stmt->fetch();
                $stmt->close();

                if ($employeeCount > 0) {
                    $_SESSION['flash_error'] = "Cannot delete section: It has {$employeeCount} employees assigned to it.";
                    $this->redirect('departments');
                    return;
                }

                $conn->query("DELETE FROM subsections WHERE section_id = {$id}");
                $conn->query("DELETE FROM sections WHERE id = {$id}");

                $_SESSION['flash_message'] = 'Section deleted successfully!';
                $_SESSION['flash_type'] = 'success';
            }
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
        }

        $this->redirect('departments');
    }

    private function handleSubsectionRequest(string $action): void
    {
        if (!$this->isAuthenticated()) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }
        
        $userRole = $_SESSION['user_role'] ?? '';
        if (!in_array($userRole, ['hr_manager', 'super_admin'])) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('departments');
            return;
        }

        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            $this->redirect('departments');
            return;
        }

        $conn = $this->getDbConnection();
        $name = trim($_POST['subsection_name'] ?? '');
        $description = trim($_POST['subsection_description'] ?? '');
        $department_id = (int)($_POST['department_id'] ?? 0);
        $section_id = (int)($_POST['section_id'] ?? 0);

        try {
            if ($action === 'add') {
                $stmt = $conn->prepare("INSERT INTO subsections (name, description, department_id, section_id) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssii", $name, $description, $department_id, $section_id);
                $stmt->execute();
                $stmt->close();
                $_SESSION['flash_message'] = 'Sub-section added successfully!';
                $_SESSION['flash_type'] = 'success';

            } elseif ($action === 'edit') {
                $id = (int)$_POST['subsection_id'];
                $stmt = $conn->prepare("UPDATE subsections SET name=?, description=?, department_id=?, section_id=?, updated_at=NOW() WHERE id=?");
                $stmt->bind_param("ssiii", $name, $description, $department_id, $section_id, $id);
                $stmt->execute();
                $stmt->close();
                $_SESSION['flash_message'] = 'Sub-section updated successfully!';
                $_SESSION['flash_type'] = 'success';

            } elseif ($action === 'delete') {
                $id = (int)$_POST['id'];

                // Check if subsection has employees
                $stmt = $conn->prepare("SELECT COUNT(*) FROM employees WHERE subsection_id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $stmt->bind_result($employeeCount);
                $stmt->fetch();
                $stmt->close();

                if ($employeeCount > 0) {
                    $_SESSION['flash_error'] = "Cannot delete sub-section: It has {$employeeCount} employees assigned to it.";
                    $this->redirect('departments');
                    return;
                }

                $conn->query("DELETE FROM subsections WHERE id = {$id}");

                $_SESSION['flash_message'] = 'Sub-section deleted successfully!';
                $_SESSION['flash_type'] = 'success';
            }
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
        }

        $this->redirect('departments');
    }
}
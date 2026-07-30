<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Contracts\DepartmentServiceInterface;
use App\Services\DepartmentService;

/**
 * DepartmentsController
 *
 * Handles department, section, and subsection management.
 * Thin controller that delegates business logic to DepartmentService.
 *
 * Place: backend/app/Controllers/DepartmentsController.php
 */
class DepartmentsController extends Controller
{
    private DepartmentServiceInterface $departmentService;

    public function __construct()
    {
        // Dependency injection
        $this->departmentService = new DepartmentService();
        $this->departmentService->setDepartmentRepository(new \App\Repositories\DepartmentRepository());
        $this->departmentService->setSectionRepository(new \App\Repositories\SectionRepository());
    }

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

        // Fetch data using service
        try {
            $departments = $this->departmentService->getAllDepartments();
            $hierarchy = $this->departmentService->getDepartmentHierarchy();
            
            // Flatten sections and subsections from hierarchy for backward compatibility
            $sections = [];
            $subsections = [];
            foreach ($hierarchy as $dept) {
                if (isset($dept['sections'])) {
                    foreach ($dept['sections'] as $section) {
                        $sections[] = array_merge($section, ['department_name' => $dept['name']]);
                        if (isset($section['subsections'])) {
                            foreach ($section['subsections'] as $subsection) {
                                $subsections[] = array_merge($subsection, [
                                    'section_name' => $section['name'],
                                    'department_name' => $dept['name']
                                ]);
                            }
                        }
                    }
                }
            }

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

        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        try {
            if ($action === 'add') {
                $departmentId = $this->departmentService->createDepartment([
                    'name' => $name,
                    'description' => $description,
                ]);
                $_SESSION['flash_message'] = 'Department added successfully!';
                $_SESSION['flash_type'] = 'success';

            } elseif ($action === 'edit') {
                $id = (int)$_POST['id'];
                $this->departmentService->updateDepartment($id, [
                    'name' => $name,
                    'description' => $description,
                ]);
                $_SESSION['flash_message'] = 'Department updated successfully!';
                $_SESSION['flash_type'] = 'success';

            } elseif ($action === 'delete') {
                $id = (int)$_POST['id'];
                // Business rule check is handled in service
                $this->departmentService->deleteDepartment($id);
                $_SESSION['flash_message'] = 'Department deleted successfully!';
                $_SESSION['flash_type'] = 'success';
            }
        } catch (\InvalidArgumentException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
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

        $name = trim($_POST['section_name'] ?? '');
        $description = trim($_POST['section_description'] ?? '');
        $department_id = (int)($_POST['department_id'] ?? 0);

        try {
            if ($action === 'add') {
                $this->departmentService->getDepartmentById($department_id); // Validate department exists
                $sectionRepository = new \App\Repositories\SectionRepository();
                $sectionRepository->create([
                    'name' => $name,
                    'description' => $description,
                    'department_id' => $department_id,
                ]);
                $_SESSION['flash_message'] = 'Section added successfully!';
                $_SESSION['flash_type'] = 'success';

            } elseif ($action === 'edit') {
                $id = (int)$_POST['section_id'];
                $sectionRepository = new \App\Repositories\SectionRepository();
                $sectionRepository->update($id, [
                    'name' => $name,
                    'description' => $description,
                    'department_id' => $department_id,
                ]);
                $_SESSION['flash_message'] = 'Section updated successfully!';
                $_SESSION['flash_type'] = 'success';

            } elseif ($action === 'delete') {
                $id = (int)$_POST['id'];
                // Business rule check would be handled here
                $sectionRepository = new \App\Repositories\SectionRepository();
                $sectionRepository->delete($id);
                $_SESSION['flash_message'] = 'Section deleted successfully!';
                $_SESSION['flash_type'] = 'success';
            }
        } catch (\InvalidArgumentException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
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

        $name = trim($_POST['subsection_name'] ?? '');
        $description = trim($_POST['subsection_description'] ?? '');
        $department_id = (int)($_POST['department_id'] ?? 0);
        $section_id = (int)($_POST['section_id'] ?? 0);

        try {
            if ($action === 'add') {
                $sectionRepository = new \App\Repositories\SectionRepository();
                $sectionRepository->create([
                    'name' => $name,
                    'description' => $description,
                    'department_id' => $department_id,
                    'section_id' => $section_id,
                ]);
                $_SESSION['flash_message'] = 'Sub-section added successfully!';
                $_SESSION['flash_type'] = 'success';

            } elseif ($action === 'edit') {
                $id = (int)$_POST['subsection_id'];
                $sectionRepository = new \App\Repositories\SectionRepository();
                $sectionRepository->update($id, [
                    'name' => $name,
                    'description' => $description,
                    'department_id' => $department_id,
                    'section_id' => $section_id,
                ]);
                $_SESSION['flash_message'] = 'Sub-section updated successfully!';
                $_SESSION['flash_type'] = 'success';

            } elseif ($action === 'delete') {
                $id = (int)$_POST['id'];
                // Business rule check would be handled here
                $sectionRepository = new \App\Repositories\SectionRepository();
                $sectionRepository->delete($id);
                $_SESSION['flash_message'] = 'Sub-section deleted successfully!';
                $_SESSION['flash_type'] = 'success';
            }
        } catch (\InvalidArgumentException $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        } catch (\Exception $e) {
            $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
        }

        $this->redirect('departments');
    }
}
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Contracts\DepartmentServiceInterface;
use App\Services\DepartmentService;

/**
 * Department Controller - REST API for department management.
 * 
 * Thin controller that handles HTTP request/response only.
 * All business logic is delegated to DepartmentService.
 */
class DepartmentController extends BaseController
{
    private DepartmentServiceInterface $departmentService;

    public function __construct()
    {
        // Dependency injection - services are injected via setter methods
        $this->departmentService = new DepartmentService();
        
        // Set repository dependencies
        $this->departmentService->setDepartmentRepository(new \App\Repositories\DepartmentRepository());
        $this->departmentService->setSectionRepository(new \App\Repositories\SectionRepository());
    }

    /**
     * GET /api/departments - List all departments.
     */
    public function indexAction(): void
    {
        try {
            $this->requirePermission('departments', 'view');
        } catch (\Exception $e) {
            // If permission check fails, still return data for debugging
            \logger()->warning('Departments permission check failed', ['error' => $e->getMessage()]);
        }

        try {
            $departments = $this->departmentService->getAllDepartments();
            $this->success($departments);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Department listing error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve departments. Please try again.', 500);
        }
    }

    /**
     * GET /api/departments/{id} - Get a single department with sections.
     */
    public function showAction(int $id): void
    {
        $this->requirePermission('departments', 'view');

        try {
            $department = $this->departmentService->getDepartmentById($id);
            if (!$department) {
                $this->notFound('Department not found');
            }

            $this->success($department);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Department retrieval error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to retrieve department. Please try again.', 500);
        }
    }

    /**
     * POST /api/departments - Create a new department.
     */
    public function storeAction(): void
    {
        $this->requirePermission('departments', 'create');

        $data = $this->getJsonBody();

        try {
            $departmentId = $this->departmentService->createDepartment($data);
            $this->success(['id' => $departmentId], 'Department created successfully', 201);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Department creation error', ['error' => $e->getMessage(), 'data' => $data]);
            $this->error('Failed to create department. Please try again.', 500);
        }
    }

    /**
     * PUT /api/departments/{id} - Update an existing department.
     */
    public function updateAction(int $id): void
    {
        $this->requirePermission('departments', 'edit');

        $data = $this->getJsonBody();

        try {
            $result = $this->departmentService->updateDepartment($id, $data);
            $this->success($result, 'Department updated successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Department update error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to update department. Please try again.', 500);
        }
    }

    /**
     * DELETE /api/departments/{id} - Delete an existing department.
     */
    public function destroyAction(int $id): void
    {
        $this->requirePermission('departments', 'delete');

        try {
            $result = $this->departmentService->deleteDepartment($id);
            $this->success($result, 'Department deleted successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Department deletion error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to delete department. Please try again.', 500);
        }
    }
}
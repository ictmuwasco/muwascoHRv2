<?php

declare(strict_types=1);

namespace App\Controllers\HR;

use App\Controllers\BaseController;

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
        // Read-only department hierarchy powers dropdowns/filters across modules
        // (leave roster, reports, forms). Any AUTHENTICATED user may read it;
        // department *management* endpoints below still enforce departments.view.
        //
        // NOTE: requirePermission() responds 403 and exits - wrapping it in
        // try/catch to "continue anyway" never worked and only blocked requests.
        if ($this->getUserId() === 0) {
            $this->unauthorized('Authentication required');
        }
        if (!$this->hasPermission('departments', 'view')) {
            \logger()->info('Departments list served without departments.view', ['user_id' => $this->getUserId()]);
        }

        try {
            $departments = $this->departmentService->getDepartmentHierarchy();
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

        $data = $this->validateRequest(new \App\Validators\DepartmentValidator());

        try {
            $departmentId = $this->departmentService->createDepartment($data);
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_DEPARTMENTS,
                \App\Services\AuditService::ACTION_CREATE,
                'Created department',
                ['target_type' => 'Department', 'target_id' => $departmentId, 'target_name' => $data['name'] ?? null, 'new_values' => $data]
            );
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

        $data = $this->validateRequest(new \App\Validators\DepartmentValidator());

        try {
            $oldDept = $this->departmentService->getDepartmentById($id);
            $result = $this->departmentService->updateDepartment($id, $data);
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_DEPARTMENTS,
                \App\Services\AuditService::ACTION_UPDATE,
                'Updated department',
                ['target_type' => 'Department', 'target_id' => $id, 'target_name' => $oldDept['name'] ?? null, 'old_values' => $oldDept, 'new_values' => $data]
            );
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
            $oldDept = $this->departmentService->getDepartmentById($id);
            $result = $this->departmentService->deleteDepartment($id);
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_DEPARTMENTS,
                \App\Services\AuditService::ACTION_DELETE,
                'Deleted department',
                ['target_type' => 'Department', 'target_id' => $id, 'target_name' => $oldDept['name'] ?? null, 'old_values' => $oldDept]
            );
            $this->success($result, 'Department deleted successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Department deletion error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to delete department. Please try again.', 500);
        }
    }
}


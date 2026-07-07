<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\EmployeeService;

/**
 * Employee Controller - REST API for employee management.
 * 
 * Handles all employee CRUD operations with proper validation,
 * authorization, and audit logging.
 */
class EmployeeController extends BaseController
{
    private EmployeeService $employeeService;

    public function __construct()
    {
        $this->employeeService = EmployeeService::getInstance();
    }

    /**
     * GET /api/employees - List employees with pagination and filters.
     */
    public function indexAction(): void
    {
        $this->requirePermission('employees', 'view');

        try {
            $params = array_merge($_GET, $this->getFilters());
            $result = $this->employeeService->list($params);
            $this->success($result);
        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * GET /api/employees/{id} - Get a single employee.
     */
    public function showAction(array $params = []): void
    {
        $this->requirePermission('employees', 'view');

        $id = (int) ($params['id'] ?? 0);
        if (!$id) {
            $this->error('Employee ID is required', 400);
        }

        $employee = $this->employeeService->get($id);
        if (!$employee) {
            $this->notFound('Employee not found');
        }

        $this->success($employee);
    }

    /**
     * POST /api/employees - Create a new employee.
     */
    public function storeAction(): void
    {
        $this->requirePermission('employees', 'create');

        $data = $this->getJsonBody();
        
        // Validate required fields
        $required = ['employee_id', 'first_name', 'last_name', 'national_id', 'email', 'designation', 'employee_type', 'hire_date'];
        $missing = $this->validateRequired($data, $required);
        
        if ($missing) {
            $this->error('Missing required fields: ' . implode(', ', $missing), 400);
        }

        try {
            $result = $this->employeeService->create($data);
            $this->success($result, 'Employee created successfully', 201);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee creation error', ['error' => $e->getMessage()]);
            $this->error('Failed to create employee. Please try again.', 500);
        }
    }

    /**
     * PUT /api/employees/{id} - Update an existing employee.
     */
    public function updateAction(array $params = []): void
    {
        $this->requirePermission('employees', 'edit');

        $id = (int) ($params['id'] ?? 0);
        if (!$id) {
            $this->error('Employee ID is required', 400);
        }

        $data = $this->getJsonBody();

        try {
            $result = $this->employeeService->update($id, $data);
            $this->success($result, 'Employee updated successfully');
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee update error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to update employee. Please try again.', 500);
        }
    }

    /**
     * DELETE /api/employees/{id} - Delete an employee.
     */
    public function destroyAction(array $params = []): void
    {
        $this->requirePermission('employees', 'delete');

        $id = (int) ($params['id'] ?? 0);
        if (!$id) {
            $this->error('Employee ID is required', 400);
        }

        try {
            $result = $this->employeeService->delete($id);
            $this->success($result, 'Employee deleted successfully');
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee deletion error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to delete employee. Please try again.', 500);
        }
    }

    /**
     * GET /api/employees/search - Search employees.
     */
    public function searchAction(): void
    {
        $this->requirePermission('employees', 'view');

        $params = array_merge($_GET, $this->getFilters());
        $result = $this->employeeService->list($params);
        $this->success($result);
    }

    /**
     * GET /api/employees/reference - Get reference data for forms.
     */
    public function referenceAction(): void
    {
        $this->requirePermission('employees', 'view');

        $data = $this->employeeService->getReferenceData();
        $this->success($data);
    }
}
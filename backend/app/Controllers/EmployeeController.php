<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Contracts\EmployeeServiceInterface;
use App\Services\EmployeeService;

/**
 * Employee Controller - REST API for employee management.
 * 
 * Thin controller that handles HTTP request/response only.
 * All business logic is delegated to EmployeeService.
 */
class EmployeeController extends BaseController
{
    private EmployeeServiceInterface $employeeService;

    public function __construct()
    {
        // Dependency injection - services are injected via setter methods
        $this->employeeService = new EmployeeService();
        
        // Set repository dependencies
        $this->employeeService->setEmployeeRepository(new \App\Repositories\EmployeeRepository());
        $this->employeeService->setDepartmentRepository(new \App\Repositories\DepartmentRepository());
        $this->employeeService->setSectionRepository(new \App\Repositories\SectionRepository());
        $this->employeeService->setOfficeRepository(new \App\Repositories\OfficeRepository());
    }

    /**
     * GET /api/employees - List employees with pagination and filters.
     */
    public function indexAction(): void
    {
        $this->requirePermission('employees', 'view');

        try {
            $filters = $this->getFilters();
            $page = (int)($filters['page'] ?? 1);
            $limit = (int)($filters['limit'] ?? 30);
            
            // Remove pagination params from filters
            unset($filters['page'], $filters['limit']);
            
            $result = $this->employeeService->getAllEmployees($filters, $page, $limit);
            $this->success($result);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee listing error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve employees. Please try again.', 500);
        }
    }

    /**
     * GET /api/employees/{id} - Get a single employee.
     */
    public function showAction(int $id): void
    {
        $this->requirePermission('employees', 'view');

        try {
            $employee = $this->employeeService->getEmployeeById($id);
            if (!$employee) {
                $this->notFound('Employee not found');
            }

            $this->success($employee);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee retrieval error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to retrieve employee. Please try again.', 500);
        }
    }

    /**
     * POST /api/employees - Create a new employee.
     */
    public function storeAction(): void
    {
        $this->requirePermission('employees', 'create');

        $data = $this->getJsonBody();

        try {
            $employeeId = $this->employeeService->createEmployee($data);
            $this->success(['id' => $employeeId], 'Employee created successfully', 201);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee creation error', ['error' => $e->getMessage(), 'data' => $data]);
            $this->error('Failed to create employee. Please try again.', 500);
        }
    }

    /**
     * PUT /api/employees/{id} - Update an existing employee.
     */
    public function updateAction(int $id): void
    {
        $this->requirePermission('employees', 'edit');

        $data = $this->getJsonBody();

        try {
            $result = $this->employeeService->updateEmployee($id, $data);
            $this->success($result, 'Employee updated successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee update error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to update employee. Please try again.', 500);
        }
    }

    /**
     * DELETE /api/employees/{id} - Delete an employee.
     */
    public function destroyAction(int $id): void
    {
        $this->requirePermission('employees', 'delete');

        try {
            $result = $this->employeeService->deleteEmployee($id);
            $this->success($result, 'Employee deleted successfully');
        } catch (\InvalidArgumentException $e) {
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

        try {
            $query = $_GET['q'] ?? $_GET['query'] ?? '';
            $filters = $this->getFilters();
            $page = (int)($filters['page'] ?? 1);
            $limit = (int)($filters['limit'] ?? 30);
            
            unset($filters['page'], $filters['limit'], $filters['q'], $filters['query']);
            
            $result = $this->employeeService->searchEmployees($query, $filters, $page, $limit);
            $this->success($result);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Employee search error', ['error' => $e->getMessage()]);
            $this->error('Failed to search employees. Please try again.', 500);
        }
    }

    /**
     * GET /api/employees/reference - Get reference data for forms.
     */
    public function referenceAction(): void
    {
        $this->requirePermission('employees', 'view');

        try {
            $data = [
                'departments' => $this->employeeService->getDepartments(),
                'sections' => [],
                'subsections' => [],
                'offices' => $this->employeeService->getOffices(),
                'hierarchy' => $this->employeeService->getOrganizationHierarchy(),
            ];
            $this->success($data);
        } catch (\Exception $e) {
            \logger()->error('Employee reference data error', ['error' => $e->getMessage()]);
            $this->error('Failed to load reference data. Please try again.', 500);
        }
    }
}

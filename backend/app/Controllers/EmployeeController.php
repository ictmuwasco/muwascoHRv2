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
            $departments = [];
            $sections = [];
            $subsections = [];
            $offices = [];
            $hierarchy = [];

            // Fetch each data source independently so one failure doesn't break all
            try {
                $departments = $this->employeeService->getDepartments() ?? [];
            } catch (\Exception $e) {
                \logger()->error('Reference departments error', ['error' => $e->getMessage()]);
            }

            try {
                $offices = $this->employeeService->getOffices() ?? [];
            } catch (\Exception $e) {
                \logger()->error('Reference offices error', ['error' => $e->getMessage()]);
            }

            try {
                $hierarchy = $this->employeeService->getOrganizationHierarchy() ?? [];
                $sections = $hierarchy['sections'] ?? [];
                $subsections = $hierarchy['subsections'] ?? [];
            } catch (\Exception $e) {
                \logger()->error('Reference hierarchy error', ['error' => $e->getMessage()]);
            }

            $data = [
                'departments' => $departments,
                'sections' => $sections,
                'subsections' => $subsections,
                'offices' => $offices,
                'hierarchy' => $hierarchy,
            ];
            $this->success($data);
        } catch (\Exception $e) {
            \logger()->error('Employee reference data error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->error('Failed to load reference data: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/profile - Get the current user's profile.
     */
    public function profileAction(): void
    {
        $this->requirePermission('profile', 'view');

        try {
            $userId = $this->getUserId();
            if ($userId === 0) {
                $this->unauthorized('Authentication required');
            }

            // Get the employee associated with the current user
            $employee = $this->employeeService->getEmployeeByUserId($userId);
            if (!$employee) {
                $this->notFound('Employee profile not found');
            }

            // Build profile data structure expected by the frontend
            $profile = [
                'personal' => [
                    'first_name' => $employee['first_name'] ?? '',
                    'last_name' => $employee['last_name'] ?? '',
                    'surname' => $employee['surname'] ?? '',
                    'email' => $employee['email'] ?? '',
                    'phone' => $employee['phone'] ?? '',
                    'national_id' => $employee['national_id'] ?? '',
                    'gender' => $employee['gender'] ?? '',
                    'marital_status' => $employee['marital_status'] ?? '',
                    'address' => $employee['address'] ?? '',
                ],
                'employment' => [
                    'department' => $employee['department_name'] ?? '',
                    'section' => $employee['section_name'] ?? '',
                    'office' => $employee['office_name'] ?? '',
                    'designation' => $employee['designation'] ?? '',
                    'employee_type' => $employee['employee_type'] ?? '',
                    'employee_status' => $employee['employee_status'] ?? '',
                    'employment_date' => $employee['hire_date'] ?? $employee['employment_date'] ?? '',
                ],
                'next_of_kin' => [
                    'name' => $employee['next_of_kin_name'] ?? '',
                    'relationship' => $employee['next_of_kin_relationship'] ?? '',
                    'phone' => $employee['next_of_kin_phone'] ?? '',
                    'email' => $employee['next_of_kin_email'] ?? '',
                    'address' => $employee['next_of_kin_address'] ?? '',
                ],
                'documents' => [],
            ];

            $this->success($profile);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Profile retrieval error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->error('Failed to load profile. Please try again.', 500);
        }
    }

    /**
     * PUT /api/profile - Update the current user's profile.
     */
    public function updateProfileAction(): void
    {
        $this->requirePermission('profile', 'edit');

        try {
            $userId = $this->getUserId();
            if ($userId === 0) {
                $this->unauthorized('Authentication required');
            }

            // Get the employee associated with the current user
            $employee = $this->employeeService->getEmployeeByUserId($userId);
            if (!$employee) {
                $this->notFound('Employee profile not found');
            }

            $data = $this->getJsonBody();
            $updateData = [];

            // Handle personal info updates
            if (isset($data['personal']) && is_array($data['personal'])) {
                $personal = $data['personal'];
                $allowedPersonalFields = [
                    'first_name', 'last_name', 'surname', 'email', 'phone',
                    'national_id', 'gender', 'marital_status', 'address'
                ];
                foreach ($allowedPersonalFields as $field) {
                    if (isset($personal[$field])) {
                        $updateData[$field] = $personal[$field];
                    }
                }
            }

            // Handle employment info updates
            if (isset($data['employment']) && is_array($data['employment'])) {
                $employment = $data['employment'];
                $allowedEmploymentFields = [
                    'designation', 'employee_type', 'employee_status', 'employment_date'
                ];
                foreach ($allowedEmploymentFields as $field) {
                    if (isset($employment[$field])) {
                        $updateData[$field] = $employment[$field];
                    }
                }
            }

            if (empty($updateData)) {
                $this->error('No valid fields to update', 400);
            }

            // Update the employee record (partial update, no full validation)
            $result = $this->employeeService->updateEmployeeProfile((int)$employee['id'], $updateData);
            $this->success($result, 'Profile updated successfully');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            \logger()->error('Profile update error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->error('Failed to update profile. Please try again.', 500);
        }
    }
}

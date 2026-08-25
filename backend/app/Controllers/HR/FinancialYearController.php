<?php

declare(strict_types=1);

namespace App\Controllers\HR;

use App\Controllers\BaseController;

use App\Models\FinancialYear;
use App\Models\EmployeeLeaveBalance;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Services\FinancialYearService;
use App\Helpers\Auth;
use App\Helpers\RBAC;

/**
 * FinancialYearController
 * 
 * Handles API endpoints for financial year management.
 */
class FinancialYearController extends BaseController
{
    private FinancialYearService $financialYearService;

    public function __construct()
    {
        $this->financialYearService = new FinancialYearService();
    }

    /**
     * Get all financial years.
     */
    public function indexAction(): void
    {
        try {
            // Authorization check
            $auth = Auth::getInstance();
            if (!$this->checkPermission('financial_year', 'view')) {
                $this->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
                return;
            }

            $financialYears = FinancialYear::all('start_date', 'DESC');
            $data = \App\Http\Resources\FinancialYearResource::collection($financialYears);

            $this->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            error_log("Financial Year Index Error: " . $e->getMessage());
            $this->json([
                'success' => false,
                'message' => 'Failed to fetch financial years',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get financial year status.
     */
    public function statusAction(): void
    {
        try {
            $auth = Auth::getInstance();
            if (!$this->checkPermission('financial_year', 'view')) {
                $this->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
                return;
            }

            $status = $this->financialYearService->getFinancialYearStatus();
            $currentFY = $this->financialYearService->getCurrentFinancialYear();

            $currentFYData = null;
            if ($currentFY) {
                $currentFYData = \App\Http\Resources\FinancialYearResource::toArray($currentFY);
            }

            $this->json([
                'success' => true,
                'data' => [
                    'status' => $status,
                    'current_financial_year' => $currentFYData,
                ],
            ]);
        } catch (\Exception $e) {
            error_log("Financial Year Status Error: " . $e->getMessage());
            $this->json([
                'success' => false,
                'message' => 'Failed to fetch status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new financial year.
     */
    public function storeAction(): void
    {
        try {
            // Financial year creation includes leave allocation for all employees
            // which can take a long time. Remove PHP execution time limit.
            set_time_limit(0);
            
            $auth = Auth::getInstance();
            if (!$this->checkPermission('financial_year', 'create')) {
                $this->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
                return;
            }

            // Get JSON input
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                $this->json([
                    'success' => false,
                    'message' => 'Invalid request data',
                ], 422);
                return;
            }

            // Check if creation is allowed
            $canCreate = $this->financialYearService->canCreateNewFinancialYear();
            if (!$canCreate['can_create']) {
                $this->json([
                    'success' => false,
                    'message' => $canCreate['reason'],
                    'can_create' => false,
                ], 403);
                return;
            }

            // Validate input
            $requiredFields = ['year_name', 'start_date', 'end_date', 'total_days'];
            foreach ($requiredFields as $field) {
                if (empty($input[$field])) {
                    $this->json([
                        'success' => false,
                        'message' => "Missing required field: {$field}",
                    ], 422);
                    return;
                }
            }

            // Get current user
            $user = $auth->user();
            $createdBy = $user['name'] ?? 'System';

            // Create financial year
            $result = $this->financialYearService->createFinancialYear([
                'year_name' => $input['year_name'],
                'start_date' => $input['start_date'],
                'end_date' => $input['end_date'],
                'total_days' => (int)$input['total_days'],
            ], $createdBy);

            if ($result['success']) {
                $this->json([
                    'success' => true,
                    'message' => $result['message'],
                    'data' => [
                        'financial_year_id' => $result['financial_year_id'],
                        'allocated_count' => $result['allocated_count'],
                    ],
                ], 201);
            } else {
                $this->json([
                    'success' => false,
                    'message' => $result['error'],
                ], 500);
            }

        } catch (\Exception $e) {
            error_log("Financial Year Store Error: " . $e->getMessage());
            $this->json([
                'success' => false,
                'message' => 'Failed to create financial year',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Allocate leave to an employee.
     */
    public function allocateLeaveAction(): void
    {
        try {
            $auth = Auth::getInstance();
            if (!$this->checkPermission('financial_year', 'edit')) {
                $this->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);

            if (!$input || empty($input['employee_id']) || empty($input['financial_year_id'])) {
                $this->json([
                    'success' => false,
                    'message' => 'Missing required fields: employee_id, financial_year_id',
                ], 422);
                return;
            }

            $employeeId = (int)$input['employee_id'];
            $financialYearId = (int)$input['financial_year_id'];
            $selectedLeaveTypes = $input['leave_types'] ?? null;

            // Verify financial year exists
            $fy = FinancialYear::findById($financialYearId);
            if (!$fy) {
                $this->json([
                    'success' => false,
                    'message' => 'Financial year not found',
                ], 404);
                return;
            }

            // Allocate leave
            $result = $this->financialYearService->allocateLeaveToEmployee(
                $employeeId,
                $financialYearId,
                $selectedLeaveTypes
            );

            if (!empty($result['error'])) {
                $this->json([
                    'success' => false,
                    'message' => $result['error'],
                ], 500);
                return;
            }

            $message = "Leave allocated successfully. {$result['allocated']} new record(s) created.";
            if ($result['skipped'] > 0) {
                $message .= " {$result['skipped']} already existed and were skipped.";
            }

            $this->json([
                'success' => true,
                'message' => $message,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            error_log("Leave Allocation Error: " . $e->getMessage());
            $this->json([
                'success' => false,
                'message' => 'Failed to allocate leave',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get leave types.
     */
    public function leaveTypesAction(): void
    {
        try {
            $auth = Auth::getInstance();
            if (!$auth->check()) {
                $this->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
                return;
            }

            $leaveTypes = LeaveType::all();
            $data = array_map(function ($lt) {
                return [
                    'id' => $lt['id'],
                    'name' => $lt['name'],
                    'description' => $lt['description'] ?? null,
                    'is_active' => (bool)($lt['is_active'] ?? false),
                ];
            }, $leaveTypes);

            $this->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            error_log("Leave Types Error: " . $e->getMessage());
            $this->json([
                'success' => false,
                'message' => 'Failed to fetch leave types',
            ], 500);
        }
    }

    /**
     * Get active employees for allocation.
     */
    public function employeesAction(): void
    {
        try {
            $auth = Auth::getInstance();
            if (!$this->checkPermission('financial_year', 'edit')) {
                $this->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
                return;
            }

            $employees = Employee::where(['employee_status' => 'active']);
            $data = array_map(function ($emp) {
                return [
                    'id' => $emp['id'],
                    'full_name' => trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '') . (!empty($emp['surname']) ? ' ' . $emp['surname'] : '')),
                ];
            }, $employees);

            $this->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            error_log("Employees Error: " . $e->getMessage());
            $this->json([
                'success' => false,
                'message' => 'Failed to fetch employees',
            ], 500);
        }
    }

    /**
     * Check permission with development mode fallback.
     */
    private function checkPermission(string $module, string $action): bool
    {
        $auth = Auth::getInstance();
        
        // First check if user is authenticated
        if (!$auth->check()) {
            return false;
        }
        
        // Get current user role
        $user = $auth->user();
        $role = $user['role'] ?? '';
        
        // Super admin always has access
        if ($role === 'super_admin') {
            return true;
        }
        
        // Check permission using RBAC
        $rbac = \App\Helpers\RBAC::getInstance();
        if ($rbac->hasPermission($role, $module, $action)) {
            return true;
        }
        
        // Fallback: in development mode, allow authenticated users
        $env = env('APP_ENV', 'production');
        if ($env === 'development') {
            return true;
        }
        
        return false;
    }
}


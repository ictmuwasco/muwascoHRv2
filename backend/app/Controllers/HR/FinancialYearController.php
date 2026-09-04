<?php

declare(strict_types=1);

namespace App\Controllers\HR;

use App\Controllers\BaseController;
use App\Models\FinancialYear;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Services\FinancialYearService;

/**
 * FinancialYearController
 *
 * API endpoints for financial year management.
 *
 * Authorization: route metadata (`financial_year:view|create|edit`) is
 * enforced by AuthorizationMiddleware; each action additionally calls
 * requirePermission() as a second, engine-backed guard. The legacy
 * RBAC/development-mode bypass previously used here was removed in Phase 3
 * (H3): all permission decisions flow through the central engine.
 */
class FinancialYearController extends BaseController
{
    private FinancialYearService $financialYearService;

    public function __construct()
    {
        $this->financialYearService = new FinancialYearService();
    }

    /**
     * GET /api/admin/financial-years - All financial years.
     */
    public function indexAction(): void
    {
        $this->requirePermission('financial_year', 'view');

        try {
            $financialYears = FinancialYear::all('start_date', 'DESC');
            $data = \App\Http\Resources\FinancialYearResource::collection($financialYears);

            $this->success($data, 'OK');
        } catch (\Throwable $e) {
            \logger()->error('Financial year index failed', ['error' => $e->getMessage()]);
            $this->error('Failed to fetch financial years', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * GET /api/admin/financial-years/status - Current FY status.
     */
    public function statusAction(): void
    {
        $this->requirePermission('financial_year', 'view');

        try {
            $status = $this->financialYearService->getFinancialYearStatus();
            $currentFY = $this->financialYearService->getCurrentFinancialYear();

            $currentFYData = null;
            if ($currentFY) {
                $currentFYData = \App\Http\Resources\FinancialYearResource::toArray($currentFY);
            }

            $this->success([
                'status' => $status,
                'current_financial_year' => $currentFYData,
            ], 'OK');
        } catch (\Throwable $e) {
            \logger()->error('Financial year status failed', ['error' => $e->getMessage()]);
            $this->error('Failed to fetch status', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * POST /api/admin/financial-year/add - Create a financial year.
     *
     * Creation also allocates leave for every employee and can be long
     * running; the execution time limit is lifted for this action only.
     */
    public function storeAction(): void
    {
        $this->requirePermission('financial_year', 'create');

        try {
            set_time_limit(0);

            $input = $this->getJsonBody();
            if (!$input) {
                $this->error('Invalid request data', 422, 'VALIDATION_ERROR');
            }

            // Business gate: creation windows are controlled by the service.
            $canCreate = $this->financialYearService->canCreateNewFinancialYear();
            if (!$canCreate['can_create']) {
                $this->error(
                    (string) $canCreate['reason'],
                    403,
                    'BUSINESS_RULE',
                    ['can_create' => false]
                );
            }

            // Shape validation (uniqueness/existence rules live in the service).
            $requiredFields = ['year_name', 'start_date', 'end_date', 'total_days'];
            foreach ($requiredFields as $field) {
                if (empty($input[$field])) {
                    $this->error("Missing required field: {$field}", 422, 'VALIDATION_ERROR');
                }
            }

            $createdBy = \App\Helpers\Auth::getInstance()->user()['name'] ?? 'System';

            $result = $this->financialYearService->createFinancialYear([
                'year_name' => $input['year_name'],
                'start_date' => $input['start_date'],
                'end_date' => $input['end_date'],
                'total_days' => (int) $input['total_days'],
            ], $createdBy);

            if ($result['success']) {
                $this->success([
                    'financial_year_id' => $result['financial_year_id'],
                    'allocated_count' => $result['allocated_count'],
                ], (string) $result['message'], 201);
            }

            $this->error((string) ($result['error'] ?? 'Failed to create financial year'), 400, 'BUSINESS_RULE');
        } catch (\Throwable $e) {
            \logger()->error('Financial year creation failed', ['error' => $e->getMessage()]);
            $this->error('Failed to create financial year', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * POST /api/admin/financial-year/allocate - Allocate leave to one employee.
     */
    public function allocateLeaveAction(): void
    {
        $this->requirePermission('financial_year', 'edit');

        try {
            $input = $this->getJsonBody();

            if (!$input || empty($input['employee_id']) || empty($input['financial_year_id'])) {
                $this->error('Missing required fields: employee_id, financial_year_id', 422, 'VALIDATION_ERROR');
            }

            $employeeId = (int) $input['employee_id'];
            $financialYearId = (int) $input['financial_year_id'];
            $selectedLeaveTypes = $input['leave_types'] ?? null;

            // Existence guard (business validation lives in the service).
            $fy = FinancialYear::findById($financialYearId);
            if (!$fy) {
                $this->error('Financial year not found', 404, 'NOT_FOUND');
            }

            $result = $this->financialYearService->allocateLeaveToEmployee(
                $employeeId,
                $financialYearId,
                $selectedLeaveTypes
            );

            if (!empty($result['error'])) {
                $this->error((string) $result['error'], 400, 'ALLOCATION_FAILED');
            }

            $message = "Leave allocated successfully. {$result['allocated']} new record(s) created.";
            if (($result['skipped'] ?? 0) > 0) {
                $message .= " {$result['skipped']} already existed and were skipped.";
            }

            $this->success($result, $message);
        } catch (\Throwable $e) {
            \logger()->error('Leave allocation failed', ['error' => $e->getMessage()]);
            $this->error('Failed to allocate leave', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * GET /api/admin/financial-years/leave-types - Leave type catalogue.
     */
    public function leaveTypesAction(): void
    {
        $this->requirePermission('financial_year', 'view');

        try {
            $leaveTypes = LeaveType::all();
            $data = array_map(static fn (array $lt): array => [
                'id' => $lt['id'],
                'name' => $lt['name'],
                'description' => $lt['description'] ?? null,
                'is_active' => (bool) ($lt['is_active'] ?? false),
            ], $leaveTypes);

            $this->success($data, 'OK');
        } catch (\Throwable $e) {
            \logger()->error('Leave types fetch failed', ['error' => $e->getMessage()]);
            $this->error('Failed to fetch leave types', 500, 'INTERNAL_ERROR');
        }
    }

    /**
     * GET /api/admin/financial-years/employees - Active employees for allocation.
     */
    public function employeesAction(): void
    {
        $this->requirePermission('financial_year', 'edit');

        try {
            $employees = Employee::where(['employee_status' => 'active']);
            $data = array_map(static fn (array $emp): array => [
                'id' => $emp['id'],
                'full_name' => trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '') . (!empty($emp['surname']) ? ' ' . $emp['surname'] : '')),
            ], $employees);

            $this->success($data, 'OK');
        } catch (\Throwable $e) {
            \logger()->error('Employee fetch failed', ['error' => $e->getMessage()]);
            $this->error('Failed to fetch employees', 500, 'INTERNAL_ERROR');
        }
    }
}

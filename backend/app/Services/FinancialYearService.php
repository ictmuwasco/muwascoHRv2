<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FinancialYear;
use App\Models\EmployeeLeaveBalance;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Services\NotificationService;
use App\Helpers\Auth;

/**
 * FinancialYearService
 * 
 * Contains business logic for financial year management.
 * Handles creation, status checks, and validation rules.
 */
class FinancialYearService
{
    private NotificationService $notificationService;

    public function __construct()
    {
        $this->notificationService = NotificationService::getInstance();
    }

    /**
     * Get the current financial year.
     */
    public function getCurrentFinancialYear(): ?array
    {
        $today = date('Y-m-d');
        $fy = FinancialYear::where(['is_active' => 1]);
        
        foreach ($fy as $year) {
            if ($today >= $year['start_date'] && $today <= $year['end_date']) {
                return $year;
            }
        }

        // If no active FY found, calculate the expected one
        return $this->calculateExpectedFinancialYear();
    }

    /**
     * Calculate the expected financial year based on current date.
     * Financial years run from July 1 to June 30.
     */
    public function calculateExpectedFinancialYear(): array
    {
        $year = (int)date('Y');
        $month = (int)date('n');
        
        // If current month is July or later, FY starts this year
        // If current month is before July, FY started last year
        $startYear = $month >= 7 ? $year : $year - 1;
        $endYear = $startYear + 1;

        return [
            'year_name' => "{$startYear}/" . substr((string)$endYear, 2),
            'start_date' => "{$startYear}-07-01",
            'end_date' => "{$endYear}-06-30",
            'total_days' => $this->calculateTotalDays("{$startYear}-07-01", "{$endYear}-06-30"),
        ];
    }

    /**
     * Calculate total days between two dates.
     */
    public function calculateTotalDays(string $startDate, string $endDate): int
    {
        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        return (int)$start->diff($end)->format('%a') + 1;
    }

    /**
     * Phase 5 §8: THE single financial-year resolver for "which FY applies
     * right now" across all modules (roster, profiles, dashboards, reports).
     *
     * Selection order (preserves the legacy behaviour found in
     * LeaveProfileService::getCurrentFinancialYearId and
     * LeaveRosterController::defaultFyId):
     *   1. the latest-starting year that has not ended yet (end_date >= today)
     *   2. otherwise the most recent year by id
     *   3. 0 when no financial years exist (callers treat 0 as "unconfigured")
     */
    public function resolveCurrentFinancialYearId(): int
    {
        $db = \db();

        $row = $db->fetchOne(
            "SELECT id FROM financial_years WHERE end_date >= CURDATE() ORDER BY id DESC LIMIT 1"
        );
        if ($row) {
            return (int) $row['id'];
        }

        $row = $db->fetchOne("SELECT id FROM financial_years ORDER BY id DESC LIMIT 1");
        return $row ? (int) $row['id'] : 0;
    }

    /**
     * Phase 5 §8: which financial year does a given date fall into?
     * Answers "what financial year is this date?" from one implementation.
     *
     * @return int|null The financial year id, or null when no configured
     *                  year covers the date.
     */
    public function yearIdForDate(string $date): ?int
    {
        $row = \db()->fetchOne(
            "SELECT id FROM financial_years WHERE start_date <= ? AND end_date >= ? ORDER BY start_date DESC LIMIT 1",
            'ss',
            [$date, $date]
        );
        return $row ? (int) $row['id'] : null;
    }

    /**
     * Check if a new financial year can be created.
     * Blocks duplicate years, but allows creation in any month.
     */
    public function canCreateNewFinancialYear(): array
    {
        $nextFY = $this->calculateExpectedFinancialYear();

        // Check if it already exists
        $existing = FinancialYear::where(['year_name' => $nextFY['year_name']]);
        if (!empty($existing) && count($existing) > 0) {
            return [
                'can_create' => false,
                'reason' => "Financial year {$nextFY['year_name']} already exists.",
                'next_fy' => null,
            ];
        }

        // Allow creation in any month
        return [
            'can_create' => true,
            'reason' => "You can create financial year {$nextFY['year_name']}.",
            'next_fy' => $nextFY,
        ];
    }

    /**
     * Get financial year status information.
     */
    public function getFinancialYearStatus(): array
    {
        $month = (int)date('n');
        $day = (int)date('j');
        
        $nextFY = $this->calculateExpectedFinancialYear();
        $existing = FinancialYear::where(['year_name' => $nextFY['year_name']]);
        
        if (!empty($existing) && count($existing) > 0) {
            $alertClass = 'success';
            $message = "✓ Financial Year {$nextFY['year_name']} is already created.";
        } elseif ($month === 7 && $day === 1) {
            $alertClass = 'danger';
            $message = "🚨 URGENT: It's July 1st! Create financial year {$nextFY['year_name']} immediately!";
        } else {
            $alertClass = 'warning';
            $message = "⚠️ It's " . date('F') . ". You can create financial year {$nextFY['year_name']}.";
        }

        return [
            'alert_class' => $alertClass,
            'message' => $message,
            'current_month' => date('F'),
            'current_date' => date('Y-m-d'),
            'next_financial_year' => $nextFY,
            'exists' => !empty($existing),
        ];
    }

    /**
     * Create a new financial year with automatic leave allocation.
     */
    public function createFinancialYear(array $data, string $createdBy): array
    {
        $db = \db();
        $db->beginTransaction();

        try {
            // Insert financial year
            $db->query("
                INSERT INTO financial_years (year_name, start_date, end_date, total_days, is_active, created_at, updated_at)
                VALUES (?, ?, ?, ?, 1, NOW(), NOW())
            ", "sssi", [
                $data['year_name'],
                $data['start_date'],
                $data['end_date'],
                $data['total_days']
            ]);
            $financialYearId = $db->lastInsertId();

            // Allocate leave to all employees
            $allocatedCount = $this->allocateLeaveToAllEmployees($financialYearId);

            $db->commit();

            // Trigger notification (best-effort - must not fail the request
            // since the financial year is already committed)
            try {
                $this->triggerFinancialYearCreatedNotification($data, $createdBy, $allocatedCount);
            } catch (\Throwable $notifyError) {
                error_log("Financial Year Notification Error (non-fatal): " . $notifyError->getMessage());
            }

            return [
                'success' => true,
                'financial_year_id' => $financialYearId,
                'allocated_count' => $allocatedCount,
                'message' => "Financial year '{$data['year_name']}' created. Leave allocated to {$allocatedCount} employee-leave type combinations.",
            ];

        } catch (\Exception $e) {
            $db->rollback();
            error_log("Financial Year Creation Error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Error creating financial year: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Allocate leave to all active employees for a financial year.
     */
    private function allocateLeaveToAllEmployees(int $financialYearId): int
    {
        $employees = Employee::where(['employee_status' => 'active']);
        $leaveTypes = LeaveType::all();
        
        $allocatedCount = 0;

        foreach ($employees as $employee) {
            $result = $this->allocateLeaveToEmployee(
                (int)$employee['id'],
                $financialYearId,
                null,
                $leaveTypes
            );
            $allocatedCount += $result['allocated'];
        }

        return $allocatedCount;
    }

    /**
     * Allocate leave to a specific employee.
     */
    public function allocateLeaveToEmployee(
        int $employeeId,
        int $financialYearId,
        ?array $selectedLeaveTypes = null,
        ?array $allLeaveTypes = null
    ): array {
        $db = \db();
        $db->beginTransaction();

        try {
            $employee = Employee::findById($employeeId);
            if (!$employee) {
                throw new \Exception("Employee {$employeeId} not found or inactive");
            }

            $leaveTypes = $allLeaveTypes ?? LeaveType::all();
            $gender = strtolower(trim($employee['gender'] ?? ''));
            $employment = strtolower(trim($employee['employment_type'] ?? ''));
            $rules = $this->resolveLeaveRules($gender, $employment);

            if ($selectedLeaveTypes) {
                $rules = array_values(array_filter($rules, fn($r) => in_array($r['leave_type_id'], $selectedLeaveTypes)));
            }

            if (empty($rules)) {
                throw new \Exception("No applicable leave rules found for gender='{$gender}', employment='{$employment}'");
            }

            $prevFyId = $this->getPreviousFinancialYearId($financialYearId);
            $broughtForwardDays = $prevFyId ? $this->getBroughtForwardDays($employeeId, $prevFyId) : 0.0;

            $allocated = 0;
            $skipped = 0;

            foreach ($rules as $rule) {
                // Check if already exists
                $exists = EmployeeLeaveBalance::where([
                    'employee_id' => $employeeId,
                    'leave_type_id' => $rule['leave_type_id'],
                    'financial_year_id' => $financialYearId,
                ]);

                if (!empty($exists)) {
                    $skipped++;
                    continue;
                }

                $allocatedDays = (float)$rule['allocated_days'];
                $broughtForward = ($rule['leave_type_id'] === 1 && $employment === 'permanent') ? $broughtForwardDays : 0.0;
                $accumulated = $allocatedDays + $broughtForward;
                $remaining = $accumulated;

                EmployeeLeaveBalance::create([
                    'employee_id' => $employeeId,
                    'leave_type_id' => (int)$rule['leave_type_id'],
                    'financial_year_id' => $financialYearId,
                    'allocated_days' => $allocatedDays,
                    'brought_forward_days' => $broughtForward,
                    'used_days' => 0.0,
                    'accumulated_days' => $accumulated,
                    'remaining_days' => $remaining,
                ]);

                $allocated++;
            }

            $db->commit();

            return [
                'allocated' => $allocated,
                'skipped' => $skipped,
                'gender' => $gender,
                'employment' => $employment,
            ];

        } catch (\Exception $e) {
            $db->rollback();
            error_log("Employee Leave Allocation Error: " . $e->getMessage());
            return ['allocated' => 0, 'skipped' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Resolve leave rules based on gender and employment type.
     */
    private function resolveLeaveRules(string $gender, string $employment): array
    {
        $allRules = $this->getLeaveRules();
        $resolved = [];

        foreach ($allRules as $rule) {
            $genderMatch = ($rule['gender'] === 'all' || $rule['gender'] === $gender);
            $employmentMatch = ($rule['employment'] === 'all' || $rule['employment'] === $employment);

            if ($genderMatch && $employmentMatch) {
                $resolved[] = $rule;
            }
        }

        return $resolved;
    }

    /**
     * Get leave allocation rules.
     */
    private function getLeaveRules(): array
    {
        return [
            ['leave_type_id' => 1, 'allocated_days' => 30, 'gender' => 'all', 'employment' => 'permanent'],
            ['leave_type_id' => 1, 'allocated_days' => 0, 'gender' => 'all', 'employment' => 'contract'],
            ['leave_type_id' => 2, 'allocated_days' => 10, 'gender' => 'all', 'employment' => 'all'],
            ['leave_type_id' => 3, 'allocated_days' => 120, 'gender' => 'female', 'employment' => 'all'],
            ['leave_type_id' => 4, 'allocated_days' => 14, 'gender' => 'male', 'employment' => 'all'],
            ['leave_type_id' => 5, 'allocated_days' => 10, 'gender' => 'all', 'employment' => 'all'],
            ['leave_type_id' => 6, 'allocated_days' => 0, 'gender' => 'all', 'employment' => 'all'],
            ['leave_type_id' => 7, 'allocated_days' => 10, 'gender' => 'all', 'employment' => 'all'],
            ['leave_type_id' => 8, 'allocated_days' => 0, 'gender' => 'all', 'employment' => 'all'],
            ['leave_type_id' => 9, 'allocated_days' => 0, 'gender' => 'all', 'employment' => 'all'],
        ];
    }

    /**
     * Get the previous financial year ID.
     */
    private function getPreviousFinancialYearId(int $currentFinancialYearId): ?int
    {
        $currentFY = FinancialYear::findById($currentFinancialYearId);
        if (!$currentFY) return null;

        $db = \db();
        $result = $db->fetchOne("
            SELECT id FROM financial_years 
            WHERE end_date < ? 
            ORDER BY end_date DESC 
            LIMIT 1
        ", "s", [$currentFY['start_date']]);
        
        return $result ? (int)$result['id'] : null;
    }

    /**
     * Get brought-forward days from previous financial year.
     */
    private function getBroughtForwardDays(int $employeeId, int $previousFinancialYearId): float
    {
        $db = \db();
        $result = $db->fetchOne(
            "SELECT remaining_days FROM employee_leave_balances WHERE employee_id = ? AND financial_year_id = ? AND leave_type_id = 1",
            "ii",
            [$employeeId, $previousFinancialYearId]
        );

        return $result ? (float)$result['remaining_days'] : 0.0;
    }

    /**
     * Trigger notification when financial year is created.
     */
    private function triggerFinancialYearCreatedNotification(array $financialYear, string $createdBy, int $allocatedCount): void
    {
        $auth = Auth::getInstance();
        $user = $auth->user();

        $title = "Financial Year Created";
        $message = "Financial year {$financialYear['year_name']} was created by {$createdBy}. Leave allocated to {$allocatedCount} employee-leave type combinations.";

        // Notify all admin users (best-effort)
        $db = \db();
        $admins = $db->fetchAll(
            "SELECT id FROM users WHERE role IN ('super_admin', 'hr_manager') AND is_active = 1"
        );

        foreach ($admins as $admin) {
            $this->notificationService->sendInApp(
                (int) $admin['id'],
                $title,
                $message,
                'info',
                'financial_year.php'
            );
        }
    }
}
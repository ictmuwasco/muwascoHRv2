<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;

/**
 * AdminController
 *
 * Handles administrative functions including financial year management
 * and leave allocation.
 *
 * Place: backend/app/Controllers/AdminController.php
 */
class AdminController extends Controller
{
    /**
     * Display admin panel with financial year management
     * GET /admin
     */
    public function indexAction(): void
    {
        if (!$this->isAuthenticated()) {
            $this->redirect('login');
            return;
        }

        // Only admins, HR managers and super admins can access admin
        $userRole = $_SESSION['user_role'] ?? '';
        if (!in_array($userRole, ['super_admin', 'hr_manager', 'admin', 'administrator'])) {
            $_SESSION['flash_error'] = 'You do not have permission to access this resource.';
            $this->redirect('dashboard');
            return;
        }

        $conn = $this->getDbConnection();
        
        // Load notification service
        require_once __DIR__ . '/../../../notifications/NotificationService.php';
        $notificationService = new \NotificationService($conn);

        // Check financial year reminders
        $this->checkFinancialYearReminders($notificationService, $conn);

        // Get financial year status
        $fyStatus = $this->canCreateNewFinancialYear($conn);

        // Fetch data
        $financial_years = $conn->query("SELECT * FROM financial_years ORDER BY start_date DESC")?->fetch_all(MYSQLI_ASSOC) ?? [];
        $leave_types = $conn->query("SELECT * FROM leave_types ORDER BY id")?->fetch_all(MYSQLI_ASSOC) ?? [];
        $employees = $conn->query("SELECT id, CONCAT(first_name, ' ', last_name, IF(surname != '' AND surname IS NOT NULL, CONCAT(' ', surname), '')) as full_name FROM employees WHERE employee_status = 'active' ORDER BY first_name, last_name")?->fetch_all(MYSQLI_ASSOC) ?? [];

        $this->view('admin/index', [
            'financial_years' => $financial_years,
            'leave_types' => $leave_types,
            'employees' => $employees,
            'fy_status' => $fyStatus,
            'csrf_token' => $this->generateCsrfToken(),
        ]);
    }

    /**
     * Add financial year
     * POST /admin/financial-year/add
     */
    public function addFinancialYearAction(): void
    {
        $this->handleFinancialYearRequest('add');
    }

    /**defaultppens but theme 
     * Allocate leave to employee
     * POST /admin/leave/allocate
     */
    public function allocateLeaveAction(): void
    {
        $this->handleLeaveAllocation();
    }


    private function handleFinancialYearRequest(string $action): void
    {
        if (!$this->isAuthenticated()) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }
        
        $auth = \App\Helpers\Auth::getInstance();
        if (!$auth->hasPermission('hr_manager', 'view') && !$auth->hasPermission('super_admin', 'view')) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('admin');
            return;
        }

        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            $this->redirect('admin');
            return;
        }

        $conn = $this->getDbConnection();
        $fyStatus = $this->canCreateNewFinancialYear($conn);

        if (!$fyStatus['can_create']) {
            $_SESSION['flash_error'] = $fyStatus['reason'];
            $this->redirect('admin');
            return;
        }

        $next_fy = $fyStatus['next_fy'];
        $total_days = $this->calculateTotalDays($next_fy['start_date'], $next_fy['end_date']);

        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare("INSERT INTO financial_years (start_date, end_date, year_name, total_days, is_active, created_at) VALUES (?, ?, ?, ?, 1, NOW())");
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
            $stmt->bind_param("sssi", $next_fy['start_date'], $next_fy['end_date'], $next_fy['year_name'], $total_days);
            if (!$stmt->execute()) throw new Exception('Insert failed: ' . $conn->error);

            $financial_year_id = $conn->insert_id;
            $allocated_count = $this->allocateLeaveToAllEmployees($conn, $financial_year_id);

            // Trigger notification
            require_once __DIR__ . '/../../../notifications/NotificationService.php';
            $notificationService = new \NotificationService($conn);
            
            // Get all HR managers and super admins
            $userIds = [];
            $result = $conn->query("SELECT id FROM users WHERE role IN ('hr_manager', 'super_admin') AND id > 0");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $userId = (int)$row['id'];
                    if ($userId > 0) {
                        $userIds[] = $userId;
                    }
                }
            }
            
            $notificationService->triggerNotification('financial_year_created', [
                'fy_name' => $next_fy['year_name'],
                'fy_start' => $next_fy['start_date'],
                'fy_end' => $next_fy['end_date'],
                'allocated_count' => $allocated_count,
                'total_days' => $total_days,
                'created_by' => $_SESSION['user_name'],
                'created_at' => date('Y-m-d H:i:s'),
            ], $userIds);

            $conn->commit();
            $_SESSION['flash_message'] = "Financial year '{$next_fy['year_name']}' created. Leave allocated to {$allocated_count} employee-leave type combinations.";
            $_SESSION['flash_type'] = 'success';

        } catch (Exception $e) {
            if ($conn->connect_errno === 0) {
                $conn->rollback();
            }
            $_SESSION['flash_error'] = 'Error creating financial year: ' . $e->getMessage();
        }

        $this->redirect('admin');
    }

    private function handleLeaveAllocation(): void
    {
        if (!$this->isAuthenticated()) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }
        
        $auth = \App\Helpers\Auth::getInstance();
        if (!$auth->hasPermission('hr_manager', 'view') && !$auth->hasPermission('super_admin', 'view')) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('admin');
            return;
        }

        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid security token.';
            $this->redirect('admin');
            return;
        }

        $conn = $this->getDbConnection();
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $financial_year_id = (int)($_POST['financial_year_id'] ?? 0);
        $leave_types = isset($_POST['leave_types']) ? array_map('intval', $_POST['leave_types']) : null;

        $stmt = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name, IF(surname != '' AND surname IS NOT NULL, CONCAT(' ', surname), '')) as full_name FROM employees WHERE id = ? AND employee_status = 'active'");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $employee = $stmt->get_result()->fetch_assoc();

        if (!$employee) {
            $_SESSION['flash_error'] = 'Employee not found or not active.';
            $this->redirect('admin');
            return;
        }

        $result = $this->allocateLeaveToEmployee($conn, $employee_id, $financial_year_id, $leave_types);

        if (!empty($result['error'])) {
            $_SESSION['flash_error'] = "Allocation failed: " . htmlspecialchars($result['error']);
        } elseif ($result['allocated'] > 0) {
            $msg = "Leave allocated to {$employee['full_name']}: {$result['allocated']} new record(s).";
            if ($result['skipped'] > 0) $msg .= " {$result['skipped']} already existed and were skipped.";
            $_SESSION['flash_message'] = $msg;
            $_SESSION['flash_type'] = 'success';
        } elseif ($result['skipped'] > 0) {
            $_SESSION['flash_error'] = "All leave records for {$employee['full_name']} already exist for the selected financial year. Nothing new was added.";
        } else {
            $_SESSION['flash_error'] = "No leave allocated for {$employee['full_name']} (gender: '{$result['gender']}', employment: '{$result['employment']}'). No matching rules found — verify these values in the database.";
        }

        $this->redirect('admin');
    }

    private function checkFinancialYearReminders(\NotificationService $notificationService, \mysqli $conn): void
    {
        $month = (int)date('n');
        $day = (int)date('j');
        $year = (int)date('Y');

        $next_fy = [
            'start_date' => "{$year}-07-01",
            'end_date' => ($year + 1) . "-06-30",
            'year_name' => (string)$year . '/' . substr((string)($year + 1), 2),
        ];

        $stmt = $conn->prepare("SELECT id FROM financial_years WHERE year_name = ?");
        if (!$stmt) return;
        $stmt->bind_param("s", $next_fy['year_name']);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) return;

        $payload = [
            'next_fy_name' => $next_fy['year_name'],
            'next_fy_start' => $next_fy['start_date'],
            'next_fy_end' => $next_fy['end_date'],
            'current_month' => date('F'),
            'current_date' => date('Y-m-d'),
        ];

        // Get all HR managers and super admins
        $userIds = [];
        $result = $conn->query("SELECT id FROM users WHERE role IN ('hr_manager', 'super_admin') AND id > 0");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $userId = (int)$row['id'];
                if ($userId > 0) {
                    $userIds[] = $userId;
                }
            }
        }

        if ($month === 7 && $day === 1) {
            $notificationService->triggerNotification('financial_year_urgent', $payload, $userIds);
        } elseif ($month === 7 || $month === 6) {
            $notificationService->triggerNotification('financial_year_reminder', $payload, $userIds);
        }
    }

    private function canCreateNewFinancialYear(\mysqli $conn): array
    {
        $month = (int)date('n');
        $year = (int)date('Y');

        $next_fy = [
            'start_date' => "{$year}-07-01",
            'end_date' => ($year + 1) . "-06-30",
            'year_name' => (string)$year . '/' . substr((string)($year + 1), 2),
        ];

        $count_result = $conn->query("SELECT COUNT(*) as count FROM financial_years");
        $has_existing = $count_result && $count_result->fetch_assoc()['count'] > 0;

        $stmt = $conn->prepare("SELECT id FROM financial_years WHERE year_name = ?");
        if (!$stmt) return ['can_create' => false, 'reason' => 'Database error: ' . $conn->error, 'next_fy' => null];
        $stmt->bind_param("s", $next_fy['year_name']);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            return ['can_create' => false, 'reason' => "Financial year {$next_fy['year_name']} already exists.", 'next_fy' => null];
        }

        if (!$has_existing) {
            return ['can_create' => true, 'reason' => "No financial years exist. Creating initial year {$next_fy['year_name']}.", 'next_fy' => $next_fy];
        }

        if ($month === 7) {
            return ['can_create' => true, 'reason' => "It is July. You can create financial year {$next_fy['year_name']}.", 'next_fy' => $next_fy];
        }

        return ['can_create' => false, 'reason' => 'Financial years can only be created in July. Current month: ' . date('F') . '.', 'next_fy' => null];
    }

    private function calculateTotalDays(string $start_date, string $end_date): int
    {
        return (new \DateTime($end_date))->diff(new \DateTime($start_date))->days + 1;
    }

    private function allocateLeaveToAllEmployees(\mysqli $conn, int $financial_year_id): int
    {
        $allocated_count = 0;
        $in_transaction = false;

        try {
            $conn->begin_transaction();
            $in_transaction = true;

            $stmt = $conn->prepare("SELECT start_date FROM financial_years WHERE id = ?");
            if (!$stmt) throw new Exception("FY query failed: " . $conn->error);
            $stmt->bind_param("i", $financial_year_id);
            $stmt->execute();
            $new_fy = $stmt->get_result()->fetch_assoc();
            if (!$new_fy) throw new Exception("Financial year ID {$financial_year_id} not found");

            $prev_fy_id = $this->getPreviousFinancialYearId($conn, $new_fy['start_date']);

            $employees_result = $conn->query("SELECT id, gender, employment_type FROM employees WHERE employee_status = 'active'");
            if (!$employees_result) throw new Exception("Failed to fetch employees: " . $conn->error);
            $employees = $employees_result->fetch_all(MYSQLI_ASSOC);
            if (empty($employees)) throw new Exception("No active employees found");

            $check_stmt = $conn->prepare("SELECT id FROM employee_leave_balances WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?");
            $insert_stmt = $conn->prepare("INSERT INTO employee_leave_balances (employee_id, leave_type_id, financial_year_id, allocated_days, brought_forward_days, used_days, accumulated_days, remaining_days, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            if (!$check_stmt || !$insert_stmt) throw new Exception("Failed to prepare statements");

            foreach ($employees as $employee) {
                $emp_id = (int)$employee['id'];
                $gender = strtolower(trim($employee['gender'] ?? ''));
                $employment = strtolower(trim($employee['employment_type'] ?? ''));
                $rules = $this->resolveRulesForEmployee($gender, $employment);
                $bf_days = ($prev_fy_id !== null) ? $this->getBroughtForwardDays($conn, $emp_id, $prev_fy_id) : 0.0;

                foreach ($rules as $rule) {
                    $check_stmt->bind_param("iii", $emp_id, $rule['leave_type_id'], $financial_year_id);
                    $check_stmt->execute();
                    if ($check_stmt->get_result()->fetch_assoc()) continue;

                    $brought_forward = ($rule['leave_type_id'] === 1 && $employment === 'permanent') ? $bf_days : 0.0;
                    if ($this->insertLeaveBalance($conn, $insert_stmt, $emp_id, $rule, $financial_year_id, $brought_forward)) {
                        $allocated_count++;
                    }
                }
            }

            $conn->commit();
            return $allocated_count;

        } catch (Exception $e) {
            if ($in_transaction && $conn->connect_errno === 0) {
                $conn->rollback();
            }
            error_log("Leave Allocation Error: " . $e->getMessage());
            return 0;
        }
    }

    private function allocateLeaveToEmployee(\mysqli $conn, int $employee_id, int $financial_year_id, ?array $selected_leave_types = null): array
    {
        $allocated_count = 0;
        $in_transaction = false;

        try {
            $conn->begin_transaction();
            $in_transaction = true;

            $stmt = $conn->prepare("SELECT start_date FROM financial_years WHERE id = ?");
            if (!$stmt) throw new Exception("FY query failed: " . $conn->error);
            $stmt->bind_param("i", $financial_year_id);
            $stmt->execute();
            $new_fy = $stmt->get_result()->fetch_assoc();
            if (!$new_fy) throw new Exception("Financial year ID {$financial_year_id} not found");

            $stmt = $conn->prepare("SELECT id, gender, employment_type FROM employees WHERE id = ? AND employee_status = 'active'");
            if (!$stmt) throw new Exception("Employee query failed: " . $conn->error);
            $stmt->bind_param("i", $employee_id);
            $stmt->execute();
            $employee = $stmt->get_result()->fetch_assoc();
            if (!$employee) throw new Exception("Employee {$employee_id} not found or inactive");

            $gender = strtolower(trim($employee['gender'] ?? ''));
            $employment = strtolower(trim($employee['employment_type'] ?? ''));
            $rules = $this->resolveRulesForEmployee($gender, $employment);

            if ($selected_leave_types) {
                $rules = array_values(array_filter($rules, fn($r) => in_array($r['leave_type_id'], $selected_leave_types)));
            }

            if (empty($rules)) {
                throw new Exception("No applicable leave rules found for gender='{$gender}', employment='{$employment}'");
            }

            $prev_fy_id = $this->getPreviousFinancialYearId($conn, $new_fy['start_date']);
            $bf_days = $this->getBroughtForwardDays($conn, $employee_id, $prev_fy_id);

            $check_stmt = $conn->prepare("SELECT id FROM employee_leave_balances WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?");
            $insert_stmt = $conn->prepare("INSERT INTO employee_leave_balances (employee_id, leave_type_id, financial_year_id, allocated_days, brought_forward_days, used_days, accumulated_days, remaining_days, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            if (!$check_stmt || !$insert_stmt) throw new Exception("Failed to prepare statements");

            $skipped = 0;
            foreach ($rules as $rule) {
                $check_stmt->bind_param("iii", $employee_id, $rule['leave_type_id'], $financial_year_id);
                $check_stmt->execute();
                if ($check_stmt->get_result()->fetch_assoc()) {
                    $skipped++;
                    continue;
                }
                $brought_forward = ($rule['leave_type_id'] === 1 && $employment === 'permanent') ? $bf_days : 0.0;
                if ($this->insertLeaveBalance($conn, $insert_stmt, $employee_id, $rule, $financial_year_id, $brought_forward)) {
                    $allocated_count++;
                }
            }

            $conn->commit();
            return ['allocated' => $allocated_count, 'skipped' => $skipped, 'gender' => $gender, 'employment' => $employment];

        } catch (Exception $e) {
            if ($in_transaction && $conn->connect_errno === 0) {
                $conn->rollback();
            }
            error_log("Employee Leave Allocation Error: " . $e->getMessage());
            return ['allocated' => 0, 'skipped' => 0, 'error' => $e->getMessage()];
        }
    }

    private function getPreviousFinancialYearId(\mysqli $conn, string $new_fy_start): ?int
    {
        $stmt = $conn->prepare("SELECT id FROM financial_years WHERE end_date < ? ORDER BY end_date DESC LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param("s", $new_fy_start);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ? (int)$row['id'] : null;
    }

    private function getBroughtForwardDays(\mysqli $conn, int $employee_id, ?int $prev_fy_id): float
    {
        if (!$prev_fy_id) return 0.0;
        $stmt = $conn->prepare("SELECT remaining_days FROM employee_leave_balances WHERE leave_type_id = 1 AND financial_year_id = ? AND employee_id = ?");
        if (!$stmt) return 0.0;
        $stmt->bind_param("ii", $prev_fy_id, $employee_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ? min((float)$row['remaining_days'], 15.0) : 0.0;
    }

    private function insertLeaveBalance(\mysqli $conn, object $insert_stmt, int $emp_id, array $rule, int $financial_year_id, float $brought_forward): bool
    {
        $allocated = (float)$rule['allocated_days'];
        $used = 0.0;
        $accumulated = $allocated + $brought_forward;
        $remaining = $accumulated - $used;

        $insert_stmt->bind_param(
            "iiiddddd",
            $emp_id,
            $rule['leave_type_id'],
            $financial_year_id,
            $allocated,
            $brought_forward,
            $used,
            $accumulated,
            $remaining
        );
        return $insert_stmt->execute();
    }

    private function getLeaveRules(): array
    {
        return [
            ['leave_type_id' => 1, 'allocated_days' => 30, 'gender' => 'all', 'employment' => 'permanent'],
            ['leave_type_id' => 1, 'allocated_days' => 0, 'gender' => 'all', 'employment' => 'contract'],
            ['leave_type_id' => 2, 'allocated_days' => 10, 'gender' => 'all', 'employment' => 'all'],
            ['leave_type_id' => 3, 'allocated_days' => 120, 'gender' => 'female', 'employment' => 'all'],
            ['leave_type_id' => 3, 'allocated_days' => 0, 'gender' => 'male', 'employment' => 'all'],
            ['leave_type_id' => 4, 'allocated_days' => 10, 'gender' => 'male', 'employment' => 'all'],
            ['leave_type_id' => 4, 'allocated_days' => 0, 'gender' => 'female', 'employment' => 'all'],
            ['leave_type_id' => 5, 'allocated_days' => 10, 'gender' => 'all', 'employment' => 'all'],
            ['leave_type_id' => 6, 'allocated_days' => 0, 'gender' => 'all', 'employment' => 'all'],
            ['leave_type_id' => 7, 'allocated_days' => 10, 'gender' => 'all', 'employment' => 'all'],
            ['leave_type_id' => 8, 'allocated_days' => 0, 'gender' => 'all', 'employment' => 'all'],
            ['leave_type_id' => 9, 'allocated_days' => 0, 'gender' => 'all', 'employment' => 'all'],
        ];
    }

    private function ruleMatchesEmployee(array $rule, string $gender, string $employment): bool
    {
        $genderMatch = ($rule['gender'] === 'all' || $rule['gender'] === $gender);
        $employmentMatch = ($rule['employment'] === 'all' || $rule['employment'] === $employment);
        return $genderMatch && $employmentMatch;
    }

    private function getBestRuleForLeaveType(array $rules, int $leave_type_id, string $gender, string $employment): ?array
    {
        $candidates = array_filter($rules, fn($r) => $r['leave_type_id'] === $leave_type_id);
        $specific = null;
        $fallback = null;

        foreach ($candidates as $rule) {
            if (!$this->ruleMatchesEmployee($rule, $gender, $employment)) continue;

            $isSpecificGender = ($rule['gender'] !== 'all');
            $isSpecificEmployment = ($rule['employment'] !== 'all');

            if ($isSpecificGender || $isSpecificEmployment) {
                $specific = $rule;
            } else {
                $fallback = $rule;
            }
        }

        return $specific ?? $fallback;
    }

    private function resolveRulesForEmployee(string $gender, string $employment): array
    {
        $all_rules = $this->getLeaveRules();
        $leave_types = array_unique(array_column($all_rules, 'leave_type_id'));
        $resolved = [];

        foreach ($leave_types as $lt_id) {
            $rule = $this->getBestRuleForLeaveType($all_rules, $lt_id, $gender, $employment);
            if ($rule !== null) {
                $resolved[] = $rule;
            }
        }

        return $resolved;
    }
}

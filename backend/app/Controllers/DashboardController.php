<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * Dashboard Controller - Handles dashboard data and statistics.
 *
 * Provides operational widgets and analytics for the dashboard.
 */
class DashboardController extends BaseController
{
    /**
     * GET /api/dashboard - Get dashboard data and statistics.
     *
     * Phase 5: this endpoint is now strictly read-only. The previous
     * "embedded auto clock-out" side effect (a mutating GET) was removed;
     * missed clock-outs are handled exclusively by
     * AttendanceCloseService via backend/cron/auto_clockout.php and the
     * per-employee lazy reconcile in AttendanceController::dashboardAction.
     */
    public function indexAction(): void
    {
        $this->requirePermission('dashboard', 'view');

        $db = \db();
        $userId = $this->getUserId();
        $employee = $this->employeeRepository->findByUserId($userId);

        if (!$employee) {
            $this->success([
                'stats' => [
                    'total_employees' => 0,
                    'present_today' => 0,
                    'on_leave' => 0,
                    'pending_approvals' => 0,
                ],
                'attendance' => null,
                'departments' => null,
                'leave' => null,
            ]);
            return;
        }

        $employeeDbId = (int)$employee['id'];
        $today = date('Y-m-d');
        $currentMonth = date('Y-m');

        // Get dashboard statistics
        try {
            $totalEmployees = $this->getEmployeeCount();
            $attendanceToday = $this->getTodayAttendance();
            $onLeave = $this->getOnLeaveCount();
            $pendingApprovals = $this->getPendingApprovalsCount();
        } catch (\Throwable $e) {
            \logger()->error('Dashboard stats error', ['error' => $e->getMessage()]);
            $totalEmployees = 0;
            $attendanceToday = ['total' => 0, 'clocked_in' => 0, 'clocked_out' => 0];
            $onLeave = 0;
            $pendingApprovals = 0;
        }

        $data = [
            'stats' => [
                'totalEmployees' => $totalEmployees,
                'presentToday' => $attendanceToday['total'] ?? 0,
                'onLeave' => $onLeave,
                'pendingApprovals' => $pendingApprovals,
                'lateToday' => 0,
            ],
            'attendance' => null,
            'departments' => null,
            'leave' => null,
        ];

        $this->success($data);
    }

    /**
     * GET /api/dashboard/hr-insights - HR oversight insights widget data.
     *
     * Business rule (dashboard:hr_insights — see migration 046): this endpoint
     * aggregates org-wide oversight signals that only HR Manager, Managing
     * Director and Super Admin may see:
     *
     *   1. Contracts expired / expiring within the next 30 days
     *      (employees.contract_end_date, migration 009)
     *   2. Employees within 1 year of retirement (age >= 59, retirement at 60,
     *      not already marked retired — mirrors the Reports module rule)
     *   3. Leave applications stuck in any pending* stage for more than 7 days
     *   4. Employees rostered for leave in the current calendar month
     *      (leave_roster scheduled_month/scheduled_year)
     *   5. Today's attendance: who clocked in vs who did not
     *   6. Employees on approved leave today
     *
     * Read-only; every list is capped so the widget stays light.
     */
    public function hrInsightsAction(): void
    {
        $this->requirePermission('dashboard', 'hr_insights');

        $db = \db();
        $today = date('Y-m-d');
        $in30Days = date('Y-m-d', strtotime('+30 days'));
        $weekAgo = date('Y-m-d H:i:s', strtotime('-7 days'));
        $retireAge = 60;

        $insights = [
            'generated_at' => date('c'),
            'contracts_expired' => ['count' => 0, 'items' => []],
            'contracts_expiring' => ['count' => 0, 'items' => []],
            'retiring_soon' => ['count' => 0, 'items' => []],
            'leave_pending_over_week' => ['count' => 0, 'items' => []],
            'roster_current_month' => ['count' => 0, 'items' => []],
            'attendance_today' => ['clocked_in' => 0, 'not_clocked_in' => 0, 'items' => []],
            'on_leave_today' => ['count' => 0, 'items' => []],
        ];

        try {
            // 1a. Contracts already expired (active employees only)
            $insights['contracts_expired']['items'] = $this->fetchEmployeeRows(
                $db,
                "SELECT e.id, CONCAT_WS(' ', e.first_name, e.last_name) AS name,
                        e.position, d.name AS department_name,
                        e.contract_end_date AS end_date
                 FROM employees e
                 LEFT JOIN departments d ON d.id = e.department_id
                 WHERE e.employee_status = 'active'
                   AND e.contract_end_date IS NOT NULL
                   AND e.contract_end_date < ?
                 ORDER BY e.contract_end_date ASC
                 LIMIT 20",
                's', [$today]
            );
            $insights['contracts_expired']['count'] = (int) $db->fetchValue(
                "SELECT COUNT(*) FROM employees
                 WHERE employee_status = 'active'
                   AND contract_end_date IS NOT NULL
                   AND contract_end_date < ?",
                's', [$today]
            );

            // 1b. Contracts expiring within the next 30 days
            $insights['contracts_expiring']['items'] = $this->fetchEmployeeRows(
                $db,
                "SELECT e.id, CONCAT_WS(' ', e.first_name, e.last_name) AS name,
                        e.position, d.name AS department_name,
                        e.contract_end_date AS end_date
                 FROM employees e
                 LEFT JOIN departments d ON d.id = e.department_id
                 WHERE e.employee_status = 'active'
                   AND e.contract_end_date IS NOT NULL
                   AND e.contract_end_date BETWEEN ? AND ?
                 ORDER BY e.contract_end_date ASC
                 LIMIT 20",
                'ss', [$today, $in30Days]
            );
            $insights['contracts_expiring']['count'] = (int) $db->fetchValue(
                "SELECT COUNT(*) FROM employees
                 WHERE employee_status = 'active'
                   AND contract_end_date IS NOT NULL
                   AND contract_end_date BETWEEN ? AND ?",
                'ss', [$today, $in30Days]
            );

            // 2. Retirement within 1 year (age >= 59, not yet retired)
            $insights['retiring_soon']['items'] = $this->fetchEmployeeRows(
                $db,
                "SELECT e.id, CONCAT_WS(' ', e.first_name, e.last_name) AS name,
                        e.position, d.name AS department_name,
                        e.date_of_birth,
                        TIMESTAMPDIFF(YEAR, e.date_of_birth, ?) AS age
                 FROM employees e
                 LEFT JOIN departments d ON d.id = e.department_id
                 WHERE e.employee_status = 'active'
                   AND e.date_of_birth IS NOT NULL
                   AND TIMESTAMPDIFF(YEAR, e.date_of_birth, ?) >= ? - 1
                 ORDER BY e.date_of_birth ASC
                 LIMIT 20",
                'ssi', [$today, $today, $retireAge]
            );
            $insights['retiring_soon']['count'] = (int) $db->fetchValue(
                "SELECT COUNT(*) FROM employees
                 WHERE employee_status = 'active'
                   AND date_of_birth IS NOT NULL
                   AND TIMESTAMPDIFF(YEAR, date_of_birth, ?) >= ? - 1",
                'si', [$today, $retireAge]
            );

            // 3. Leave pending for more than one week (any pending* stage)
            $insights['leave_pending_over_week']['items'] = $this->fetchEmployeeRows(
                $db,
                "SELECT la.id, CONCAT_WS(' ', e.first_name, e.last_name) AS name,
                        d.name AS department_name, la.status, la.start_date,
                        la.end_date, la.applied_at,
                        DATEDIFF(NOW(), la.applied_at) AS days_pending
                 FROM leave_applications la
                 JOIN employees e ON e.id = la.employee_id
                 LEFT JOIN departments d ON d.id = e.department_id
                 WHERE la.status LIKE 'pending%'
                   AND la.applied_at <= ?
                 ORDER BY la.applied_at ASC
                 LIMIT 20",
                's', [$weekAgo]
            );
            $insights['leave_pending_over_week']['count'] = (int) $db->fetchValue(
                "SELECT COUNT(*) FROM leave_applications
                 WHERE status LIKE 'pending%' AND applied_at <= ?",
                's', [$weekAgo]
            );

            // 4. Roster: employees scheduled for leave in the current month
            $monthName = date('F');
            $year = (int) date('Y');
            $insights['roster_current_month']['items'] = $this->fetchEmployeeRows(
                $db,
                "SELECT e.id, CONCAT_WS(' ', e.first_name, e.last_name) AS name,
                        e.position, d.name AS department_name,
                        lr.scheduled_month, lr.scheduled_year
                 FROM leave_roster lr
                 JOIN employees e ON e.id = lr.employee_id
                   AND e.employee_status = 'active'
                 LEFT JOIN departments d ON d.id = e.department_id
                 WHERE lr.scheduled_month = ? AND lr.scheduled_year = ?
                 ORDER BY e.first_name ASC
                 LIMIT 50",
                'si', [$monthName, $year]
            );
            $insights['roster_current_month']['count'] = (int) $db->fetchValue(
                "SELECT COUNT(*) FROM leave_roster lr
                 JOIN employees e ON e.id = lr.employee_id
                   AND e.employee_status = 'active'
                 WHERE lr.scheduled_month = ? AND lr.scheduled_year = ?",
                'si', [$monthName, $year]
            );

            // 5. Attendance today: who clocked in
            $insights['attendance_today']['items'] = $this->fetchEmployeeRows(
                $db,
                "SELECT e.id, CONCAT_WS(' ', e.first_name, e.last_name) AS name,
                        e.position, d.name AS department_name,
                        MIN(a.clock_in) AS clock_in
                 FROM attendance a
                 JOIN employees e ON e.id = a.employee_id
                 LEFT JOIN departments d ON d.id = e.department_id
                 WHERE DATE(a.clock_in) = ?
                 GROUP BY e.id, e.first_name, e.last_name, e.position, d.name
                 ORDER BY clock_in ASC
                 LIMIT 50",
                's', [$today]
            );
            $insights['attendance_today']['clocked_in'] = (int) $db->fetchValue(
                "SELECT COUNT(DISTINCT a.employee_id) FROM attendance a
                 JOIN employees e ON e.id = a.employee_id
                 WHERE e.employee_status = 'active' AND DATE(a.clock_in) = ?",
                's', [$today]
            );

            // 6. Employees on approved leave today + "did not clock in" figure.
            $insights['on_leave_today']['items'] = $this->fetchEmployeeRows(
                $db,
                "SELECT e.id, CONCAT_WS(' ', e.first_name, e.last_name) AS name,
                        e.position, d.name AS department_name,
                        la.start_date, la.end_date
                 FROM leave_applications la
                 JOIN employees e ON e.id = la.employee_id
                 LEFT JOIN departments d ON d.id = e.department_id
                 WHERE la.status = 'approved'
                   AND la.start_date <= ? AND la.end_date >= ?
                   AND e.employee_status = 'active'
                 ORDER BY e.first_name ASC
                 LIMIT 50",
                'ss', [$today, $today]
            );
            $onLeaveToday = (int) $db->fetchValue(
                "SELECT COUNT(*) FROM leave_applications la
                 JOIN employees e ON e.id = la.employee_id
                 WHERE la.status = 'approved'
                   AND la.start_date <= ? AND la.end_date >= ?
                   AND e.employee_status = 'active'",
                'ss', [$today, $today]
            );
            $totalActive = (int) $db->fetchValue(
                "SELECT COUNT(*) FROM employees WHERE employee_status = 'active'"
            );
            $insights['on_leave_today']['count'] = $onLeaveToday;
            $insights['attendance_today']['on_leave'] = $onLeaveToday;
            $insights['attendance_today']['total_active'] = $totalActive;
            $insights['attendance_today']['not_clocked_in'] = max(
                0,
                $totalActive - $insights['attendance_today']['clocked_in'] - $onLeaveToday
            );
        } catch (\Throwable $e) {
            \logger()->error('HR insights error', ['error' => $e->getMessage()]);
        }

        $this->success($insights);
    }

    /**
     * Run a prepared statement and return all rows as an associative array.
     * Helper for hrInsightsAction — logs and returns [] on prepare failure.
     */
    private function fetchEmployeeRows(\App\Helpers\Database $db, string $sql, string $types = '', array $params = []): array
    {
        try {
            return $db->fetchAll($sql, $types, $params);
        } catch (\Throwable $e) {
            \logger()->error('HR insights query failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get all dashboard statistics.
     */
    public function statsAction(): void
    {
        $this->requirePermission('dashboard', 'view');

        $db = \db();

        try {
            $totalEmployees = $this->getEmployeeCount();
            $attendanceToday = $this->getTodayAttendance();
            $onLeave = $this->getOnLeaveCount();
            $pendingApprovals = $this->getPendingApprovalsCount();
        } catch (\Throwable $e) {
            \logger()->error('Dashboard stats error', ['error' => $e->getMessage()]);
            $totalEmployees = 0;
            $attendanceToday = ['total' => 0, 'clocked_in' => 0, 'clocked_out' => 0];
            $onLeave = 0;
            $pendingApprovals = 0;
        }

        $data = [
            'totalEmployees' => $totalEmployees,
            'presentToday' => $attendanceToday['total'] ?? 0,
            'onLeave' => $onLeave,
            'pendingApprovals' => $pendingApprovals,
            'lateToday' => 0,
        ];

        $this->success($data);
    }

    /**
     * Get today's attendance records.
     */
    public function attendanceTodayAction(): void
    {
        $this->requirePermission('attendance', 'view');

        $db = \db();
        $today = date('Y-m-d');

        $records = $db->fetchAll(
            "SELECT a.*, e.first_name, e.last_name, d.name as department_name, o.name as office_name
             FROM attendance a
             JOIN employees e ON a.employee_id = e.id
             LEFT JOIN departments d ON e.department_id = d.id
             LEFT JOIN offices o ON a.office_id = o.id
             WHERE DATE(a.clock_in) = ?
             ORDER BY a.clock_in DESC
             LIMIT 50",
            's',
            [$today]
        );

        $stats = [
            'total' => count($records),
            'clocked_in' => count(array_filter($records, fn($r) => $r['status'] === 'clocked_in')),
            'clocked_out' => count(array_filter($records, fn($r) => $r['status'] === 'clocked_out')),
            'late' => count(array_filter($records, fn($r) => $r['is_late'] == 1)),
        ];

        $this->success([
            'records' => $records,
            'stats' => $stats,
        ]);
    }

    /**
     * Get pending leave requests.
     */
    public function pendingLeavesAction(): void
    {
        $this->requirePermission('leave', 'view');

        $db = \db();
        $leaves = $db->fetchAll(
            "SELECT l.*, e.first_name, e.last_name, d.name as department_name, lt.name as leave_type_name
             FROM leave_applications l
             JOIN employees e ON l.employee_id = e.id
             LEFT JOIN departments d ON e.department_id = d.id
             JOIN leave_types lt ON l.leave_type_id = lt.id
             WHERE l.status = 'pending'
             ORDER BY l.applied_at DESC
             LIMIT 20"
        );

        $this->success($leaves);
    }

    /**
     * Get recent complaints.
     */
    public function recentComplaintsAction(): void
    {
        $this->requirePermission('complaints', 'view');

        $db = \db();
        $complaints = $db->fetchAll(
            "SELECT c.*, e.first_name, e.last_name, d.name as department_name, cc.name as category_name
             FROM complaints c
             JOIN employees e ON c.employee_id = e.id
             LEFT JOIN departments d ON e.department_id = d.id
             LEFT JOIN complaint_categories cc ON c.category_id = cc.id
             ORDER BY c.created_at DESC
             LIMIT 10"
        );

        $this->success($complaints);
    }

    /**
     * Get employee count by department.
     */
    public function employeeCountAction(): void
    {
        $this->requirePermission('employees', 'view');

        $data = $this->getDepartmentStats();
        $this->success([
            'total' => $this->getEmployeeCount(),
            'by_department' => $data,
        ]);
    }

    /**
     * Get recent notifications for the current user.
     */
    public function notificationsAction(): void
    {
        $userId = $this->getUserId();
        $notificationService = \App\Services\NotificationService::getInstance();

        $notifications = $notificationService->getUnreadNotifications($userId, 10);
        $unreadCount = $notificationService->getUnreadCount($userId);

        $this->success([
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    /**
     * Get total employee count.
     */
    private function getEmployeeCount(): int
    {
        $db = \db();
        return (int) $db->fetchValue(
            "SELECT COUNT(*) FROM employees WHERE employee_status = 'active' OR employee_status IS NULL"
        );
    }

    /**
     * Get today's attendance count.
     */
    private function getTodayAttendance(): array
    {
        $db = \db();
        $today = date('Y-m-d');

        $total = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM attendance WHERE DATE(clock_in) = ?",
            's',
            [$today]
        );

        $clockedIn = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM attendance WHERE DATE(clock_in) = ? AND status = 'clocked_in'",
            's',
            [$today]
        );

        return [
            'total' => $total,
            'clocked_in' => $clockedIn,
            'clocked_out' => $total - $clockedIn,
        ];
    }

    /**
     * Get employees currently on approved leave.
     */
    private function getOnLeaveCount(): int
    {
        $db = \db();
        $today = date('Y-m-d');
        return (int) $db->fetchValue(
            "SELECT COUNT(DISTINCT employee_id) FROM leave_applications
             WHERE status = 'approved' AND start_date <= ? AND end_date >= ?",
            'ss',
            [$today, $today]
        );
    }

    /**
     * Get pending approvals count.
     */
    private function getPendingApprovalsCount(): int
    {
        $db = \db();
        return (int) $db->fetchValue(
            "SELECT COUNT(*) FROM leave_applications WHERE status = 'pending'"
        );
    }

    /**
     * Get open complaints count.
     */
    private function getOpenComplaintsCount(): int
    {
        $db = \db();
        return (int) $db->fetchValue(
            "SELECT COUNT(*) FROM complaints WHERE status NOT IN ('resolved', 'closed')"
        );
    }

    /**
     * Get pending appraisals count.
     */
    private function getPendingAppraisalsCount(): int
    {
        $db = \db();
        return (int) $db->fetchValue(
            "SELECT COUNT(*) FROM employee_appraisals WHERE status = 'pending'"
        );
    }

    /**
     * Get active users count.
     */
    private function getActiveUsersCount(): int
    {
        $db = \db();
        return (int) $db->fetchValue(
            "SELECT COUNT(*) FROM users WHERE is_active = 1"
        );
    }

    /**
     * Get employee statistics by department.
     */
    private function getDepartmentStats(): array
    {
        $db = \db();
        return $db->fetchAll(
            "SELECT d.name as department, COUNT(e.id) as count
             FROM employees e
             LEFT JOIN departments d ON e.department_id = d.id
             WHERE (e.employee_status = 'active' OR e.employee_status IS NULL)
             GROUP BY d.name
             ORDER BY count DESC"
        );
    }

    /**
     * Get today's attendance rate.
     */
    private function getAttendanceRate(): float
    {
        $totalEmployees = $this->getEmployeeCount();
        if ($totalEmployees === 0) {
            return 0.0;
        }

        $today = date('Y-m-d');
        $db = \db();
        $attended = (int) $db->fetchValue(
            "SELECT COUNT(DISTINCT employee_id) FROM attendance WHERE DATE(clock_in) = ?",
            's',
            [$today]
        );

        return round(($attended / $totalEmployees) * 100, 2);
    }

    /**
     * GET /api/dashboard/charts/attendance - Get attendance chart data.
     */
    public function chartsAttendanceAction(): void
    {
        // HR-restricted — org-wide presence data (dashboard:hr_insights,
        // migration 046). Only hr_manager / managing_director / super_admin.
        $this->requirePermission('dashboard', 'hr_insights');

        $db = \db();
        $today = date('Y-m-d');

        $present = (int) $db->fetchValue(
            "SELECT COUNT(DISTINCT employee_id) FROM attendance WHERE DATE(clock_in) = ? AND status IN ('clocked_in', 'clocked_out')",
            's',
            [$today]
        );

        $late = (int) $db->fetchValue(
            "SELECT COUNT(DISTINCT employee_id) FROM attendance WHERE DATE(clock_in) = ? AND is_late = 1",
            's',
            [$today]
        );

        $absent = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM employees WHERE employee_status = 'active' OR employee_status IS NULL"
        ) - $present;

        $this->success([
            'present' => $present,
            'late' => $late,
            'absent' => max(0, $absent),
            'total' => $present + $late,
        ]);
    }

    /**
     * GET /api/dashboard/charts/departments - Get department chart data.
     */
    public function chartsDepartmentsAction(): void
    {
        // HR-restricted — org-wide headcount data (dashboard:hr_insights,
        // migration 046). Only hr_manager / managing_director / super_admin.
        $this->requirePermission('dashboard', 'hr_insights');

        $db = \db();
        $departments = $db->fetchAll(
            "SELECT d.name as department, COUNT(e.id) as count
             FROM employees e
             LEFT JOIN departments d ON e.department_id = d.id
             WHERE (e.employee_status = 'active' OR e.employee_status IS NULL)
             GROUP BY d.name
             ORDER BY count DESC"
        );

        $this->success([
            'total_departments' => count($departments),
            'departments' => $departments,
        ]);
    }

    /**
     * GET /api/dashboard/charts/leave - Get leave chart data.
     */
    public function chartsLeaveAction(): void
    {
        // HR-restricted — org-wide leave volumes (dashboard:hr_insights,
        // migration 046). Only hr_manager / managing_director / super_admin.
        $this->requirePermission('dashboard', 'hr_insights');

        $db = \db();
        $today = date('Y-m-d');

        $onLeave = (int) $db->fetchValue(
            "SELECT COUNT(DISTINCT employee_id) FROM leave_applications
             WHERE status = 'approved' AND start_date <= ? AND end_date >= ?",
            'ss',
            [$today, $today]
        );

        $pending = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM leave_applications WHERE status = 'pending'"
        );

        $this->success([
            'on_leave' => $onLeave,
            'pending' => $pending,
        ]);
    }
/**
     * GET /api/dashboard/strategic-performance - Strategic & Performance
     * overview dashboard computed from REAL database rows. Supports optional
     * financial_year_id and department_id filters. Lower-level heads are
     * scoped to their own department. No fabricated statistics.
     */
    public function strategicPerformanceAction(): void
    {
        $this->requirePermission('dashboard', 'view');
        $scope = \App\Helpers\OrgScope::current();
        $db = \App\Helpers\Database::getInstance()->getConnection();

        $params = [];
        $types  = '';
        $where  = '';

        $filterDept = isset($_GET['department_id']) && $_GET['department_id'] !== ''
            ? (int) $_GET['department_id'] : null;
        if (!$scope['is_hr'] && !$scope['is_super_admin'] && !$scope['is_pme_or_audit']) {
            $filterDept = $scope['department_id'] ?? null;
        }
        $filterFy = isset($_GET['financial_year_id']) && $_GET['financial_year_id'] !== ''
            ? (int) $_GET['financial_year_id'] : null;

        if ($filterDept !== null) {
            $where = 'WHERE c.department_id = ?';
            $params[] = $filterDept;
            $types   .= 'i';
        }
        if ($filterFy !== null) {
            $where = $where === '' ? 'WHERE c.financial_year_id = ?' : $where . ' AND c.financial_year_id = ?';
            $params[] = $filterFy;
            $types   .= 'i';
        }

        $cnt = function (string $sql, array $p = [], string $t = '') use ($db, $where, $params, $types): int {
            $full = $p === [] ? $sql : $sql; // params handled below
            $useParams = array_merge($params, $p);
            $useTypes  = $types . $t;
            $stmt = $db->prepare($full);
            if (!$stmt) {
                return 0;
            }
            if ($useParams) {
                $stmt->bind_param($useTypes, ...$useParams);
            }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return (int) ($row['c'] ?? 0);
        };

        $activeFy = null;
        $fyRes = $db->query("SELECT id, year_name, start_date, end_date FROM financial_years WHERE is_active = 1 ORDER BY start_date DESC LIMIT 1");
        if ($fyRes) {
            $activeFy = $fyRes->fetch_assoc();
        }

        $activePlan = null;
        $today = date('Y-m-d');
        $planRes = $db->query(
            "SELECT * FROM strategic_plan WHERE start_date <= '$today' AND end_date >= '$today' ORDER BY start_date DESC LIMIT 1"
        );
        if ($planRes && $planRes->num_rows > 0) {
            $activePlan = $planRes->fetch_assoc();
        } else {
            $planRes = $db->query("SELECT * FROM strategic_plan ORDER BY start_date DESC LIMIT 1");
            if ($planRes) {
                $activePlan = $planRes->fetch_assoc();
            }
        }

        // Department-scoped counts (join through performance_contracts where the
        // underlying table does not itself carry department/financial year).
        $contractSql   = "SELECT COUNT(*) AS c FROM performance_contracts c $where";
        $workplanSql   = "SELECT COUNT(*) AS c FROM workplan_objectives w JOIN performance_contracts c ON w.performance_contract_id = c.id $where";
        $kpiSql        = "SELECT COUNT(*) AS c FROM kpis k JOIN performance_contracts c ON k.performance_contract_id = c.id $where";

        $this->success([
            'active_financial_year' => $activeFy,
            'active_strategic_plan' => $activePlan,
            'filters_applied' => ['financial_year_id' => $filterFy, 'department_id' => $filterDept],
            'totals' => [
                'strategic_plans'       => $cnt('SELECT COUNT(*) AS c FROM strategic_plan'),
                'strategic_goals'       => $cnt('SELECT COUNT(*) AS c FROM goals'),
                'strategic_targets'     => $cnt('SELECT COUNT(*) AS c FROM strategic_targets'),
                'departments_aligned'   => $cnt('SELECT COUNT(DISTINCT department_id) AS c FROM strategic_targets WHERE department_id IS NOT NULL'),
                'performance_contracts' => $cnt($contractSql),
                'workplan_objectives'   => $cnt($workplanSql),
                'kpis'                  => $cnt($kpiSql),
                'sectional_objectives'  => $cnt('SELECT COUNT(*) AS c FROM performance_indicators WHERE is_active = 1'),
                'appraisals'            => $cnt('SELECT COUNT(*) AS c FROM employee_appraisals'),
            ],
        ]);
    }
}

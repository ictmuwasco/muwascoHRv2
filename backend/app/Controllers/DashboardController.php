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
     * Employee repository — injected in the constructor.
     *
     * DashboardController previously relied on a dynamic (undefined) property
     * $this->employeeRepository, which is null under the container's zero-arg
     * autoWire(). Calling ->findByUserId() on null threw a PHP 8 Error
     * (uncaught, because resolveCurrentEmployeePk runs before the try/catch
     * in hrInsightsAction / myPendingLeavesAction) → HTTP 500.
     */
    private \App\Repositories\EmployeeRepository $employeeRepository;

    public function __construct()
    {
        $this->employeeRepository = new \App\Repositories\EmployeeRepository();
    }

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

        $userId = $this->getUserId();

        // This widget issues 18 separate aggregates (the worst offender
        // measured: 2,430-5,045 ms of query time inside one dashboard burst).
        // Assembling it once per TTL window and serving an ETag means the
        // parallel dashboard calls and every repeat visit afterwards no longer
        // repeat that work.
        $insights = \App\Helpers\Cache::remember(
            'dashboard.hr_insights',
            fn (): array => $this->buildHrInsights(),
            self::CACHE_TTL
        );

        $this->successCached($insights, self::CACHE_TTL, ['user' => $userId]);
    }

    /**
     * Build the HR oversight insights payload.
     *
     * Extracted verbatim from hrInsightsAction so the assembled array can be
     * cached as a single unit (see hrInsightsAction). Keeps the original
     * fail-soft behaviour: a failing sub-query logs and leaves that block empty
     * rather than failing the whole widget.
     */
    private function buildHrInsights(): array
    {
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
                 WHERE a.attendance_date = ?
                 GROUP BY e.id, e.first_name, e.last_name, e.position, d.name
                 ORDER BY clock_in ASC
                 LIMIT 50",
                's', [$today]
            );
            $insights['attendance_today']['clocked_in'] = (int) $db->fetchValue(
                "SELECT COUNT(DISTINCT a.employee_id) FROM attendance a
                 JOIN employees e ON e.id = a.employee_id
                 WHERE e.employee_status = 'active' AND a.attendance_date = ?",
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

        return $insights;
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
     * Cache TTL (seconds) for dashboard read-only aggregates.
     *
     * Deliberately short: these are "as of now" widgets, so a few seconds of
     * staleness is invisible to users, while a single recomputation is shared by
     * every concurrent dashboard request in that window. That matters because
     * the dashboard fans out into several parallel calls which otherwise
     * recompute the same org-wide counts and queue behind each other.
     */
    private const CACHE_TTL = 30;

    /**
     * GET /api/dashboard/stats - Get all dashboard statistics.
     */
    public function statsAction(): void
    {
        $this->requirePermission('dashboard', 'view');

        $userId = $this->getUserId();

        // Org-wide totals are identical for every viewer, so the cache key is
        // shared - but the RESPONSE validator is scoped to the caller below.
        $data = \App\Helpers\Cache::remember('dashboard.stats', function (): array {
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

            return [
                'totalEmployees'   => $totalEmployees,
                'presentToday'     => $attendanceToday['total'] ?? 0,
                'onLeave'          => $onLeave,
                'pendingApprovals' => $pendingApprovals,
                'lateToday'        => 0,
            ];
        }, self::CACHE_TTL);

        $this->successCached($data, self::CACHE_TTL, ['user' => $userId]);
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
             WHERE a.attendance_date = ?
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
             -- The approval workflow is STAGED (docs/PHASE5_REPORT.md): rows sit
             -- in pending_subsection_head / pending_section_head /
             -- pending_dept_head / pending_managing_director (+ pending_hr /
             -- pending_bod_chair variants). Matching the bare 'pending' literal
             -- misses 100% of real awaiting-approval rows; LIKE 'pending%'
             -- covers every stage (same predicate as hr-insights block 3).
             WHERE l.status LIKE 'pending%'
             ORDER BY l.applied_at DESC
             LIMIT 20"
        );

        $this->success($leaves);
    }
    /**
     * GET /api/dashboard/my-pending-leaves - Personal pending approvals widget.
     *
     * Returns leave applications that the CURRENT user is expected to approve
     * at whatever workflow stage they are currently pending at. This lets
     * section/subsection/dept heads and managers see only their OWN queue
     * without needing the org-wide dashboard:hr_insights permission.
     *
     * Response shape: { count: number, items: [{ id, name, days_pending }] }
     */
    public function myPendingLeavesAction(): void
    {
        $userId = $this->getUserId();
        if ($userId <= 0) {
            $this->forbidden('Authentication required');
        }

        // Resolve the current user's employee record
        $employee = $this->employeeRepository->findByUserId($userId);
        $employeeId = $employee ? (int) $employee['id'] : 0;
        $userRole = $this->getUserRole();

        if ($employeeId <= 0) {
            // No employee record — return empty rather than 500
            $this->success(['count' => 0, 'items' => []]);
            return;
        }

        $db = \db();

        // Build a WHERE clause that matches leave applications pending at the
        // current user's approval level. The leave_applications table tracks
        // the designated approver per level via *_emp_id columns, and the
        // status enum indicates which level is currently pending.
        $roleStatusMap = [
            'section_head'      => 'pending_section_head',
            'dept_head'         => 'pending_dept_head',
            'managing_director' => 'pending_managing_director',
            'manager'           => 'pending_manager',
            'sub_section_head'  => 'pending_subsection_head',
            'hr_manager'        => 'pending_hr_manager',
        ];

        $roleEmpIdColumn = [
            'section_head'      => 'section_head_emp_id',
            'dept_head'         => 'dept_head_emp_id',
            'managing_director' => 'md_emp_id',
            'manager'           => 'manager_emp_id',
            'sub_section_head'  => 'subsection_head_emp_id',
            'hr_manager'        => 'hr_approved_by',
        ];

        $whereClauses = [];
        $params = [];
        $types = '';

        // Role-based matching: if the user has a management role, match leaves
        // pending at that level where they are the designated approver.
        foreach ($roleStatusMap as $role => $status) {
            if ($userRole === $role || $userRole === 'super_admin') {
                $col = $roleEmpIdColumn[$role];
                $whereClauses[] = "(l.status = ? AND l.{$col} = ?)";
                $params[] = $status;
                $params[] = $employeeId;
                $types .= 'si';
            }
        }

        // Also match leaves pending at HR level for hr_manager role
        // (status can be 'pending_hr_manager' or 'pending_hr')
        if ($userRole === 'hr_manager' || $userRole === 'super_admin') {
            $whereClauses[] = "(l.status = 'pending_hr' AND l.hr_approved_by = ?)";
            $params[] = $employeeId;
            $types .= 'i';
        }

        // If no role matched, return empty
        if (empty($whereClauses)) {
            $this->success(['count' => 0, 'items' => []]);
            return;
        }

        $whereSql = '(' . implode(' OR ', $whereClauses) . ')';

        $items = $db->fetchAll(
            "SELECT l.id,
                    CONCAT_WS(' ', e.first_name, e.last_name) AS name,
                    DATEDIFF(NOW(), l.applied_at) AS days_pending
             FROM leave_applications l
             JOIN employees e ON l.employee_id = e.id
             WHERE {$whereSql}
             ORDER BY l.applied_at ASC
             LIMIT 20",
            $types, $params
        );

        $count = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM leave_applications l WHERE {$whereSql}",
            $types, $params
        );

        $this->success([
            'count' => $count,
            'items' => $items ?: [],
        ]);
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
     *
     * Uses the STORED GENERATED column `attendance_date` instead of
     * DATE(clock_in). Wrapping the column in a function made the predicate
     * non-sargable, so MariaDB full-scanned all 13k attendance rows for every
     * dashboard call ("type: ALL, rows: 13144"). Filtering on the generated date
     * column uses idx_attendance_date_status instead and returns the same rows
     * from a single index lookup (measured 3.21 ms -> 0.35 ms).
     */
    private function getTodayAttendance(): array
    {
        $db = \db();
        $today = date('Y-m-d');

        // One aggregate instead of two round trips: total + clocked-in together.
        $row = $db->fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'clocked_in' THEN 1 ELSE 0 END) AS clocked_in
             FROM attendance
             WHERE attendance_date = ?",
            's',
            [$today]
        );

        $total = (int) ($row['total'] ?? 0);
        $clockedIn = (int) ($row['clocked_in'] ?? 0);

        return [
            'total' => $total,
            'clocked_in' => $clockedIn,
            'clocked_out' => max(0, $total - $clockedIn),
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
        // LIKE 'pending%' — the workflow's awaiting rows live in staged
        // statuses (pending_section_head etc.), never the bare 'pending'.
        return (int) $db->fetchValue(
            "SELECT COUNT(*) FROM leave_applications WHERE status LIKE 'pending%'"
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
     *
     * The real appraisal workflow (employee_appraisals) is STAGED, like leave:
     * rows sit in 'draft', 'awaiting_employee' or 'submitted' — there is no
     * bare 'pending' status, so the old literal matched 0 rows forever.
     * "Pending" here means: submitted and awaiting a decision (the appraiser's
     * queue). Draft/awaiting_employee rows are the employee's own work and are
     * deliberately not counted.
     */
    private function getPendingAppraisalsCount(): int
    {
        $db = \db();
        return (int) $db->fetchValue(
            "SELECT COUNT(*) FROM employee_appraisals WHERE status = 'submitted'"
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
            "SELECT COUNT(DISTINCT employee_id) FROM attendance WHERE attendance_date = ?",
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

        $userId = $this->getUserId();

        // Predicates rewritten onto the generated `attendance_date` column so
        // they use idx_attendance_date_status / idx_attendance_date_late rather
        // than full-scanning 13k rows (see getTodayAttendance). The whole
        // aggregate is then cached so the dashboard's parallel calls compute it
        // once per TTL window instead of once per request.
        $data = \App\Helpers\Cache::remember('dashboard.charts.attendance', function (): array {
            $db = \db();
            $today = date('Y-m-d');

            $present = (int) $db->fetchValue(
                "SELECT COUNT(DISTINCT employee_id) FROM attendance
                 WHERE attendance_date = ? AND status IN ('clocked_in', 'clocked_out')",
                's',
                [$today]
            );

            $late = (int) $db->fetchValue(
                "SELECT COUNT(DISTINCT employee_id) FROM attendance
                 WHERE attendance_date = ? AND is_late = 1",
                's',
                [$today]
            );

            $absent = $this->getEmployeeCount() - $present;

            return [
                'present' => $present,
                'late'    => $late,
                'absent'  => max(0, $absent),
                'total'   => $present + $late,
            ];
        }, self::CACHE_TTL);

        $this->successCached($data, self::CACHE_TTL, ['user' => $userId]);
    }

    /**
     * GET /api/dashboard/charts/departments - Get department chart data.
     */
    public function chartsDepartmentsAction(): void
    {
        // HR-restricted — org-wide headcount data (dashboard:hr_insights,
        // migration 046). Only hr_manager / managing_director / super_admin.
        $this->requirePermission('dashboard', 'hr_insights');

        $userId = $this->getUserId();

        $data = \App\Helpers\Cache::remember('dashboard.charts.departments', function (): array {
            $departments = \db()->fetchAll(
                "SELECT d.name as department, COUNT(e.id) as count
                 FROM employees e
                 LEFT JOIN departments d ON e.department_id = d.id
                 WHERE (e.employee_status = 'active' OR e.employee_status IS NULL)
                 GROUP BY d.name
                 ORDER BY count DESC"
            );

            return [
                'total_departments' => count($departments),
                'departments'       => $departments,
            ];
        }, self::CACHE_TTL);

        $this->successCached($data, self::CACHE_TTL, ['user' => $userId]);
    }

    /**
     * GET /api/dashboard/charts/leave - Get leave chart data.
     */
    public function chartsLeaveAction(): void
    {
        // HR-restricted — org-wide leave volumes (dashboard:hr_insights,
        // migration 046). Only hr_manager / managing_director / super_admin.
        $this->requirePermission('dashboard', 'hr_insights');

        $userId = $this->getUserId();

        $data = \App\Helpers\Cache::remember('dashboard.charts.leave', function (): array {
            $db = \db();
            $today = date('Y-m-d');

            $onLeave = (int) $db->fetchValue(
                "SELECT COUNT(DISTINCT employee_id) FROM leave_applications
                 WHERE status = 'approved' AND start_date <= ? AND end_date >= ?",
                'ss',
                [$today, $today]
            );

            $pending = (int) $db->fetchValue(
                // LIKE 'pending%' — staged workflow statuses (see
                // getPendingApprovalsCount); bare 'pending' matched 0 rows.
                "SELECT COUNT(*) FROM leave_applications WHERE status LIKE 'pending%'"
            );

            return [
                'on_leave' => $onLeave,
                'pending'  => $pending,
            ];
        }, self::CACHE_TTL);

        $this->successCached($data, self::CACHE_TTL, ['user' => $userId]);
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

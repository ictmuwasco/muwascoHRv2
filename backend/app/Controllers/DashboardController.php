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
     * GET /api/dashboard - Get dashboard data with embedded auto clock-out.
     * This endpoint also auto-clocks out employees from previous days.
     */
    public function indexAction(): void
    {
        $this->requirePermission('dashboard', 'view');

        // Auto clock-out any open sessions from previous days
        // This runs every time the dashboard is loaded, ensuring forgotten clock-outs are handled
        $this->autoClockOutPreviousDays();

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
     * Auto clock-out employees who forgot to clock out from previous days.
     * This is called automatically when the dashboard loads.
     */
    private function autoClockOutPreviousDays(): void
    {
        try {
            $db = \db();

            // Find all open sessions from previous days
            $openSessions = $db->fetchAll(
                "SELECT id, employee_id, clock_in
                FROM attendance
                WHERE clock_out IS NULL AND DATE(clock_in) < ?",
                's',
                [date('Y-m-d')]
            );

            if (!empty($openSessions)) {
                // Batch-close at end of each employee's attendance day
                // (Africa/Nairobi) and flag auto_clocked_out = 1 for HR
                // reporting. Mirrors AttendanceController::reconcileStaleSession()
                // and autoClockOutAction() so all three paths stay consistent.
                $db->query(
                    "UPDATE attendance
                    SET clock_out = DATE_FORMAT(clock_in, '%Y-%m-%d 23:59:59'),
                        status = 'auto_clocked_out',
                        auto_clocked_out = 1,
                        updated_at = NOW()
                    WHERE clock_out IS NULL AND DATE(clock_in) < ?",
                    's',
                    [date('Y-m-d')]
                );

                \logger()->info('Auto clock-out completed', [
                    'count' => count($openSessions),
                    'employee_ids' => array_column($openSessions, 'employee_id')
                ]);
            }
        } catch (\Throwable $e) {
            \logger()->error('Auto clock-out failed', ['error' => $e->getMessage()]);
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
        $this->requirePermission('dashboard', 'view');

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
        $this->requirePermission('dashboard', 'view');

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
        $this->requirePermission('dashboard', 'view');

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

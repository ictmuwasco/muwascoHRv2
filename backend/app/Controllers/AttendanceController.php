<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Attendance\AttendanceClockService;
use App\Services\Attendance\AttendanceCloseService;
use App\Services\Attendance\InvalidClockRequestException;
use App\Services\Attendance\InvalidOfficeException;
use App\Services\Attendance\NoOpenSessionException;
use App\Services\Attendance\OnApprovedLeaveException;
use App\Services\Attendance\OutsideGeofenceException;
use App\Services\AuditService;

/**
 * Attendance Controller - REST API for attendance management.
 *
 * Phase 5: HTTP responsibilities only — resolve session identity, shape the
 * request context, and map service outcomes/exceptions to responses.
 * All clock-in/out business rules live in
 * {@see \App\Services\Attendance\AttendanceClockService}; all stale-session
 * closing lives in {@see \App\Services\Attendance\AttendanceCloseService}
 * (shared with backend/cron/auto_clockout.php and DashboardController).
 *
 * The read endpoints below remain thin, permission-gated queries. Clock
 * routes are self-service: the employee identity ALWAYS comes from the
 * authenticated session, never from the request body.
 */
class AttendanceController extends BaseController
{
    private \App\Repositories\AttendanceRepository $attendanceRepository;
    private \App\Repositories\Contracts\EmployeeRepositoryInterface $employeeRepository;
    private AttendanceClockService $clockService;
    private AttendanceCloseService $closeService;

    public function __construct()
    {
        $this->attendanceRepository = new \App\Repositories\AttendanceRepository();
        $this->employeeRepository = new \App\Repositories\EmployeeRepository();
        $this->clockService = new AttendanceClockService();
        $this->closeService = new AttendanceCloseService();
    }

    /**
     * Write an attendance-scoped audit-trail entry via the central AuditService.
     *
     * @param string $action      AuditService ACTION_* constant.
     * @param string $status      AuditService STATUS_* constant.
     * @param string $description Human-readable description.
     * @param array  $options     Extra context (employee_id, office_id, lat/lng, …).
     * @return int|null Audit-log row id, or null on failure.
     */
    protected function auditAttendance(string $action, string $status, string $description, array $options = []): ?int
    {
        return AuditService::getInstance()->log(
            AuditService::MODULE_ATTENDANCE,
            $action,
            $description,
            array_merge($options, ['status' => $status])
        );
    }

    /**
     * Audit dashboard view / filter / export actions. Background auto-refresh
     * calls carry no audit signal and are deliberately not recorded to avoid
     * flooding the audit trail with noise.
     *
     * @param string $auditAction 'view' | 'export' | '' (absent = background call)
     * @param array  $params      Request parameters passed to getDashboard().
     * @param array  $result      Dashboard result (employees/pagination).
     */
    private function auditDashboardView(string $auditAction, array $params, array $result): void
    {
        $isExport = $auditAction === 'export';
        if (!$isExport && $auditAction !== 'view') {
            return;
        }

        $this->auditAttendance(
            $isExport ? AuditService::ACTION_EXPORT : AuditService::ACTION_VIEW,
            AuditService::STATUS_SUCCESS,
            $isExport ? 'Exported attendance dashboard (CSV)' : 'Viewed attendance dashboard',
            [
                'channel' => 'WEB',
                'metadata' => [
                    'date'          => $params['date'] ?? null,
                    'department_id' => $params['department_id'] ?? null,
                    'section_id'    => $params['section_id'] ?? null,
                    'status'        => $params['status'] ?? null,
                    'search'        => $params['search'] ?? null,
                    'page'          => $params['page'] ?? null,
                    'limit'         => $params['limit'] ?? null,
                    'row_count'     => isset($result['employees']) ? count($result['employees']) : null,
                    'total'         => $result['pagination']['total'] ?? null,
                    'is_export'     => $isExport,
                ],
            ]
        );
    }

    /**
     * POST /api/attendance/clock-in - Clock in with location validation.
     *
     * Expected JSON body:
     * {
     *   "office_id": 1,
     *   "latitude": -0.72809798,
     *   "longitude": 37.15159988,
     *   "accuracy": 20
     * }
     *
     * Business rules live in AttendanceClockService::clockIn(). Outcomes:
     *   200 created | idempotent retry · 400 invalid request/office ·
     *   403 OUTSIDE_RADIUS · 409 ON_APPROVED_LEAVE · 500 persistence failure.
     */
    public function clockInAction(): void
    {
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required to clock in');
            return;
        }

        $employee = $this->employeeRepository->findByUserId($userId);
        if (!$employee) {
            $this->notFound('Employee profile not found');
            return;
        }

        $request = $this->buildClockRequest($this->getJsonBody());
        $request['employee_db_id'] = (int) $employee['id'];

        try {
            $result = $this->clockService->clockIn((int) $employee['id'], $request);
        } catch (OutsideGeofenceException $e) {
            $ctx = $e->radiusContext();
            $this->json([
                'success' => false,
                'message' => $e->getMessage(),
                'distance' => $ctx['distance'],
                'allowed_radius' => $ctx['allowed_radius'],
                'code' => 'OUTSIDE_RADIUS',
            ], 403);
            return;
        } catch (OnApprovedLeaveException $e) {
            $this->error($e->getMessage(), 409, 'ON_APPROVED_LEAVE');
            return;
        } catch (InvalidClockRequestException | InvalidOfficeException $e) {
            $this->error($e->getMessage(), 400);
            return;
        } catch (\Throwable $e) {
            \logger()->error('Clock-in request failed', ['error' => $e->getMessage()]);
            $this->error('Failed to record your clock-in. Please try again.', 500);
            return;
        }

        $this->json($result['payload']);
    }

    /**
     * POST /api/attendance/clock-out - Clock out with location validation.
     *
     * Expected JSON body: same as clock-in.
     *
     * Business rules live in AttendanceClockService::clockOut(). Outcomes:
     *   200 created | idempotent retry · 400 invalid request/office ·
     *   400 NOT_CLOCKED_IN · 403 OUTSIDE_RADIUS · 500 persistence failure.
     */
    public function clockOutAction(): void
    {
        $userId = $this->getUserId();
        if ($userId === 0) {
            $this->unauthorized('Authentication required to clock out');
            return;
        }

        $employee = $this->employeeRepository->findByUserId($userId);
        if (!$employee) {
            $this->notFound('Employee profile not found');
            return;
        }

        $request = $this->buildClockRequest($this->getJsonBody());
        $request['employee_db_id'] = (int) $employee['id'];

        try {
            $result = $this->clockService->clockOut((int) $employee['id'], $request);
        } catch (OutsideGeofenceException $e) {
            $ctx = $e->radiusContext();
            $this->json([
                'success' => false,
                'message' => $e->getMessage(),
                'distance' => $ctx['distance'],
                'allowed_radius' => $ctx['allowed_radius'],
                'code' => 'OUTSIDE_RADIUS',
            ], 403);
            return;
        } catch (NoOpenSessionException $e) {
            $this->error($e->getMessage(), 400, null, ['code' => 'NOT_CLOCKED_IN']);
            return;
        } catch (InvalidClockRequestException | InvalidOfficeException $e) {
            $this->error($e->getMessage(), 400);
            return;
        } catch (\Throwable $e) {
            \logger()->error('Clock-out request failed', ['error' => $e->getMessage()]);
            $this->error('Failed to record your clock-out. Please try again.', 500);
            return;
        }

        $this->json($result['payload']);
    }

    /**
     * GET /api/attendance/dashboard - Per-employee clock-in card state.
     *
     * Also performs the per-employee lazy midnight reconciliation via
     * AttendanceCloseService (previously an inline controller query), so a
     * forgotten clock-out can never carry yesterday's clock-in into today.
     */
    public function dashboardAction(): void
    {
        $userId = $this->getUserId();
        $employee = $this->employeeRepository->findByUserId($userId);

        if (!$employee) {
            $this->success([
                'is_clocked_in' => false,
                'has_clocked_in_today' => false,
                'current_session' => null,
                'today_record' => null,
                'default_office' => null,
                'office_mode' => 'manual',
                'offices' => [],
            ]);
            return;
        }

        $db = \db();
        $today = date('Y-m-d');
        $employeeDbId = (int)$employee['id'];

        // Lazy midnight reconciliation for THIS employee (delegated to the
        // single close implementation shared with the scheduled cron job).
        $this->closeService->reconcileEmployee($employeeDbId);

        // Today's record is the single source of truth for today's state.
        $todayRecord = $db->fetchOne(
            "SELECT a.id, a.employee_id, a.office_id, a.clock_in, a.clock_out,
                    a.is_late, a.auto_clocked_out, a.status, a.created_at, a.updated_at,
                    o.name AS office_name
             FROM attendance a
             LEFT JOIN offices o ON a.office_id = o.id
             WHERE a.employee_id = ? AND DATE(a.clock_in) = ?
             ORDER BY a.clock_in DESC LIMIT 1",
            'is',
            [$employeeDbId, $today]
        );

        $hasClockedInToday = !empty($todayRecord);
        $isClockedIn = $hasClockedInToday
            && empty($todayRecord['clock_out'])
            && (string)$todayRecord['status'] !== 'clocked_out';

        // Employee's default/assigned office (State A: auto-select).
        $defaultOffice = null;
        $employeeOfficeId = $employee['office_id'] ?? null;
        if (!empty($employeeOfficeId)) {
            $defaultOffice = $db->fetchOne(
                "SELECT id, name, latitude, longitude, geo_fence_radius
                 FROM offices
                 WHERE id = ? AND latitude IS NOT NULL AND longitude IS NOT NULL",
                'i',
                [(int)$employeeOfficeId]
            );
        }

        // All recognised (geo-enabled) offices for manual/alternative selection.
        $offices = $db->fetchAll(
            "SELECT id, name, latitude, longitude, geo_fence_radius
             FROM offices
             WHERE latitude IS NOT NULL AND longitude IS NOT NULL
             ORDER BY name ASC"
        );

        // Office selector state machine:
        //  - 'default'     = State A (employee has an assigned office)
        //  - 'alternative' = State B (employee may pick another recognised office)
        //  - 'manual'      = State C (no assigned office -> must pick)
        $officeMode = $defaultOffice ? 'default' : 'manual';

        $this->success([
            'is_clocked_in' => $isClockedIn,
            'has_clocked_in_today' => $hasClockedInToday,
            'current_session' => $isClockedIn ? $todayRecord : null,
            'today_record' => $todayRecord,
            'default_office' => $defaultOffice,
            'office_mode' => $officeMode,
            'offices' => $offices,
        ]);
    }

    /**
     * GET /api/attendance/my-records - Get current user's attendance records.
     */
    public function myRecordsAction(): void
    {
        $this->requirePermission('attendance', 'view');

        $userId = $this->getUserId();
        $employee = $this->employeeRepository->findByUserId($userId);

        if (!$employee) {
            $this->success([]);
            return;
        }

        $employeeDbId = (int)$employee['id'];
        $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $_GET['end_date'] ?? date('Y-m-d');

        $db = \db();
        $records = $db->fetchAll(
            "SELECT a.*, e.first_name, e.last_name, d.name as department, o.name as office_name,
             DATE(a.clock_in) as date,
             TIME(a.clock_in) as clock_in_time,
             TIME(a.clock_out) as clock_out_time
             FROM attendance a
             JOIN employees e ON a.employee_id = e.id
             LEFT JOIN departments d ON e.department_id = d.id
             LEFT JOIN offices o ON a.office_id = o.id
             WHERE a.employee_id = ? AND DATE(a.clock_in) BETWEEN ? AND ?
             ORDER BY a.clock_in DESC
             LIMIT 500",
            'iss',
            [$employeeDbId, $startDate, $endDate]
        );

        $formattedRecords = array_map(function($record) {
            return [
                'id' => $record['id'],
                'employee_id' => $record['employee_id'],
                'employee_name' => trim($record['first_name'] . ' ' . $record['last_name']),
                'department' => $record['department'],
                'office_id' => $record['office_id'],
                'office_name' => $record['office_name'],
                'clock_in_office_id' => $record['clock_in_office_id'],
                'clock_out_office_id' => $record['clock_out_office_id'],
                'clock_in' => $record['clock_in'],
                'clock_in_time' => $record['clock_in_time'] ? date('g:i A', strtotime($record['clock_in_time'])) : null,
                'clock_out' => $record['clock_out'],
                'clock_out_time' => $record['clock_out_time'] ? date('g:i A', strtotime($record['clock_out_time'])) : null,
                'date' => $record['date'],
                'status' => ucfirst(str_replace('_', ' ', $record['status'])),
                'is_late' => (bool)$record['is_late'],
                'auto_clocked_out' => (bool)$record['auto_clocked_out'],
                'lat' => $record['lat'],
                'lng' => $record['lng'],
                'accuracy' => $record['accuracy'],
                'device_fingerprint' => $record['device_fingerprint'],
                'created_at' => $record['created_at'],
                'updated_at' => $record['updated_at'],
            ];
        }, $records);

        $this->success($formattedRecords);
    }

    /**
     * GET /api/attendance/employee/{employeeId} - Get employee attendance.
     *
     * Phase 5: now reads through AttendanceRepository directly — the stale
     * AttendanceService layer (which targeted a schema that no longer exists)
     * was retired. The employee-existence rule is preserved.
     *
     * DATA SCOPE: the target employee must be inside the caller's attendance
     * scope — own records for officers/employees, own unit for heads,
     * org-wide for HR/super admin/MD (IDOR guard, §23).
     */
    public function byEmployeeAction(int $employeeId): void
    {
        $this->requirePermission('attendance', 'view');

        $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $_GET['end_date'] ?? date('Y-m-d');

        $target = $this->employeeRepository->findById($employeeId);
        if (!$target) {
            $this->error('Employee not found', 400);
            return;
        }

        if (!\App\Helpers\OrgScope::attendanceEmployeeAllowed(
            \App\Helpers\OrgScope::current(),
            $this->resolveOwnEmployeeDbId(),
            $target
        )) {
            $this->error("You are not authorised to view this employee's attendance.", 403);
            return;
        }

        $records = $this->attendanceRepository->getByEmployeeAndDateRange($employeeId, $startDate, $endDate);
        $this->success($records);
    }

    /**
     * The caller's own employees.id (DB PK), or null when the session user has
     * no employee profile. Feeds the attendance data-scope helpers.
     */
    private function resolveOwnEmployeeDbId(): ?int
    {
        $userId = $this->getUserId();
        if ($userId === 0) {
            return null;
        }
        $employee = $this->employeeRepository->findByUserId($userId);
        return $employee ? (int) $employee['id'] : null;
    }

    /**
     * Column map used by OrgScope::attendanceWhere() for the standard
     * attendance + employees join used across these read endpoints.
     */
    private function attendanceColumnMap(): array
    {
        return [
            'employee_id'   => 'a.employee_id',
            'department_id' => 'e.department_id',
            'section_id'    => 'e.section_id',
            'subsection_id' => 'e.subsection_id',
        ];
    }

    /**
     * GET /api/attendance - Get attendance records.
     *
     * DATA SCOPE (Phase: role/page/permission restriction): officers and
     * employees see ONLY their own records; heads are pinned to their own
     * organisational unit (department/section/subsection); HR, super admin
     * and the Managing Director see organisation-wide. The scope is derived
     * exclusively from the authenticated session/employee record — request
     * parameters can never widen it.
     */
    public function indexAction(): void
    {
        $this->requirePermission('attendance', 'view');

        $db = \db();

        [$scopeWhere, $scopeParams] = \App\Helpers\OrgScope::attendanceWhere(
            \App\Helpers\OrgScope::current(),
            $this->resolveOwnEmployeeDbId(),
            $this->attendanceColumnMap()
        );

        $sql = "SELECT a.*, e.first_name, e.last_name, d.name as department, o.name as office_name,
             DATE(a.clock_in) as date,
             TIME(a.clock_in) as clock_in_time,
             TIME(a.clock_out) as clock_out_time
             FROM attendance a
             JOIN employees e ON a.employee_id = e.id
             LEFT JOIN departments d ON e.department_id = d.id
             LEFT JOIN offices o ON a.office_id = o.id
             WHERE {$scopeWhere}
             ORDER BY a.clock_in DESC
             LIMIT 500";

        $records = $scopeParams
            ? $db->fetchAll($sql, str_repeat('i', count($scopeParams)), $scopeParams)
            : $db->fetchAll($sql);

        // Format the data for the frontend
        $formattedRecords = array_map(function($record) {
            return [
                'id' => $record['id'],
                'employee_id' => $record['employee_id'],
                'employee_name' => trim($record['first_name'] . ' ' . $record['last_name']),
                'department' => $record['department'],
                'office_id' => $record['office_id'],
                'office_name' => $record['office_name'],
                'clock_in_office_id' => $record['clock_in_office_id'],
                'clock_out_office_id' => $record['clock_out_office_id'],
                'clock_in' => $record['clock_in'],
                'clock_in_time' => $record['clock_in_time'] ? date('g:i A', strtotime($record['clock_in_time'])) : null,
                'clock_out' => $record['clock_out'],
                'clock_out_time' => $record['clock_out_time'] ? date('g:i A', strtotime($record['clock_out_time'])) : null,
                'date' => $record['date'],
                'status' => ucfirst(str_replace('_', ' ', $record['status'])),
                'is_late' => (bool)$record['is_late'],
                'auto_clocked_out' => (bool)$record['auto_clocked_out'],
                'lat' => $record['lat'],
                'lng' => $record['lng'],
                'accuracy' => $record['accuracy'],
                'device_fingerprint' => $record['device_fingerprint'],
                'created_at' => $record['created_at'],
                'updated_at' => $record['updated_at'],
            ];
        }, $records);

        $this->success($formattedRecords);
    }

    /**
     * GET /api/attendance/today - Get today's attendance.
     *
     * DATA SCOPE: same pinning as indexAction() — own records for officers,
     * own unit for heads, org-wide only for HR/super admin/MD.
     */
    public function todayAction(): void
    {
        $this->requirePermission('attendance', 'view');

        $db = \db();
        $today = date('Y-m-d');

        [$scopeWhere, $scopeParams] = \App\Helpers\OrgScope::attendanceWhere(
            \App\Helpers\OrgScope::current(),
            $this->resolveOwnEmployeeDbId(),
            $this->attendanceColumnMap()
        );

        $sql = "SELECT a.*, e.first_name, e.last_name, d.name as department, o.name as office_name
             FROM attendance a
             JOIN employees e ON a.employee_id = e.id
             LEFT JOIN departments d ON e.department_id = d.id
             LEFT JOIN offices o ON a.office_id = o.id
             WHERE DATE(a.clock_in) = ? AND {$scopeWhere}
             ORDER BY a.clock_in DESC
             LIMIT 50";

        $types  = 's' . str_repeat('i', count($scopeParams));
        $params = array_merge([$today], $scopeParams);

        $records = $db->fetchAll($sql, $types, $params);

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
     * GET /api/attendance/hr-dashboard - Organisation-wide attendance monitoring.
     *
     * Powers the HR "Attendance Dashboard" page. All statuses are computed
     * server-side by AttendanceDashboardService (single source of truth);
     * the frontend only renders what this endpoint returns.
     *
     * NOTE: distinct from /attendance/dashboard, which is the per-employee
     * clock-in card state endpoint.
     *
     * Authorization + DATA SCOPE (Phase: role/page/permission restriction):
     *   - requires attendance:manage (org monitoring is an administrative
     *     function — plain officers/employees are rejected);
     *   - unit-scoped heads get their department/section/subsection filters
     *     CLAMPED server-side to their own organisational unit: client-
     *     submitted department_id/section_id values are ignored, never
     *     trusted.
     *
     * Parameters:
     *   date           Y-m-d            (default today)
     *   department_id  int              scope: summary+rows (clamped for heads)
     *   section_id     int              scope: summary+rows (clamped for heads)
     *   status         STATUS constant  row filter
     *   search         string           name / staff no row filter
     *   page, limit    pagination of the employee table
     *   trend_days     1-31             trailing trend window (default 7)
     */
    public function hrDashboardAction(): void
    {
        $this->requirePermission('attendance', 'manage');

        try {
            $scope = \App\Helpers\OrgScope::current();

            // Own-scope callers (officers/employees) must never enumerate the
            // roster through the monitoring dashboard.
            if (\App\Helpers\OrgScope::attendanceReadMode($scope) === \App\Helpers\OrgScope::ATTENDANCE_SCOPE_OWN) {
                $this->error('You are not authorised to view the attendance monitoring dashboard.', 403);
                return;
            }

            $service = new \App\Services\AttendanceDashboardService();

            $params = [
                'date'          => $_GET['date'] ?? null,
                'trend_days'    => isset($_GET['trend_days']) ? (int)$_GET['trend_days'] : 7,
                'department_id' => (isset($_GET['department_id']) && $_GET['department_id'] !== '')
                                    ? (int)$_GET['department_id'] : null,
                'section_id'    => (isset($_GET['section_id']) && $_GET['section_id'] !== '')
                                    ? (int)$_GET['section_id'] : null,
                'subsection_id' => (isset($_GET['subsection_id']) && $_GET['subsection_id'] !== '')
                                    ? (int)$_GET['subsection_id'] : null,
                'status'        => $_GET['status'] ?? null,
                'search'        => $this->getSearchQuery(),
                'page'          => max(1, (int)($_GET['page'] ?? 1)),
                'limit'         => (int)($_GET['limit'] ?? 25),
            ];

            // DATA SCOPE clamp for heads — server-resolved unit only.
            if (\App\Helpers\OrgScope::attendanceReadMode($scope) === \App\Helpers\OrgScope::ATTENDANCE_SCOPE_UNIT) {
                if (empty($scope['department_id'])) {
                    // Unit could not be resolved — deny rather than expose
                    // organisation-wide monitoring data.
                    $this->error('Your organisational unit could not be resolved.', 403);
                    return;
                }
                $params['department_id'] = (int) $scope['department_id'];
                $params['section_id'] = (!empty($scope['is_section_head']) || !empty($scope['is_sub_section_head']))
                    && !empty($scope['section_id'])
                    ? (int) $scope['section_id'] : null;
                $params['subsection_id'] = !empty($scope['is_sub_section_head']) && !empty($scope['subsection_id'])
                    ? (int) $scope['subsection_id'] : null;
            }

            $result = $service->getDashboard($params);

            // Record a user-initiated view / filter / export. Background
            // auto-refresh calls omit the "audit" flag and are not logged.
            $this->auditDashboardView($_GET['audit'] ?? '', $params, $result);

            $this->success($result);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (\Throwable $e) {
            error_log('[hrDashboard] ' . $e->getMessage());
            $this->error('Failed to load attendance dashboard data', 500);
        }
    }

    /**
     * GET /api/attendance/hr-employee-history - Employee profile + recent history
     * for the dashboard detail modal. Uses the live schema directly because the
     * legacy /attendance/employee/{id} path relies on retired repository SQL.
     *
     * DATA SCOPE: the target employee must be inside the caller's attendance
     * scope — own records for officers/employees, own unit for heads,
     * org-wide for HR/super admin/MD (IDOR guard, §23).
     */
    public function hrEmployeeHistoryAction(): void
    {
        $this->requirePermission('attendance', 'view');

        $employeeId = (int)($_GET['employee_id'] ?? 0);
        if ($employeeId <= 0) {
            $this->error('employee_id is required', 400);
        }

        $target = $this->employeeRepository->findById($employeeId);
        if (!$target) {
            $this->error('Employee not found', 404);
            return;
        }

        if (!\App\Helpers\OrgScope::attendanceEmployeeAllowed(
            \App\Helpers\OrgScope::current(),
            $this->resolveOwnEmployeeDbId(),
            $target
        )) {
            $this->error("You are not authorised to view this employee's attendance history.", 403);
            return;
        }

        try {
            $service = new \App\Services\AttendanceDashboardService();
            $result = $service->getEmployeeHistory(
                $employeeId,
                (string)($_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'))),
                (string)($_GET['end_date'] ?? date('Y-m-d')),
                (int)($_GET['limit'] ?? 30)
            );

            $this->auditAttendance(
                AuditService::ACTION_VIEW,
                AuditService::STATUS_SUCCESS,
                'Viewed employee attendance history',
                [
                    'target_type' => 'Employee',
                    'target_id'   => $employeeId,
                    'channel'     => 'WEB',
                    'metadata'    => [
                        'employee_id' => $employeeId,
                        'start_date'  => $_GET['start_date'] ?? null,
                        'end_date'    => $_GET['end_date'] ?? null,
                    ],
                ]
            );

            $this->success($result);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 404);
        } catch (\Throwable $e) {
            error_log('[hrEmployeeHistory] ' . $e->getMessage());
            $this->error('Failed to load employee attendance history', 500);
        }
    }

    /**
     * POST /api/attendance/auto-clockout - Close all previous-day open sessions.
     *
     * Intended for the scheduled task (backend/cron/auto_clockout.php) and
     * manual ops triggers. Delegates to AttendanceCloseService — the single
     * implementation of the missed clock-out rule (Phase 5 §12). Idempotent:
     * repeated calls find nothing left to close.
     */
    public function autoClockOutAction(): void
    {
        $result = $this->closeService->closeStaleOpenSessions();

        $this->json([
            'success' => true,
            'message' => $result['closed'] > 0
                ? "Auto clock-out completed. {$result['closed']} employee(s) clocked out."
                : 'No open sessions found.',
            'auto_clocked_out' => $result['closed'],
        ]);
    }

    /**
     * Shape the HTTP clock request into the context passed to the domain
     * service. The employee identity is NEVER taken from the body — only
     * office/coordinates/network context live here.
     *
     * @return array<string,mixed>
     */
    private function buildClockRequest(array $data): array
    {
        return [
            'office_id' => (int) ($data['office_id'] ?? 0),
            'location_status' => (string) ($data['location_status'] ?? ''),
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'accuracy' => $data['accuracy'] ?? null,
            'ip_address' => $this->clientIp(),
            'channel' => 'WEB',
        ];
    }

    /**
     * Best-effort client IP for the audit trail. Captured on every record -
     * GPS-verified or not - so HR always has network-origin evidence
     * (office workstations share the office public IP).
     */
    private function clientIp(): ?string
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        $ip = $forwarded !== ''
            ? trim(explode(',', $forwarded)[0])
            : ($_SERVER['REMOTE_ADDR'] ?? '');

        return ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) ? $ip : null;
    }
}

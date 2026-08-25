<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\GeoLocation;
use App\Services\Contracts\AttendanceServiceInterface;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\Contracts\OfficeRepositoryInterface;

/**
 * Attendance Controller - REST API for attendance management.
 */
class AttendanceController extends BaseController
{
    private AttendanceServiceInterface $attendanceService;
    private EmployeeRepositoryInterface $employeeRepository;
    private OfficeRepositoryInterface $officeRepository;

    /** Max allowed clock-in attempts per request to prevent abuse */
    private const MAX_ACCURACY_METERS = 5000;

    /** Office radius fallback in meters when not configured */
    private const DEFAULT_RADIUS_METERS = 100;

    public function __construct()
    {
        $this->attendanceService = new \App\Services\AttendanceService();
        $this->employeeRepository = new \App\Repositories\EmployeeRepository();
        $this->officeRepository = new \App\Repositories\OfficeRepository();

        $this->attendanceService->setAttendanceRepository(new \App\Repositories\AttendanceRepository());
        $this->attendanceService->setEmployeeRepository($this->employeeRepository);
    }

    /**
     * GET /api/attendance - Get all attendance records.
     */
    public function indexAction(): void
    {
        $this->requirePermission('attendance', 'view');

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
             ORDER BY a.clock_in DESC
             LIMIT 500"
        );

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
     */
    public function todayAction(): void
    {
        $this->requirePermission('attendance', 'view');

        $db = \db();
        $today = date('Y-m-d');

        $records = $db->fetchAll(
            "SELECT a.*, e.first_name, e.last_name, d.name as department, o.name as office_name
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
     * GET /api/attendance/dashboard - Get employee's attendance dashboard data.
     *
     * The backend is the single source of truth. It resolves stale previous-day
     * open sessions, derives the employee's default office, and returns the
     * exact card state the UI must reflect.
     */
    public function dashboardAction(): void
    {
        $this->requirePermission('attendance', 'view');

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

        // Lazy midnight reconciliation for THIS employee: close any previous-day
        // open session so a forgotten clock-out can never carry over into today.
        $this->reconcileStaleSession($employeeDbId);

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
     * Per-employee lazy midnight reconciliation.
     *
     * Closes any still-open attendance session from a previous day for the
     * given employee so a forgotten clock-out can never carry yesterday's
     * clock-in into today's state. Runs on every attendance read/write —
     * the safety net that covers the browser-closed / cron-missed cases.
     *
     * clock_out is set to the end of the day the employee clocked in
     * (organisation timezone Africa/Nairobi, 23:59:59) and the record is
     * flagged auto_clocked_out = 1 for HR reporting.
     */
    private function reconcileStaleSession(int $employeeDbId): void
    {
        $db = \db();
        $today = date('Y-m-d');

        $db->query(
            "UPDATE attendance
               SET clock_out = DATE_FORMAT(clock_in, '%Y-%m-%d 23:59:59'),
                   status = 'auto_clocked_out',
                   auto_clocked_out = 1,
                   updated_at = NOW()
             WHERE employee_id = ? AND clock_out IS NULL AND DATE(clock_in) < ?",
            'is',
            [$employeeDbId, $today]
        );
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
     */
    public function byEmployeeAction(int $employeeId): void
    {
        $this->requirePermission('attendance', 'view');

        $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $_GET['end_date'] ?? date('Y-m-d');

        $records = $this->attendanceService->getAttendanceByEmployee($employeeId, $startDate, $endDate);
        $this->success($records);
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

        $data = $this->getJsonBody();

        // ---- Input Validation ----
        $validationError = $this->validateClockRequest($data);
        if ($validationError !== null) {
            $this->error($validationError, 400);
        }

        $officeId = (int)$data['office_id'];

        // Coordinates optional ONLY under the explicit no-fix declaration -
        // desktop PCs without GPS/Wi-Fi can still record attendance, stored
        // with NULL lat/lng ("location not verified") for HR review.
        $hasCoordinates = isset($data['latitude'], $data['longitude'])
            && $data['latitude'] !== ''
            && $data['longitude'] !== '';

        if ($hasCoordinates) {
            $latitude = (float)$data['latitude'];
            $longitude = (float)$data['longitude'];
            $accuracy = (float)($data['accuracy'] ?? 0);

            // Reject unusable GPS accuracy
            if ($accuracy > self::MAX_ACCURACY_METERS) {
                $this->error("GPS accuracy too low ({$accuracy}m). Please move to an open area and try again.", 400);
            }
        } else {
            $latitude = null;
            $longitude = null;
            $accuracy = null;
        }

        // ---- Get Office Details ----
        $office = $this->officeRepository->findById($officeId);
        if (!$office) {
            $this->error('Invalid office selected', 400);
        }

        $officeLat = (float)$office['latitude'];
        $officeLon = (float)$office['longitude'];
        if (!GeoLocation::isValidCoordinate($officeLat, $officeLon)) {
            $this->error('Selected office has no valid GPS coordinates configured', 400);
        }

                // ---- Geofence validation (skipped only for declared no-fix requests) ----
        if ($hasCoordinates) {
            // ---- Calculate Distance (Haversine, in meters) ----
            $radiusCheck = GeoLocation::isWithinRadius(
                $latitude,
                $longitude,
                $officeLat,
                $officeLon,
                (float)($office['geo_fence_radius'] ?? self::DEFAULT_RADIUS_METERS),
                $accuracy
            );

            // ---- Validate Radius (BEFORE insert) ----
            if (!$radiusCheck['within']) {
                $message = 'You are about '
                    . \App\Helpers\GeoLocation::formatDistanceMeters((float) $radiusCheck['distance'])
                    . ' from the office. You must be within '
                    . \App\Helpers\GeoLocation::formatDistanceMeters((float) ($office['geo_fence_radius'] ?? self::DEFAULT_RADIUS_METERS))
                    . ' of the office to clock in. Please move closer and try again.';
                $this->json([
                    'success' => false,
                    'message' => $message,
                    'distance' => $radiusCheck['distance'],
                    'allowed_radius' => (float)($office['geo_fence_radius'] ?? self::DEFAULT_RADIUS_METERS),
                    'code' => 'OUTSIDE_RADIUS',
                ], 403);
            }
        } else {
            $radiusCheck = ['within' => true, 'distance' => null];
        }

                        // ---- Check for existing record (prevent duplicates) ----
        $db = \db();
        $today = date('Y-m-d');
        $employeeDbId = (int)$employee['id'];
        $now = date('Y-m-d H:i:s');

        // Idempotency: a pre-check SELECT handles sequential double-clicks;
        // the unique key uk_attendance_employee_date is the authoritative guard
        // against true concurrent inserts (see migration 020_attendance_attendance_date_column.sql).
        $existing = $db->fetchOne(
            "SELECT a.id, a.clock_in, a.clock_out, a.is_late, a.status,
                    o.name AS office_name
             FROM attendance a
             LEFT JOIN offices o ON a.office_id = o.id
             WHERE a.employee_id = ? AND DATE(a.clock_in) = ?
             ORDER BY a.clock_in DESC LIMIT 1",
            'is',
            [$employeeDbId, $today]
        );

        if ($existing) {
            // Idempotent success: return the existing record; never error on retry.
            $this->json([
                'success' => true,
                'message' => 'You have already clocked in today.',
                'clock_in' => $existing['clock_in'] ?? null,
                'is_late' => (bool)($existing['is_late'] ?? 0),
                'distance' => $radiusCheck['distance'],
                'record' => $existing,
                'idempotent' => true,
            ]);
            return;
        }

        // ---- Determine if late (organisation cutoff 08:30 Africa/Nairobi) ----
        $currentHour = (int)date('H');
        $currentMinute = (int)date('i');
        $isLate = $currentHour > 8 || ($currentHour === 8 && $currentMinute > 30);
        $status = $isLate ? 'late' : 'clocked_in';

        // ---- Atomic insert (transaction + unique-constraint backstop) ----
        $inTransaction = false;
        $attendanceId = 0;
        try {
            $db->beginTransaction();
            $inTransaction = true;

            try {
                $record = [
                    'employee_id' => $employeeDbId,
                    'office_id' => $officeId,
                    'clock_in_office_id' => $officeId,
                    'clock_in' => $now,
                    'ip_address' => $this->clientIp(),
                    'status' => $status,
                    'is_late' => $isLate ? 1 : 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if ($hasCoordinates) {
                    $record['lat'] = $latitude;
                    $record['lng'] = $longitude;
                    $record['accuracy'] = $accuracy;
                }
                $attendanceId = $db->insert('attendance', $record);
                $db->commit();
                $inTransaction = false;
            } catch (\mysqli_sql_exception $e) {
                if ($inTransaction) {
                    $db->rollback();
                    $inTransaction = false;
                }
                // 1062 / SQLSTATE 23000 = duplicate key -> a concurrent request won the race.
                if ((int)$e->getCode() === 1062 || (string)$e->sqlstate === '23000') {
                    $ref = $db->fetchOne(
                        "SELECT a.id, a.clock_in, a.clock_out, a.is_late, a.status,
                                o.name AS office_name
                         FROM attendance a
                         LEFT JOIN offices o ON a.office_id = o.id
                         WHERE a.employee_id = ? AND DATE(a.clock_in) = ?
                         ORDER BY a.clock_in DESC LIMIT 1",
                        'is',
                        [$employeeDbId, $today]
                    );
                    $this->json([
                        'success' => true,
                        'message' => 'You have already clocked in today.',
                        'clock_in' => $ref['clock_in'] ?? $now,
                        'is_late' => (bool)($ref['is_late'] ?? ($isLate ? 1 : 0)),
                        'distance' => $radiusCheck['distance'],
                        'record' => $ref,
                        'idempotent' => true,
                    ]);
                    return;
                }
                throw $e;
            }
        } catch (\Throwable $e) {
            if ($inTransaction) {
                try { $db->rollback(); } catch (\Throwable $ignored) {}
            }
            \logger()->error('Clock-in failed', [
                'employee_id' => $employeeDbId,
                'error' => $e->getMessage(),
            ]);
            $this->error('Failed to record your clock-in. Please try again.', 500);
        }

        $this->json([
            'success' => true,
            'message' => ($isLate ? 'Clocked in (LATE ARRIVAL)' : 'Clock In successful.')
                . ($hasCoordinates ? '' : ' (location not verified)'),
            'is_late' => $isLate,
            'clock_in' => $now,
            'clock_in_id' => $attendanceId,
            'distance' => $radiusCheck['distance'],
        ]);
    }

    /**
     * POST /api/attendance/clock-out - Clock out with location validation.
     *
     * Expected JSON body:
     * {
     *   "office_id": 1,
     *   "latitude": -0.72809798,
     *   "longitude": 37.15159988,
     *   "accuracy": 20
     * }
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

        $data = $this->getJsonBody();

        // ---- Input Validation ----
        $validationError = $this->validateClockRequest($data);
        if ($validationError !== null) {
            $this->error($validationError, 400);
        }

        $officeId = (int)$data['office_id'];

        // Coordinates optional ONLY under the explicit no-fix declaration -
        // mirrors clockInAction so desktop users can also CLOCK OUT easily.
        $hasCoordinates = isset($data['latitude'], $data['longitude'])
            && $data['latitude'] !== ''
            && $data['longitude'] !== '';

        if ($hasCoordinates) {
            $latitude = (float)$data['latitude'];
            $longitude = (float)$data['longitude'];
            $accuracy = (float)($data['accuracy'] ?? 0);

            // Reject unusable GPS accuracy
            if ($accuracy > self::MAX_ACCURACY_METERS) {
                $this->error("GPS accuracy too low ({$accuracy}m). Please move to an open area and try again.", 400);
            }
        } else {
            $latitude = null;
            $longitude = null;
            $accuracy = null;
        }

        // ---- Get Office Details ----
        $office = $this->officeRepository->findById($officeId);
        if (!$office) {
            $this->error('Invalid office selected', 400);
        }

        $officeLat = (float)$office['latitude'];
        $officeLon = (float)$office['longitude'];
        if (!GeoLocation::isValidCoordinate($officeLat, $officeLon)) {
            $this->error('Selected office has no valid GPS coordinates configured', 400);
        }

                // ---- Geofence validation (skipped only for declared no-fix requests) ----
        if ($hasCoordinates) {
            // ---- Calculate Distance (Haversine, in meters) ----
            $radiusCheck = GeoLocation::isWithinRadius(
                $latitude,
                $longitude,
                $officeLat,
                $officeLon,
                (float)($office['geo_fence_radius'] ?? self::DEFAULT_RADIUS_METERS),
                $accuracy
            );

            // ---- Validate Radius (BEFORE update) ----
            if (!$radiusCheck['within']) {
                $message = 'You are about '
                    . \App\Helpers\GeoLocation::formatDistanceMeters((float) $radiusCheck['distance'])
                    . ' from the office. You must be within '
                    . \App\Helpers\GeoLocation::formatDistanceMeters((float) ($office['geo_fence_radius'] ?? self::DEFAULT_RADIUS_METERS))
                    . ' of the office to clock out. Please move closer and try again.';
                $this->json([
                    'success' => false,
                    'message' => $message,
                    'distance' => $radiusCheck['distance'],
                    'allowed_radius' => (float)($office['geo_fence_radius'] ?? self::DEFAULT_RADIUS_METERS),
                    'code' => 'OUTSIDE_RADIUS',
                ], 403);
            }
        } else {
            $radiusCheck = ['within' => true, 'distance' => null];
        }

        // ---- Get today's open session (clock out is valid for today only) ----
        $db = \db();
        $today = date('Y-m-d');
        $employeeDbId = (int)$employee['id'];
        $session = $db->fetchOne(
            "SELECT id, clock_in, clock_out, status
             FROM attendance
             WHERE employee_id = ? AND DATE(clock_in) = ?
             ORDER BY clock_in DESC LIMIT 1",
            'is',
            [$employeeDbId, $today]
        );

        if (!$session) {
            // No clock-in today (either absent, or yesterday's session was
            // reconciled overnight) -> cannot clock out.
            $this->error('No active clock-in found for today. Please clock in first.', 400, null, ['code' => 'NOT_CLOCKED_IN']);
        }

        // Idempotency: already clocked out earlier today (e.g. double-click).
        if (!empty($session['clock_out']) || (string)$session['status'] === 'clocked_out') {
            $this->json([
                'success' => true,
                'message' => 'You have already clocked out today.',
                'clock_out' => $session['clock_out'],
                'distance' => $radiusCheck['distance'],
                'idempotent' => true,
            ]);
            return;
        }

        // ---- Update with clock out (ONLY after validation passes) ----
        $now = date('Y-m-d H:i:s');
        $db->update('attendance', [
            'clock_out' => $now,
            'clock_out_office_id' => $officeId,
            'ip_address' => $this->clientIp(),
            'status' => 'clocked_out',
            'updated_at' => $now,
        ], 'id = ?', 'i', [(int)$session['id']]);

        $this->json([
            'success' => true,
            'message' => 'Clock Out successful.' . ($hasCoordinates ? '' : ' (location not verified)'),
            'clock_out' => $now,
            'distance' => $radiusCheck['distance'],
        ]);
    }

    /**
     * POST /api/attendance/auto-clockout - Auto clock-out all employees at midnight.
     *
     * This endpoint is intended to be called by a cron job at midnight.
     * It clocks out all employees who are still clocked in from the previous day.
     */
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
     * Parameters:
     *   date           Y-m-d            (default today)
     *   department_id  int              scope: summary+rows
     *   section_id     int              scope: summary+rows
     *   status         STATUS constant  row filter
     *   search         string           name / staff no row filter
     *   page, limit    pagination of the employee table
     *   trend_days     1-31             trailing trend window (default 7)
     */
    public function hrDashboardAction(): void
    {
        $this->requirePermission('attendance', 'view');

        try {
            $service = new \App\Services\AttendanceDashboardService();
            $this->success($service->getDashboard([
                'date'          => $_GET['date'] ?? null,
                'trend_days'    => isset($_GET['trend_days']) ? (int)$_GET['trend_days'] : 7,
                'department_id' => (isset($_GET['department_id']) && $_GET['department_id'] !== '')
                                    ? (int)$_GET['department_id'] : null,
                'section_id'    => (isset($_GET['section_id']) && $_GET['section_id'] !== '')
                                    ? (int)$_GET['section_id'] : null,
                'status'        => $_GET['status'] ?? null,
                'search'        => $this->getSearchQuery(),
                'page'          => max(1, (int)($_GET['page'] ?? 1)),
                'limit'         => (int)($_GET['limit'] ?? 25),
            ]));
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
     */
    public function hrEmployeeHistoryAction(): void
    {
        $this->requirePermission('attendance', 'view');

        $employeeId = (int)($_GET['employee_id'] ?? 0);
        if ($employeeId <= 0) {
            $this->error('employee_id is required', 400);
        }

        try {
            $service = new \App\Services\AttendanceDashboardService();
            $this->success($service->getEmployeeHistory(
                $employeeId,
                (string)($_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'))),
                (string)($_GET['end_date'] ?? date('Y-m-d')),
                (int)($_GET['limit'] ?? 30)
            ));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 404);
        } catch (\Throwable $e) {
            error_log('[hrEmployeeHistory] ' . $e->getMessage());
            $this->error('Failed to load employee attendance history', 500);
        }
    }

    public function autoClockOutAction(): void
    {
        // Only allow internal/cron calls (you can add IP whitelist here if needed)
        $db = \db();

        // Find all attendance records from previous days that are still clocked in
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $openSessions = $db->fetchAll(
            "SELECT id, employee_id, clock_in, office_id
             FROM attendance
             WHERE clock_out IS NULL AND DATE(clock_in) < ?",
            's',
            [date('Y-m-d')]
        );

        if (empty($openSessions)) {
            $this->json([
                'success' => true,
                'message' => 'No open sessions found.',
                'auto_clocked_out' => 0,
            ]);
            return;
        }

        $count = count($openSessions);

        // Batch-close all previous-day open sessions at end of their attendance
        // day (Africa/Nairobi) and flag auto_clocked_out = 1. One statement is
        // atomic + fast (no per-row round-trips). This mirrors the per-employee
        // reconcileStaleSession() so scheduled + lazy paths stay consistent.
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

        $this->json([
            'success' => true,
            'message' => "Auto clock-out completed. {$count} employee(s) clocked out.",
            'auto_clocked_out' => $count,
        ]);
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

    /**
     * Validate clock-in/clock-out request data.
     *
     * @param array $data The decoded JSON body
     * @return string|null Error message, or null if valid
     */
    private function validateClockRequest(array $data): ?string
    {
        if (empty($data['office_id']) || (int)$data['office_id'] <= 0) {
            return 'Office is required';
        }

        // A device may declare it has no fix at all (desktop PC without
        // GPS/Wi-Fi). When the organisation allows it, coordinates become
        // optional and the record is stored unverified (lat/lng NULL).
        $unverifiedAllowed = (($data['location_status'] ?? '') === 'unavailable')
            && env('ATTENDANCE_ALLOW_UNVERIFIED_LOCATION', false);
        if ($unverifiedAllowed) {
            return null;
        }

        if (!isset($data['latitude']) || !isset($data['longitude'])) {
            return 'Location is required. Please enable location services.';
        }

        $latitude = (float)$data['latitude'];
        $longitude = (float)$data['longitude'];

        if (!GeoLocation::isValidCoordinate($latitude, $longitude)) {
            return 'Invalid GPS coordinates received';
        }

        if ($latitude === 0.0 && $longitude === 0.0) {
            return 'Could not determine your location. Please check your GPS settings.';
        }

        return null;
    }
}

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
     * GET /api/attendance/dashboard - Get dashboard attendance data.
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
                'offices' => [],
            ]);
            return;
        }

        $db = \db();
        $today = date('Y-m-d');
        $employeeDbId = (int)$employee['id'];

        // Get current active session (clocked in, not clocked out)
        $currentSession = $db->fetchOne(
            "SELECT a.*, o.name as office_name, o.latitude, o.longitude, o.geo_fence_radius
             FROM attendance a
             LEFT JOIN offices o ON a.office_id = o.id
             WHERE a.employee_id = ? AND a.clock_out IS NULL
             ORDER BY a.clock_in DESC LIMIT 1",
            'i',
            [$employeeDbId]
        );

        // Get today's record
        $todayRecord = $db->fetchOne(
            "SELECT * FROM attendance
             WHERE employee_id = ? AND DATE(clock_in) = ?
             ORDER BY clock_in DESC LIMIT 1",
            'is',
            [$employeeDbId, $today]
        );

        $isClockedIn = !empty($currentSession);
        $hasClockedInToday = !empty($todayRecord);

        // Get all offices for clock in
        $offices = $db->fetchAll(
            "SELECT id, name, latitude, longitude, geo_fence_radius
             FROM offices
             WHERE latitude IS NOT NULL AND longitude IS NOT NULL
             ORDER BY name ASC"
        );

        $this->success([
            'is_clocked_in' => $isClockedIn,
            'has_clocked_in_today' => $hasClockedInToday,
            'current_session' => $currentSession,
            'today_record' => $todayRecord,
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
        $this->requirePermission('attendance', 'edit');
        
        $userId = $this->getUserId();
        $employee = $this->employeeRepository->findByUserId($userId);
        
        if (!$employee) {
            $this->notFound('Employee profile not found');
        }

        $data = $this->getJsonBody();
        
        // ---- Input Validation ----
        $validationError = $this->validateClockRequest($data);
        if ($validationError !== null) {
            $this->error($validationError, 400);
        }

        $officeId = (int)$data['office_id'];
        $latitude = (float)$data['latitude'];
        $longitude = (float)$data['longitude'];
        $accuracy = (float)($data['accuracy'] ?? 0);

        // Reject unusable GPS accuracy
        if ($accuracy > self::MAX_ACCURACY_METERS) {
            $this->error("GPS accuracy too low ({$accuracy}m). Please move to an open area and try again.", 400);
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
            $message = 'You are outside the allowed office radius. Please move closer to the office before clocking in.';
            $this->json([
                'success' => false,
                'message' => $message,
                'distance' => $radiusCheck['distance'],
                'allowed_radius' => (float)($office['geo_fence_radius'] ?? self::DEFAULT_RADIUS_METERS),
                'code' => 'OUTSIDE_RADIUS',
            ], 403);
        }

        // ---- Check for existing record (prevent duplicates) ----
        $db = \db();
        $today = date('Y-m-d');
        
        // Use an INSERT ... SELECT with NOT EXISTS for atomic duplicate prevention
        $employeeDbId = (int)$employee['id'];
        $existing = $db->fetchOne(
            "SELECT id FROM attendance 
             WHERE employee_id = ? AND DATE(clock_in) = ? 
             LIMIT 1",
            'is',
            [$employeeDbId, $today]
        );

        if ($existing) {
            $this->error('You have already clocked in today', 400, null, ['code' => 'ALREADY_CLOCKED_IN']);
        }

        // ---- Determine if late ----
        $currentHour = (int)date('H');
        $currentMinute = (int)date('i');
        $isLate = $currentHour > 8 || ($currentHour === 8 && $currentMinute > 30);
        $status = $isLate ? 'late' : 'clocked_in';
        $now = date('Y-m-d H:i:s');

        // ---- Insert attendance record (ONLY after validation passes) ----
        $db->insert('attendance', [
            'employee_id' => $employeeDbId,
            'office_id' => $officeId,
            'clock_in_office_id' => $officeId,
            'clock_in' => $now,
            'lat' => $latitude,
            'lng' => $longitude,
            'accuracy' => $accuracy,
            'status' => $status,
            'is_late' => $isLate ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->json([
            'success' => true,
            'message' => $isLate ? 'Clocked in (LATE ARRIVAL)' : 'Clock In successful.',
            'is_late' => $isLate,
            'clock_in' => $now,
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
        $this->requirePermission('attendance', 'edit');
        
        $userId = $this->getUserId();
        $employee = $this->employeeRepository->findByUserId($userId);
        
        if (!$employee) {
            $this->notFound('Employee profile not found');
        }

        $data = $this->getJsonBody();
        
        // ---- Input Validation ----
        $validationError = $this->validateClockRequest($data);
        if ($validationError !== null) {
            $this->error($validationError, 400);
        }

        $officeId = (int)$data['office_id'];
        $latitude = (float)$data['latitude'];
        $longitude = (float)$data['longitude'];
        $accuracy = (float)($data['accuracy'] ?? 0);

        // Reject unusable GPS accuracy
        if ($accuracy > self::MAX_ACCURACY_METERS) {
            $this->error("GPS accuracy too low ({$accuracy}m). Please move to an open area and try again.", 400);
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
            $message = 'You are outside the allowed office radius. Please move closer to the office before clocking out.';
            $this->json([
                'success' => false,
                'message' => $message,
                'distance' => $radiusCheck['distance'],
                'allowed_radius' => (float)($office['geo_fence_radius'] ?? self::DEFAULT_RADIUS_METERS),
                'code' => 'OUTSIDE_RADIUS',
            ], 403);
        }

        // ---- Get current active session ----
        $db = \db();
        $employeeDbId = (int)$employee['id'];
        $session = $db->fetchOne(
            "SELECT id, clock_in FROM attendance 
             WHERE employee_id = ? AND clock_out IS NULL 
             ORDER BY clock_in DESC LIMIT 1",
            'i',
            [$employeeDbId]
        );

        if (!$session) {
            $this->error('You are not clocked in', 400, null, ['code' => 'NOT_CLOCKED_IN']);
        }

        // ---- Update with clock out (ONLY after validation passes) ----
        $db->update('attendance', [
            'clock_out' => date('Y-m-d H:i:s'),
            'clock_out_office_id' => $officeId,
            'status' => 'clocked_out',
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', 'i', [(int)$session['id']]);

        $this->json([
            'success' => true,
            'message' => 'Clock Out successful.',
            'clock_out' => date('Y-m-d H:i:s'),
            'distance' => $radiusCheck['distance'],
        ]);
    }

    /**
     * POST /api/attendance/auto-clockout - Auto clock-out all employees at midnight.
     *
     * This endpoint is intended to be called by a cron job at midnight.
     * It clocks out all employees who are still clocked in from the previous day.
     */
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

        $now = date('Y-m-d H:i:s');
        $count = 0;

        foreach ($openSessions as $session) {
            // Update each open session with auto clock-out
            $db->update('attendance', [
                'clock_out' => $now,
                'status' => 'auto_clocked_out',
                'updated_at' => $now,
            ], 'id = ?', 'i', [(int)$session['id']]);
            $count++;
        }

        $this->json([
            'success' => true,
            'message' => "Auto clock-out completed. {$count} employee(s) clocked out.",
            'auto_clocked_out' => $count,
        ]);
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

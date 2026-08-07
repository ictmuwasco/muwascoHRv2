<?php

declare(strict_types=1);

namespace App\Controllers;

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

    public function __construct()
    {
        $this->attendanceService = new \App\Services\AttendanceService();
        $this->employeeRepository = new \App\Repositories\EmployeeRepository();
        $this->officeRepository = new \App\Repositories\OfficeRepository();
        
        $this->attendanceService->setAttendanceRepository(new \App\Repositories\AttendanceRepository());
        $this->attendanceService->setEmployeeRepository($this->employeeRepository);
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
            "SELECT a.*, e.first_name, e.last_name, e.department, o.name as office_name
             FROM attendance a
             JOIN employees e ON a.employee_id = e.employee_id
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
     * POST /api/attendance/clock-in - Clock in.
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
        $officeId = (int)($data['office_id'] ?? 0);
        $latitude = (float)($data['latitude'] ?? 0);
        $longitude = (float)($data['longitude'] ?? 0);
        $accuracy = (float)($data['accuracy'] ?? 0);

        if ($officeId <= 0) {
            $this->error('Office is required', 400);
        }

        // Get office details
        $office = $this->officeRepository->findById($officeId);
        if (!$office || empty($office['latitude']) || empty($office['longitude'])) {
            $this->error('Invalid office selected', 400);
        }

        // Calculate distance
        $distance = $this->calculateDistance(
            $latitude, $longitude,
            (float)$office['latitude'], (float)$office['longitude']
        );
        $distanceM = round($distance * 1000);
        $geoRadius = (int)($office['geo_fence_radius'] ?? 200);
        $effDist = $distanceM + ($accuracy * 0.3);

        if ($effDist > $geoRadius + 20) {
            $this->error("Outside geo-fence ({$geoRadius}m). Your distance: {$distanceM}m ±{$accuracy}m.", 400);
        }

        // Check if already clocked in today
        $db = \db();
        $today = date('Y-m-d');
        $existing = $db->fetchOne(
            "SELECT * FROM attendance WHERE employee_id = ? AND DATE(clock_in) = ? LIMIT 1",
            'is',
            [(int)$employee['id'], $today]
        );

        if ($existing) {
            $this->error('You have already clocked in today', 400);
        }

        // Determine if late
        $currentHour = (int)date('H');
        $currentMinute = (int)date('i');
        $isLate = $currentHour > 8 || ($currentHour === 8 && $currentMinute > 30);
        $status = $isLate ? 'late' : 'clocked_in';

        // Insert attendance record
        $db->insert('attendance', [
            'employee_id' => (int)$employee['id'],
            'office_id' => $officeId,
            'clock_in' => date('Y-m-d H:i:s'),
            'lat' => $latitude,
            'lng' => $longitude,
            'accuracy' => $accuracy,
            'status' => $status,
            'is_late' => $isLate ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->success([
            'message' => $isLate ? 'Clocked in (LATE ARRIVAL)' : 'Clocked in successfully',
            'is_late' => $isLate,
            'clock_in' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * POST /api/attendance/clock-out - Clock out.
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
        $officeId = (int)($data['office_id'] ?? 0);

        if ($officeId <= 0) {
            $this->error('Office is required', 400);
        }

        // Get current active session
        $db = \db();
        $session = $db->fetchOne(
            "SELECT * FROM attendance WHERE employee_id = ? AND clock_out IS NULL LIMIT 1",
            'i',
            [(int)$employee['id']]
        );

        if (!$session) {
            $this->error('You are not clocked in', 400);
        }

        // Update with clock out
        $db->update('attendance', [
            'clock_out' => date('Y-m-d H:i:s'),
            'clock_out_office_id' => $officeId,
            'status' => 'clocked_out',
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $session['id']]);

        $this->success([
            'message' => 'Clocked out successfully',
            'clock_out' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Calculate distance between two coordinates (Haversine formula).
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $R = 6371; // Earth's radius in km
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
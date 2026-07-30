<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Contracts\AttendanceServiceInterface;
use App\Services\AttendanceService;
use App\Repositories\EmployeeRepository;
use App\Repositories\OfficeRepository;

/**
 * AttendanceController
 *
 * Handles all attendance-related functionality:
 * clock-in, clock-out, geo-fence validation, history.
 * Thin controller that delegates business logic to AttendanceService.
 *
 * Place: backend/app/Controllers/AttendanceController.php
 */
class AttendanceController extends Controller
{
    private AttendanceServiceInterface $attendanceService;
    private EmployeeRepository $employeeRepository;
    private OfficeRepository $officeRepository;

    public function __construct()
    {
        // Dependency injection
        $this->attendanceService = new AttendanceService();
        $this->attendanceService->setAttendanceRepository(new \App\Repositories\AttendanceRepository());
        $this->attendanceService->setEmployeeRepository(new EmployeeRepository());
        
        $this->employeeRepository = new EmployeeRepository();
        $this->officeRepository = new OfficeRepository();
    }

    /**
     * Display the attendance page with history.
     * GET /attendance
     */
    public function indexAction(): void
    {
        if (!$this->isAuthenticated()) {
            $this->redirect('login');
            return;
        }

        // Ensure RBAC functions are loaded
        if (!function_exists('hasPermission')) {
            require_once dirname(__DIR__, 2) . '/auth.php';
        }

        $userId = $this->getUserId();

        // Get employee record using repository
        $employee = $this->employeeRepository->findByUserId($userId);
        $employeeDbId = $employee['id'] ?? null;

        // Get attendance history using service
        $history = $employeeDbId
            ? $this->attendanceService->getAttendanceByEmployee((int)$employeeDbId, date('Y-m-d', strtotime('-30 days')), date('Y-m-d'))
            : [];

        // Get today's summary using service
        $summary = $employeeDbId
            ? $this->attendanceService->getAttendanceStatistics((int)$employeeDbId, (int)date('Y'), (int)date('m'))
            : null;

        // Get offices using repository
        $offices = [];
        if ($employeeDbId) {
            $allOffices = $this->officeRepository->getAllActive();
            foreach ($allOffices as $office) {
                $offices[] = [
                    'id' => $office['id'],
                    'name' => $office['name'],
                    'latitude' => $office['latitude'] ?? null,
                    'longitude' => $office['longitude'] ?? null,
                    'geo_fence_radius' => $office['geo_fence_radius'] ?? 200,
                    'is_assigned' => ($employee['office_id'] == $office['id']) ? 1 : 0,
                ];
            }
        }

        $this->view('attendance/index', [
            'employee'    => $employee,
            'employee_db_id' => $employeeDbId,
            'history'     => $history,
            'summary'     => $summary,
            'offices'     => $offices,
            'csrf_token'  => $this->generateCsrfToken(),
        ]);
    }

    /**
     * Process clock-in / clock-out via AJAX.
     * POST /attendance/clock
     */
    public function clockAction(): void
    {
        if (!$this->isAuthenticated()) {
            $this->json(['error' => 'Unauthenticated'], 401);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['error' => 'Method not allowed'], 405);
            return;
        }

        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $this->json(['error' => 'Invalid CSRF token'], 403);
            return;
        }

        $userId       = $this->getUserId();
        $action       = $_POST['action'] ?? '';
        $latitude     = (float)($_POST['latitude'] ?? 0);
        $longitude    = (float)($_POST['longitude'] ?? 0);
        $accuracy     = (float)($_POST['accuracy'] ?? 0);
        $officeId     = (int)($_POST['office_id'] ?? 0);

        try {
            // Get employee using repository
            $employee = $this->employeeRepository->findByUserId($userId);
            if (!$employee) {
                $this->json(['error' => 'Employee record not found'], 400);
                return;
            }

            $employeeDbId = (int)$employee['id'];

            // Validate office
            if (!$officeId) {
                $this->json(['error' => 'Please select an office'], 400);
                return;
            }

            // Get office using repository
            $office = $this->officeRepository->findById($officeId);
            if (!$office || empty($office['latitude']) || empty($office['longitude'])) {
                $this->json(['error' => 'Office location not configured'], 400);
                return;
            }

            // Geo-fence check
            $distance = $this->calculateDistance(
                $latitude, $longitude,
                (float)$office['latitude'], (float)$office['longitude']
            ) * 1000; // Convert to meters

            $geoRadius = (float)($office['geo_fence_radius'] ?: 200);
            $effDist   = $distance + ($accuracy * 0.3);

            if ($effDist > $geoRadius + 20) {
                $this->json([
                    'error' => "Outside geo-fence ({$geoRadius}m). Distance: " . round($distance) . "m",
                ], 400);
                return;
            }

            $warning = null;
            if ($accuracy > 150) {
                $warning = "Low location accuracy (±{$accuracy}m). Enable GPS for better results.";
            }

            // Get device fingerprint
            $deviceFp = $this->generateDeviceFingerprint();
            $today    = date('Y-m-d');

            if ($action === 'clock_in') {
                // Check if already clocked in using service
                if ($this->attendanceService->hasClockedInToday($employeeDbId)) {
                    $this->json(['error' => 'You are already clocked in'], 400);
                    return;
                }

                $currentHour = (int)date('H');
                $currentMin  = (int)date('i');
                $isLate      = $currentHour > 8 || ($currentHour === 8 && $currentMin > 30);

                // Clock in using service
                $attendanceId = $this->attendanceService->clockIn($employeeDbId, $isLate ? 'Late arrival' : '');

                if ($attendanceId) {
                    $this->json([
                        'success' => true,
                        'message' => 'Clocked in successfully!' . ($isLate ? ' (Late)' : ''),
                        'warning' => $warning,
                        'status'  => $isLate ? 'late' : 'clocked_in',
                    ]);
                } else {
                    $this->json(['error' => 'Failed to clock in'], 500);
                }

            } elseif ($action === 'clock_out') {
                // Get current attendance using service
                $todayAttendance = $this->attendanceService->getTodayAttendance();
                $currentSession = null;
                foreach ($todayAttendance as $record) {
                    if ($record['employee_id'] == $employeeDbId) {
                        $currentSession = $record;
                        break;
                    }
                }

                if (!$currentSession) {
                    $this->json(['error' => 'You are not clocked in'], 400);
                    return;
                }

                // Clock out using service
                $success = $this->attendanceService->clockOut((int)$currentSession['id']);

                if ($success) {
                    $this->json([
                        'success' => true,
                        'message' => 'Clocked out successfully!',
                        'warning' => $warning,
                    ]);
                } else {
                    $this->json(['error' => 'Failed to clock out'], 500);
                }

            } else {
                $this->json(['error' => 'Invalid action'], 400);
            }
        } catch (\InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            \logger()->error('Attendance clock error', ['error' => $e->getMessage()]);
            $this->json(['error' => 'Failed to process attendance. Please try again.'], 500);
        }
    }

    /**
     * Get attendance summary for AJAX refresh.
     * GET /attendance/summary
     */
    public function summaryAction(): void
    {
        if (!$this->isAuthenticated()) {
            $this->json(['error' => 'Unauthenticated'], 401);
            return;
        }

        $userId = $this->getUserId();

        try {
            // Get employee using repository
            $employee = $this->employeeRepository->findByUserId($userId);
            if (!$employee) {
                $this->json(['error' => 'Employee not found'], 400);
                return;
            }

            // Get summary using service
            $summary = $this->attendanceService->getAttendanceStatistics(
                (int)$employee['id'],
                (int)date('Y'),
                (int)date('m')
            );
            
            $this->json($summary);
        } catch (\InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            \logger()->error('Attendance summary error', ['error' => $e->getMessage()]);
            $this->json(['error' => 'Failed to load summary. Please try again.'], 500);
        }
    }

    /**
     * Haversine distance calculation.
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $R    = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Send JSON response.
     */
    private function json(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }
}

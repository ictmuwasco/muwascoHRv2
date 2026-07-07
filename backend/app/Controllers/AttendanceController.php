<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Attendance;

/**
 * AttendanceController
 *
 * Handles all attendance-related functionality:
 * clock-in, clock-out, geo-fence validation, history.
 *
 * Place: backend/app/Controllers/AttendanceController.php
 */
class AttendanceController extends Controller
{
    private Attendance $attendanceModel;

    public function __construct()
    {
        parent::__construct();
        $this->attendanceModel = new Attendance();
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
        $conn   = $this->getDbConnection();

        // Get employee record
        $stmt = $conn->prepare("
            SELECT e.* FROM employees e
            JOIN users u ON u.employee_id = e.employee_id
            WHERE u.id = ?
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $employee = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $employeeDbId = $employee['id'] ?? null;

        // Get attendance history
        $history = $employeeDbId
            ? $this->attendanceModel->getHistory((int)$employeeDbId, 30)
            : [];

        // Get today's summary
        $summary = $employeeDbId
            ? $this->attendanceModel->getAttendanceSummary((int)$employeeDbId)
            : null;

        // Get offices
        $offices = [];
        if ($employeeDbId) {
            $result = $conn->query("
                SELECT o.id, o.name, o.latitude, o.longitude, o.geo_fence_radius,
                       CASE WHEN e.office_id = o.id THEN 1 ELSE 0 END AS is_assigned
                FROM offices o
                LEFT JOIN employees e ON e.id = $employeeDbId AND e.office_id = o.id
                WHERE o.latitude IS NOT NULL
                ORDER BY is_assigned DESC, o.name ASC
            ");
            $offices = $result->fetch_all(MYSQLI_ASSOC);
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

        $conn = $this->getDbConnection();

        // Get employee
        $stmt = $conn->prepare("
            SELECT e.id, e.office_id FROM employees e
            JOIN users u ON u.employee_id = e.employee_id
            WHERE u.id = ?
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $employee = $stmt->get_result()->fetch_assoc();
        $stmt->close();

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

        // Get office geo-fence
        $stmt = $conn->prepare("
            SELECT id, latitude, longitude, geo_fence_radius
            FROM offices WHERE id = ?
        ");
        $stmt->bind_param("i", $officeId);
        $stmt->execute();
        $office = $stmt->get_result()->fetch_assoc();
        $stmt->close();

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
            // Check device already used today
            if ($this->attendanceModel->isDeviceUsedToday($deviceFp, $today)) {
                $this->json(['error' => 'This device has already been used for clock-in today'], 400);
                return;
            }

            // Check already clocked in
            $currentSession = $this->attendanceModel->getCurrentSession($employeeDbId);
            if ($currentSession) {
                $this->json(['error' => 'You are already clocked in'], 400);
                return;
            }

            $currentHour = (int)date('H');
            $currentMin  = (int)date('i');
            $isLate      = $currentHour > 8 || ($currentHour === 8 && $currentMin > 30);

            $success = $this->attendanceModel->clockIn(
                $employeeDbId, $officeId, $latitude, $longitude,
                $accuracy, $isLate, $deviceFp,
                $isLate ? 'late' : 'clocked_in'
            );

            if ($success) {
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
            $currentSession = $this->attendanceModel->getCurrentSession($employeeDbId);

            if (!$currentSession) {
                $this->json(['error' => 'You are not clocked in'], 400);
                return;
            }

            $success = $this->attendanceModel->clockOut(
                (int)$currentSession['id'], $employeeDbId, $officeId
            );

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
        $conn   = $this->getDbConnection();

        $stmt = $conn->prepare("
            SELECT e.id FROM employees e
            JOIN users u ON u.employee_id = e.employee_id
            WHERE u.id = ?
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $emp = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$emp) {
            $this->json(['error' => 'Employee not found'], 400);
            return;
        }

        $summary = $this->attendanceModel->getAttendanceSummary((int)$emp['id']);
        $this->json($summary);
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

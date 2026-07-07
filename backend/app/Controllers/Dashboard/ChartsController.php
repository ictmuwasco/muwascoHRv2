<?php

declare(strict_types=1);

namespace App\Controllers\Dashboard;

use App\Core\Controller;

/**
 * ChartsController
 *
 * Handles all chart data preparation and statistics aggregation.
 * Returns JSON data for AJAX chart rendering.
 * Keeps chart logic separate from the DashboardController.
 *
 * Place: backend/app/Controllers/Dashboard/ChartsController.php
 */
class ChartsController extends Controller
{
    /**
     * Get employee distribution by department (for pie chart).
     * GET /charts/employee-distribution
     */
    public function employeeDistributionAction(): void
    {
        try {
            $this->requireHrAccess();

            $conn = $this->getDbConnection();
            $result = $conn->query("
                SELECT d.name AS department_name, COUNT(e.id) AS employee_count
                FROM departments d
                LEFT JOIN employees e ON d.id = e.department_id AND e.employee_status = 'active'
                GROUP BY d.id, d.name HAVING employee_count > 0
                ORDER BY employee_count DESC
            ");

            $data = [
                'labels' => [],
                'values' => [],
                'colors' => [],
            ];

            $palette = [
                '#00d4ff', '#6c5ce7', '#00b894', '#fdcb6e',
                '#e17055', '#ff3366', '#a29bfe', '#55efc4',
                '#74b9ff', '#fd79a8',
            ];

            $i = 0;
            while ($row = $result->fetch_assoc()) {
                $data['labels'][] = $row['department_name'];
                $data['values'][] = (int)$row['employee_count'];
                $data['colors'][] = $palette[$i % count($palette)];
                $i++;
            }

            $this->json($data);
        } catch (\Exception $e) {
            error_log("Chart error (employee-distribution): " . $e->getMessage());
            $this->json(['error' => 'Failed to load chart data'], 500);
        }
    }

    /**
     * Get sections per department (for bar chart).
     * GET /charts/sections-per-dept
     */
    public function sectionsPerDeptAction(): void
    {
        try {
            $this->requireHrAccess();

            $conn = $this->getDbConnection();
            $result = $conn->query("
                SELECT d.name AS department_name, COUNT(s.id) AS section_count
                FROM departments d
                LEFT JOIN sections s ON d.id = s.department_id
                GROUP BY d.id
            ");

            $data = ['labels' => [], 'values' => []];
            while ($row = $result->fetch_assoc()) {
                $data['labels'][] = $row['department_name'];
                $data['values'][] = (int)$row['section_count'];
            }

            $this->json($data);
        } catch (\Exception $e) {
            error_log("Chart error (sections-per-dept): " . $e->getMessage());
            $this->json(['error' => 'Failed to load chart data'], 500);
        }
    }

    /**
     * Get leave statistics for current month.
     * GET /charts/leave-stats
     */
    public function leaveStatsAction(): void
    {
        try {
            $this->requireHrAccess();

            $conn = $this->getDbConnection();
            $m = date('m');
            $y = date('Y');

            $result = $conn->query("
                SELECT lt.name AS leave_type, COUNT(DISTINCT la.employee_id) AS employees_on_leave
                FROM leave_applications la
                JOIN leave_types lt ON la.leave_type_id = lt.id
                WHERE MONTH(la.applied_at) = '$m' AND YEAR(la.applied_at) = '$y'
                GROUP BY lt.name
            ");

            $data = ['labels' => [], 'values' => []];
            while ($row = $result->fetch_assoc()) {
                $data['labels'][] = $row['leave_type'];
                $data['values'][] = (int)$row['employees_on_leave'];
            }

            $this->json($data);
        } catch (\Exception $e) {
            error_log("Chart error (leave-stats): " . $e->getMessage());
            $this->json(['error' => 'Failed to load chart data'], 500);
        }
    }

    /**
     * Get appraisal completion by department.
     * GET /charts/appraisal-completion
     */
    public function appraisalCompletionAction(): void
    {
        try {
            $this->requireHrAccess();

            $conn = $this->getDbConnection();
            $result = $conn->query("
                SELECT d.name AS department_name, COUNT(e.id) AS total_employees,
                       SUM(CASE WHEN ea.status = 'submitted' THEN 1 ELSE 0 END) AS completed_appraisals
                FROM departments d
                INNER JOIN employees e ON d.id = e.department_id AND e.employee_status = 'active'
                LEFT JOIN employee_appraisals ea ON e.id = ea.employee_id
                LEFT JOIN appraisal_cycles ac ON ea.appraisal_cycle_id = ac.id AND ac.status = 'active'
                GROUP BY d.id, d.name ORDER BY d.name
            ");

            $data = [
                'labels'  => [],
                'completed' => [],
                'pending'   => [],
            ];

            while ($row = $result->fetch_assoc()) {
                $total = (int)$row['total_employees'];
                if ($total > 0) {
                    $done = (int)$row['completed_appraisals'];
                    $data['labels'][]   = $row['department_name'] ?: 'Unassigned';
                    $data['completed'][] = $done;
                    $data['pending'][]   = max(0, $total - $done);
                }
            }

            $this->json($data);
        } catch (\Exception $e) {
            error_log("Chart error (appraisal-completion): " . $e->getMessage());
            $this->json(['error' => 'Failed to load chart data'], 500);
        }
    }

    /**
     * Get attendance trends for the current month.
     * GET /charts/attendance-trends
     */
    public function attendanceTrendsAction(): void
    {
        try {
            $conn = $this->getDbConnection();
            $userId = $this->getUserId();
            if (!$userId) {
                $this->json(['error' => 'Unauthenticated'], 401);
                return;
            }

            // Get employee DB ID
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
                $this->json(['labels' => [], 'values' => []]);
                return;
            }

            $monthStart = date('Y-m-01');
            $today = date('Y-m-d');

            $result = $conn->query("
                SELECT DATE(clock_in) as day,
                       ROUND(TIMESTAMPDIFF(MINUTE, clock_in, COALESCE(clock_out, NOW())) / 60, 1) as hours
                FROM attendance
                WHERE employee_id = {$emp['id']}
                  AND DATE(clock_in) >= '$monthStart'
                  AND DATE(clock_in) <= '$today'
                ORDER BY clock_in ASC
            ");

            $data = ['labels' => [], 'values' => []];
            while ($row = $result->fetch_assoc()) {
                $data['labels'][] = date('d M', strtotime($row['day']));
                $data['values'][] = (float)$row['hours'];
            }

            $this->json($data);
        } catch (\Exception $e) {
            error_log("Chart error (attendance-trends): " . $e->getMessage());
            $this->json(['error' => 'Failed to load chart data'], 500);
        }
    }

    /**
     * Require HR manager access or return 403.
     */
    private function requireHrAccess(): void
    {
        $role = $_SESSION['user_role'] ?? '';
        $allowedRoles = ['hr_manager', 'super_admin', 'managing_director'];

        if (!in_array($role, $allowedRoles, true)) {
            $this->json(['error' => 'Forbidden'], 403);
            exit();
        }
    }

    /**
     * Send JSON response.
     */
    protected function json(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Reports;

use App\Controllers\BaseController;
use App\Helpers\Database;

/**
 * Reports Controller - Handles system reports and export downloads.
 */
class ReportsController extends BaseController
{
    private \mysqli $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * GET /api/reports/employees - Employee directory and metrics report.
     */
    public function employeesAction(): void
    {
        $this->requirePermission('reports', 'view');

        try {
            $stmt = $this->db->prepare("
                SELECT e.id, e.employee_id, e.first_name, e.last_name, e.surname, e.email, e.phone,
                       e.gender, e.employee_status, e.employee_type, e.designation,
                       d.name as department_name, s.name as section_name,
                       e.date_of_birth, e.contract_start_date, e.contract_end_date, e.national_id,
                       e.hire_date, e.created_at
                FROM employees e
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN sections s ON e.section_id = s.id
                ORDER BY d.name ASC, e.first_name ASC
            ");
            $stmt->execute();
            $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $totalEmployees = count($result);
            $activeCount = count(array_filter($result, fn($e) => ($e['employee_status'] ?? '') === 'active'));
            $inactiveCount = $totalEmployees - $activeCount;

            $byDepartment = [];
            $byEmploymentType = [];
            $byStatus = [];
            $byGender = [];
            foreach ($result as $emp) {
                $dept = $emp['department_name'] ?: 'Unassigned';
                $byDepartment[$dept] = ($byDepartment[$dept] ?? 0) + 1;

                $empType = $emp['employee_type'] ?: 'unspecified';
                $byEmploymentType[$empType] = ($byEmploymentType[$empType] ?? 0) + 1;

                $status = $emp['employee_status'] ?: 'unspecified';
                $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

                $gender = $emp['gender'] ?: 'unspecified';
                $byGender[$gender] = ($byGender[$gender] ?? 0) + 1;
            }

            $this->success([
                'summary' => [
                    'total' => $totalEmployees,
                    'active' => $activeCount,
                    'inactive' => $inactiveCount,
                    'by_department' => $byDepartment,
                    'by_employment_type' => $byEmploymentType,
                    'by_status' => $byStatus,
                    'by_gender' => $byGender,
                ],
                'records' => $result,
            ]);
        } catch (\Throwable $e) {
            \logger()->error('Employee report error', ['error' => $e->getMessage()]);
            $this->error('Failed to generate employee report: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/reports/leave - Leave summary and utilization report.
     */
    public function leaveAction(): void
    {
        $this->requirePermission('reports', 'view');

        try {
            $stmt = $this->db->prepare("
                SELECT la.id, la.start_date, la.end_date, la.days_requested, la.status, la.applied_at,
                       e.first_name, e.last_name, e.employee_id as emp_code,
                       lt.name as leave_type, d.name as department_name
                FROM leave_applications la
                LEFT JOIN employees e ON la.employee_id = e.id
                LEFT JOIN leave_types lt ON la.leave_type_id = lt.id
                LEFT JOIN departments d ON e.department_id = d.id
                ORDER BY la.applied_at DESC
                LIMIT 1000
            ");
            $stmt->execute();
            $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $approvedCount = count(array_filter($result, fn($r) => ($r['status'] ?? '') === 'approved'));
            $pendingCount = count(array_filter($result, fn($r) => str_starts_with((string)($r['status'] ?? ''), 'pending')));
            $rejectedCount = count(array_filter($result, fn($r) => ($r['status'] ?? '') === 'rejected'));

            $this->success([
                'summary' => [
                    'total_applications' => count($result),
                    'approved' => $approvedCount,
                    'pending' => $pendingCount,
                    'rejected' => $rejectedCount,
                ],
                'records' => $result,
            ]);
        } catch (\Throwable $e) {
            \logger()->error('Leave report error', ['error' => $e->getMessage()]);
            $this->error('Failed to generate leave report: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/reports/attendance - Attendance summary report.
     */
    public function attendanceAction(): void
    {
        $this->requirePermission('reports', 'view');

        try {
            $startDate = (($_GET['start_date'] ?? '') !== '') ? (string)$_GET['start_date'] : date('Y-m-01');
            $endDate = (($_GET['end_date'] ?? '') !== '') ? (string)$_GET['end_date'] : date('Y-m-d');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
                $this->error('Invalid date range. Expected format: YYYY-MM-DD.', 422);
                return;
            }

            // Per-employee aggregate over the range (mirrors the CSV export),
            // instead of raw rows - 12k+ raw rows would be unusable client-side.
            $sql = "
                SELECT e.id AS employee_db_id,
                       e.employee_id AS emp_no,
                       CONCAT(e.first_name, ' ', COALESCE(e.last_name, '')) AS name,
                       COALESCE(d.name, 'Unassigned') AS department,
                       COUNT(a.id) AS days_present,
                       SUM(CASE WHEN a.is_late = 1 THEN 1 ELSE 0 END) AS late_days,
                       SUM(CASE WHEN a.status = 'auto_clocked_out' THEN 1 ELSE 0 END) AS auto_days,
                       SUM(CASE WHEN a.clock_out IS NULL OR a.clock_out = '' THEN 1 ELSE 0 END) AS missing_out,
                       ROUND(SUM(CASE WHEN a.clock_in IS NOT NULL AND a.clock_in <> '' AND a.clock_out IS NOT NULL AND a.clock_out <> ''
                               THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, a.clock_in, a.clock_out)) ELSE 0 END) / 60, 1) AS total_hours,
                       ROUND(SUM(CASE WHEN a.clock_in IS NOT NULL AND a.clock_in <> '' AND a.clock_out IS NOT NULL AND a.clock_out <> ''
                               THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, a.clock_in, a.clock_out)) ELSE 0 END) / 60
                           / NULLIF(COUNT(a.id), 0), 1) AS avg_hours,
                       MIN(a.attendance_date) AS first_date,
                       MAX(a.attendance_date) AS last_date
                FROM attendance a
                LEFT JOIN employees e ON a.employee_id = e.id
                LEFT JOIN departments d ON e.department_id = d.id
                WHERE a.attendance_date BETWEEN ? AND ?";
            $types = 'ss';
            $params = [$startDate, $endDate];
            if (!empty($_GET['department_id'])) {
                $sql .= ' AND e.department_id = ?';
                $types .= 'i';
                $params[] = (int)$_GET['department_id'];
            }
            $sql .= " GROUP BY e.id, e.employee_id, e.first_name, e.last_name, d.name
                      ORDER BY days_present DESC, name ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            // Range-wide statistics computed from the aggregated set.
            $totalRecords = array_sum(array_map(fn($r) => (int)$r['days_present'], $records));
            $lateTotal = array_sum(array_map(fn($r) => (int)$r['late_days'], $records));
            $autoTotal = array_sum(array_map(fn($r) => (int)$r['auto_days'], $records));
            $missingTotal = array_sum(array_map(fn($r) => (int)$r['missing_out'], $records));
            $hoursTotal = array_sum(array_map(fn($r) => (float)$r['total_hours'], $records));

            $this->success([
                'summary' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'total_records' => $totalRecords,
                    'employees_with_records' => count($records),
                    'late_arrivals' => $lateTotal,
                    'auto_clockouts' => $autoTotal,
                    'missing_clockouts' => $missingTotal,
                    'total_hours' => round($hoursTotal, 1),
                    'avg_hours_per_employee' => count($records) > 0 ? round($hoursTotal / count($records), 1) : 0,
                    'avg_hours_per_day' => $totalRecords > 0 ? round($hoursTotal / $totalRecords, 1) : 0,
                ],
                'records' => $records,
            ]);
        } catch (\Throwable $e) {
            \logger()->error('Attendance report error', ['error' => $e->getMessage()]);
            $this->error('Failed to generate attendance report: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/reports/appraisal - Performance Appraisal report.
     */
    public function appraisalAction(): void
    {
        $this->requirePermission('reports', 'view');

        $this->success([
            'summary' => [
                'total' => 0,
                'completed' => 0,
                'in_progress' => 0,
            ],
            'records' => [],
        ]);
    }

    /**
     * GET /api/reports/documentation - Employee document compliance report.
     */
    public function documentationAction(): void
    {
        $this->requirePermission('reports', 'view');

        try {
            $stmt = $this->db->prepare("
                SELECT ed.id, ed.document_name, ed.category, ed.uploaded_at,
                       e.first_name, e.last_name, e.employee_id as emp_code,
                       d.name as department_name
                FROM employee_documents ed
                LEFT JOIN employees e ON ed.employee_id = e.id
                LEFT JOIN departments d ON e.department_id = d.id
                ORDER BY ed.uploaded_at DESC
                LIMIT 1000
            ");
            $stmt->execute();
            $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $this->success([
                'summary' => [
                    'total_documents' => count($result),
                ],
                'records' => $result,
            ]);
        } catch (\Throwable $e) {
            \logger()->error('Documentation report error', ['error' => $e->getMessage()]);
            $this->error('Failed to generate documentation report: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/reports/{type}/export/{format} - Export report data.
     */
    public function exportAction(string $type, string $format): void
    {
        $this->requirePermission('reports', 'view');

        $type = strtolower($type);
        $format = strtolower($format);

        $filename = "{$type}_report_" . date('Ymd_His');

        // Optional bound parameters for filtered exports (attendance uses
        // start_date/end_date/department_id from the query string).
        $bindTypes = '';
        $bindParams = [];

        switch ($type) {
            case 'contracts':
                $headers = ['Contract ID', 'Departmental Objective', 'Strategic Plan', 'Goal Perspective',
                    'Organisational Goal', 'Department', 'Financial Year', 'KPI (KRA)',
                    'Workplan Objectives', 'Created'];
                $stmt = $this->db->prepare("
                    SELECT c.id, c.name, sp.name AS strategic_plan, g.name AS goal_perspective,
                           t.name AS organisational_goal, d.name AS department,
                           fy.year_name AS financial_year, c.kra,
                           (SELECT COUNT(*) FROM workplan_objectives w
                             WHERE w.performance_contract_id = c.id) AS objectives,
                           c.created_at
                    FROM performance_contracts c
                    LEFT JOIN strategic_plan sp ON c.strategic_plan_id = sp.id
                    LEFT JOIN goals g ON c.goal_id = g.id
                    LEFT JOIN strategic_targets t ON c.target_id = t.id
                    LEFT JOIN departments d ON c.department_id = d.id
                    LEFT JOIN financial_years fy ON c.financial_year_id = fy.id
                    ORDER BY sp.start_date DESC, g.name ASC, c.created_at DESC
                ");
                break;

            case 'employees':
                $headers = ['Employee ID', 'First Name', 'Last Name', 'Surname', 'Email', 'Phone', 'Gender', 'Employment Type', 'Status', 'Designation', 'Department', 'Section', 'Date of Birth', 'Contract Start', 'Contract End', 'National ID', 'Hire Date'];
                $stmt = $this->db->prepare("
                    SELECT e.employee_id, e.first_name, e.last_name, e.surname, e.email, e.phone, e.gender, e.employee_type, e.employee_status, e.designation, d.name as department, s.name as section, e.date_of_birth, e.contract_start_date, e.contract_end_date, e.national_id, e.hire_date
                    FROM employees e LEFT JOIN departments d ON e.department_id = d.id LEFT JOIN sections s ON e.section_id = s.id
                    ORDER BY e.first_name ASC
                ");
                break;

            case 'leave':
                $headers = ['Application ID', 'Employee Code', 'Employee Name', 'Leave Type', 'Start Date', 'End Date', 'Days', 'Status', 'Applied At'];
                $stmt = $this->db->prepare("
                    SELECT la.id, e.employee_id, CONCAT(e.first_name, ' ', e.last_name) as name, lt.name as leave_type, la.start_date, la.end_date, la.days_requested, la.status, la.applied_at
                    FROM leave_applications la
                    LEFT JOIN employees e ON la.employee_id = e.id
                    LEFT JOIN leave_types lt ON la.leave_type_id = lt.id
                    ORDER BY la.applied_at DESC
                ");
                break;

            case 'attendance':
                // Per-employee aggregate over the requested range (mirrors the
                // on-screen report), not raw rows.
                $headers = ['Employee No', 'Employee Name', 'Department', 'Days Present', 'Late Days',
                    'Auto Clock-outs', 'Missing Clock-out', 'Total Hours', 'Avg Hours/Day', 'First Date', 'Last Date'];
                $startDate = (($_GET['start_date'] ?? '') !== '') ? (string)$_GET['start_date'] : date('Y-m-01');
                $endDate = (($_GET['end_date'] ?? '') !== '') ? (string)$_GET['end_date'] : date('Y-m-d');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
                    $this->error('Invalid date range. Expected format: YYYY-MM-DD.', 422);
                    return;
                }
                $bindTypes = 'ss';
                $bindParams = [$startDate, $endDate];
                $stmt = $this->db->prepare("
                    SELECT e.employee_id AS emp_no,
                           CONCAT(e.first_name, ' ', e.last_name) AS name,
                           COALESCE(d.name, 'Unassigned') AS department,
                           COUNT(a.id) AS days_present,
                           SUM(CASE WHEN a.is_late = 1 THEN 1 ELSE 0 END) AS late_days,
                           SUM(CASE WHEN a.status = 'auto_clocked_out' THEN 1 ELSE 0 END) AS auto_days,
                           SUM(CASE WHEN a.clock_out IS NULL OR a.clock_out = '' THEN 1 ELSE 0 END) AS missing_out,
                           ROUND(SUM(CASE WHEN a.clock_in IS NOT NULL AND a.clock_in <> '' AND a.clock_out IS NOT NULL AND a.clock_out <> ''
                                   THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, a.clock_in, a.clock_out)) ELSE 0 END) / 60, 1) AS total_hours,
                           ROUND(SUM(CASE WHEN a.clock_in IS NOT NULL AND a.clock_in <> '' AND a.clock_out IS NOT NULL AND a.clock_out <> ''
                                   THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, a.clock_in, a.clock_out)) ELSE 0 END) / 60
                               / NULLIF(COUNT(a.id), 0), 1) AS avg_hours,
                           MIN(a.attendance_date) AS first_date,
                           MAX(a.attendance_date) AS last_date
                    FROM attendance a
                    LEFT JOIN employees e ON a.employee_id = e.id
                    LEFT JOIN departments d ON e.department_id = d.id
                    WHERE a.attendance_date BETWEEN ? AND ?
                    GROUP BY e.id, e.employee_id, e.first_name, e.last_name, d.name
                    ORDER BY days_present DESC, name ASC
                ");
                break;

            case 'documentation':
                $headers = ['Doc ID', 'Employee Code', 'Employee Name', 'Department', 'Document Title', 'Category', 'Uploaded At'];
                $stmt = $this->db->prepare("
                    SELECT ed.id, e.employee_id, CONCAT(e.first_name, ' ', e.last_name) as name, d.name as dept, ed.document_name, ed.category, ed.uploaded_at
                    FROM employee_documents ed
                    LEFT JOIN employees e ON ed.employee_id = e.id
                    LEFT JOIN departments d ON e.department_id = d.id
                    ORDER BY ed.uploaded_at DESC
                ");
                break;

            default:
                $headers = ['ID', 'Info', 'Timestamp'];
                $stmt = $this->db->prepare("SELECT id, name, created_at FROM departments");
                break;
        }

        if (!empty($bindTypes) && !empty($bindParams)) {
            $stmt->bind_param($bindTypes, ...$bindParams);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_NUM);
        $stmt->close();

        // Export as CSV (supports excel and csv requests)
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        // Add UTF-8 BOM for Excel compatibility
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, $headers);

        foreach ($rows as $row) {
            fputcsv($output, $row);
        }

        fclose($output);
        exit();
    }
/**
     * GET /api/reports/strategic-performance - Strategic & performance report
     * built from real data: plan progress, departmental performance, KPI
     * achievement and workplan/contract volumes. Permission-scoped.
     */
    public function strategicPerformanceAction(): void
    {
        $this->requirePermission('reports', 'view');
        $scope = \App\Helpers\OrgScope::current();
        $db = \App\Helpers\Database::getInstance()->getConnection();

        // Restrict to the caller's department unless they have broad access.
        $deptFilter = null;
        if (!$scope['is_hr'] && !$scope['is_super_admin'] && !$scope['is_pme_or_audit']) {
            $deptFilter = $scope['department_id'] ?? null;
        } elseif (isset($_GET['department_id']) && $_GET['department_id'] !== '') {
            $deptFilter = (int) $_GET['department_id'];
        }

        try {
            // ---- Plan progress ----
            $planProgress = [];
            $res = $db->query(
                "SELECT sp.id, sp.name, sp.start_date, sp.end_date,
                        (SELECT COUNT(*) FROM goals g WHERE g.strategic_plan_id = sp.id) AS goals,
                        (SELECT COUNT(*) FROM strategic_targets t WHERE t.strategic_plan_id = sp.id) AS targets,
                        (SELECT COUNT(*) FROM performance_contracts c WHERE c.strategic_plan_id = sp.id) AS contracts
                 FROM strategic_plan sp ORDER BY sp.start_date DESC"
            );
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $planProgress[] = $row;
                }
                $res->free();
            }

            // ---- Departmental performance (contracts/workplans/KPIs per dept) ----
            $params = [];
            $types  = '';
            $where  = '';
            if ($deptFilter !== null) {
                $where  = 'WHERE c.department_id = ?';
                $params[] = $deptFilter;
                $types   .= 'i';
            }

            $stmt = $db->prepare(
                "SELECT d.id AS department_id, d.name AS department,
                        COUNT(DISTINCT c.id) AS contracts,
                        (SELECT COUNT(*) FROM workplan_objectives w
                          JOIN performance_contracts c2 ON w.performance_contract_id = c2.id
                         WHERE c2.department_id = d.id " . ($deptFilter !== null ? 'AND c2.department_id = ?' : '') . ") AS workplans,
                        (SELECT COUNT(*) FROM kpis k
                          JOIN performance_contracts c3 ON k.performance_contract_id = c3.id
                         WHERE c3.department_id = d.id " . ($deptFilter !== null ? 'AND c3.department_id = ?' : '') . ") AS kpis
                 FROM departments d
                 LEFT JOIN performance_contracts c ON c.department_id = d.id
                 " . ($deptFilter !== null ? 'AND c.id IS NOT NULL' : '') . "
                 GROUP BY d.id, d.name
                 ORDER BY d.name ASC"
            );
            $departmental = [];
            if ($stmt) {
                if ($deptFilter !== null) {
                    $p = [$deptFilter, $deptFilter, $deptFilter];
                    $t = 'iii';
                    $stmt->bind_param($t, ...$p);
                }
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $departmental[] = $row;
                }
                $result->free();
                $stmt->close();
            }

            // ---- KPI achievement (only where scores are recorded) ----
            $kpiAchievement = [];
            $kpiRes = $db->query(
                "SELECT k.kpi_name, k.target,
                        COALESCE(NULLIF(k.y1_score,''), NULLIF(k.y2_score,''), NULLIF(k.y3_score,''),
                                 NULLIF(k.y4_score,''), NULLIF(k.y5_score,'')) AS latest_score,
                        c.name AS contract_name, d.name AS department_name
                 FROM kpis k
                 LEFT JOIN performance_contracts c ON k.performance_contract_id = c.id
                 LEFT JOIN departments d ON c.department_id = d.id
                 ORDER BY k.created_at DESC LIMIT 200"
            );
            if ($kpiRes) {
                while ($row = $kpiRes->fetch_assoc()) {
                    $kpiAchievement[] = $row;
                }
            }

            // ---- Workplan completion proxy: objectives with recorded Y-values vs total ----
            $totalWorkplans = (int) ($db->query('SELECT COUNT(*) AS c FROM workplan_objectives')->fetch_assoc()['c'] ?? 0);
            $withTargets = (int) ($db->query("SELECT COUNT(*) AS c FROM workplan_objectives WHERE COALESCE(Y1,Y2,Y3,Y4,Y5) IS NOT NULL AND CONCAT(COALESCE(Y1,''),COALESCE(Y2,''),COALESCE(Y3,''),COALESCE(Y4,''),COALESCE(Y5,'')) <> ''")->fetch_assoc()['c'] ?? 0);

            $this->success([
                'generated_at' => date('Y-m-d H:i:s'),
                'scope' => ['department_id' => $deptFilter],
                'plan_progress' => $planProgress,
                'departmental_performance' => $departmental,
                'kpi_achievement' => $kpiAchievement,
                'workplan_summary' => [
                    'total_objectives' => $totalWorkplans,
                    'with_year_targets' => $withTargets,
                    'note' => $withTargets === 0
                        ? 'No workplan year-targets captured yet.'
                        : null,
                ],
            ]);
        } catch (\Throwable $e) {
            \logger()->error('Strategic performance report error', ['error' => $e->getMessage()]);
            $this->error('Failed to generate the strategic performance report.', 500);
        }
    }
}
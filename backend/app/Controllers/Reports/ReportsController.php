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
                SELECT e.id, e.employee_id, e.first_name, e.last_name, e.email, e.phone,
                       e.gender, e.employee_status, e.employee_type, e.designation,
                       d.name as department_name, s.name as section_name,
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
            foreach ($result as $emp) {
                $dept = $emp['department_name'] ?: 'Unassigned';
                $byDepartment[$dept] = ($byDepartment[$dept] ?? 0) + 1;
            }

            $this->success([
                'summary' => [
                    'total' => $totalEmployees,
                    'active' => $activeCount,
                    'inactive' => $inactiveCount,
                    'by_department' => $byDepartment,
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
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-d');

            $stmt = $this->db->prepare("
                SELECT a.id, a.clock_in, a.clock_out, a.status, a.is_late,
                       DATE(a.clock_in) as date,
                       e.first_name, e.last_name, e.employee_id as emp_code,
                       d.name as department_name, o.name as office_name
                FROM attendance a
                LEFT JOIN employees e ON a.employee_id = e.id
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN offices o ON a.office_id = o.id
                WHERE DATE(a.clock_in) BETWEEN ? AND ?
                ORDER BY a.clock_in DESC
                LIMIT 2000
            ");
            $stmt->bind_param('ss', $startDate, $endDate);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $lateCount = count(array_filter($result, fn($r) => !empty($r['is_late'])));

            $this->success([
                'summary' => [
                    'total_records' => count($result),
                    'late_arrivals' => $lateCount,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
                'records' => $result,
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

        switch ($type) {
            case 'employees':
                $headers = ['Employee ID', 'First Name', 'Last Name', 'Email', 'Phone', 'Gender', 'Status', 'Designation', 'Department', 'Hire Date'];
                $stmt = $this->db->prepare("
                    SELECT e.employee_id, e.first_name, e.last_name, e.email, e.phone, e.gender, e.employee_status, e.designation, d.name as department, e.hire_date
                    FROM employees e LEFT JOIN departments d ON e.department_id = d.id
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
                $headers = ['Attendance ID', 'Employee Code', 'Employee Name', 'Department', 'Date', 'Clock In', 'Clock Out', 'Status', 'Is Late'];
                $stmt = $this->db->prepare("
                    SELECT a.id, e.employee_id, CONCAT(e.first_name, ' ', e.last_name) as name, d.name as dept, DATE(a.clock_in) as date, a.clock_in, a.clock_out, a.status, IF(a.is_late=1, 'Yes', 'No') as late
                    FROM attendance a
                    LEFT JOIN employees e ON a.employee_id = e.id
                    LEFT JOIN departments d ON e.department_id = d.id
                    ORDER BY a.clock_in DESC LIMIT 5000
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
}
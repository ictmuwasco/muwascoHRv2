<?php

declare(strict_types=1);

namespace App\Controllers\Reports;

use App\Controllers\BaseController;
use App\Helpers\OrgScope;
use App\Services\AttendanceReport\AttendanceReportService;

/**
 * AttendanceReportController
 *
 * HTTP layer for the Attendance Analytics & Reporting module. Every action
 * enforces the `reports:view` permission (`reports:export` for the CSV
 * download), resolves the caller's organisational scope via OrgScope and
 * delegates to the AttendanceReportService. Role-scoping is ALWAYS applied
 * server-side - frontend filters can never widen it.
 *
 * Routes (registered in api.php BEFORE the /reports/{type}/export/{format}
 * wildcard), mirroring the Leave Reports module:
 *   GET /reports/attendance/options
 *   GET /reports/attendance/summary
 *   GET /reports/attendance/trends
 *   GET /reports/attendance/by-status
 *   GET /reports/attendance/by-department
 *   GET /reports/attendance/late-arrivals
 *   GET /reports/attendance/working-hours
 *   GET /reports/attendance/insights
 *   GET /reports/attendance/compliance
 *   GET /reports/attendance/employees   (paginated per-employee summary)
 *   GET /reports/attendance/records     (drill-down daily rows, one employee)
 *   GET /reports/attendance/export      (CSV, all active filters applied)
 *
 * The legacy aggregated endpoint GET /reports/attendance on
 * ReportsController stays untouched for backward compatibility.
 */
class AttendanceReportController extends BaseController
{
    private AttendanceReportService $service;

    public function __construct()
    {
        $this->service = new AttendanceReportService();
    }

    /** Normalised filters + caller scope shared by every action. */
    private function context(): array
    {
        return [$this->service->query()->normalizeFilters($_GET), OrgScope::current()];
    }

    /**
     * GET /reports/attendance/options - filter dropdown values.
     */
    public function optionsAction(): void
    {
        $this->requirePermission('reports', 'view');
        $this->success($this->service->options(OrgScope::current()));
    }

    /**
     * GET /reports/attendance/summary - KPI cards.
     */
    public function summaryAction(): void
    {
        $this->requirePermission('reports', 'view');
        [$filters, $scope] = $this->context();
        $this->success($this->service->analytics()->summary($filters, $scope));
    }

    /**
     * GET /reports/attendance/trends - attendance trend (auto-grouped).
     */
    public function trendsAction(): void
    {
        $this->requirePermission('reports', 'view');
        [$filters, $scope] = $this->context();
        $this->success($this->service->analytics()->trends($filters, $scope));
    }

    /**
     * GET /reports/attendance/by-status - status distribution.
     */
    public function byStatusAction(): void
    {
        $this->requirePermission('reports', 'view');
        [$filters, $scope] = $this->context();
        $this->success($this->service->analytics()->byStatus($filters, $scope));
    }

    /**
     * GET /reports/attendance/by-department - departmental analysis.
     */
    public function byDepartmentAction(): void
    {
        $this->requirePermission('reports', 'view');
        [$filters, $scope] = $this->context();
        $this->success($this->service->analytics()->byDepartment($filters, $scope));
    }

    /**
     * GET /reports/attendance/late-arrivals - late arrival analysis.
     */
    public function lateArrivalsAction(): void
    {
        $this->requirePermission('reports', 'view');
        [$filters, $scope] = $this->context();
        $this->success($this->service->analytics()->lateArrivals($filters, $scope));
    }

    /**
     * GET /reports/attendance/working-hours - hours analysis + trend.
     */
    public function workingHoursAction(): void
    {
        $this->requirePermission('reports', 'view');
        [$filters, $scope] = $this->context();
        $this->success($this->service->analytics()->workingHours($filters, $scope));
    }

    /**
     * GET /reports/attendance/insights - dynamically computed insights.
     */
    public function insightsAction(): void
    {
        $this->requirePermission('reports', 'view');
        [$filters, $scope] = $this->context();
        $this->success($this->service->analytics()->insights($filters, $scope));
    }

    /**
     * GET /reports/attendance/compliance - compliance rate + series.
     */
    public function complianceAction(): void
    {
        $this->requirePermission('reports', 'view');
        [$filters, $scope] = $this->context();
        $this->success($this->service->compliance()->overview($filters, $scope));
    }

    /**
     * GET /reports/attendance/employees - paginated per-employee report.
     *
     * Query params: page, per_page (<=100), sort (whitelisted column),
     * dir (asc|desc), search (name / emp no / department).
     */
    public function employeesAction(): void
    {
        $this->requirePermission('reports', 'view');
        [$filters, $scope] = $this->context();

        [$page, $perPage] = $this->getPaginationParams();
        $sort   = (string) ($_GET['sort'] ?? 'days_present');
        $dir    = (string) ($_GET['dir'] ?? 'desc');
        $search = isset($_GET['search']) && $_GET['search'] !== ''
            ? (string) $_GET['search']
            : null;

        $this->success($this->service->records()->employeeSummary(
            $filters,
            $scope,
            $page,
            $perPage,
            $sort,
            $dir,
            $search
        ));
    }

    /**
     * GET /reports/attendance/records - drill-down daily rows for ONE
     * employee. employee_id arrives through the normalised filters so the
     * OrgScope pinning applies identically.
     */
    public function recordsAction(): void
    {
        $this->requirePermission('reports', 'view');
        [$filters, $scope] = $this->context();

        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 15)));

        $this->success($this->service->records()->dailyRecords($filters, $scope, $page, $perPage));
    }

    /**
     * GET /reports/attendance/export - CSV respecting all active filters.
     */
    public function exportAction(): void
    {
        $this->requirePermission('reports', 'export');

        [$filters, $scope] = $this->context();

        $filename = 'attendance_report_' . date('Ymd_His') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel

        $summary = $this->service->analytics()->summary($filters, $scope);
        [$headers, $rows] = $this->service->export()->employeeRows($filters, $scope);

        // --- Report metadata block ---
        fputcsv($out, ['Attendance Report']);
        fputcsv($out, ['Reporting Period', ($filters['from'] ?? '—') . ' to ' . ($filters['to'] ?? '—')]);
        fputcsv($out, ['Generated On:', date('Y-m-d H:i:s')]);
        fputcsv($out, ['Applied Filters:', $this->humanFilters($filters)]);
        fputcsv($out, []);

        // --- Summary metrics ---
        fputcsv($out, ['SUMMARY METRICS']);
        fputcsv($out, ['Attendance Records', $summary['attendance_records']]);
        fputcsv($out, ['Employees With Records', $summary['employees_with_records']]);
        fputcsv($out, ['Expected Working Days', $summary['expected_working_days']]);
        fputcsv($out, ['Present Days', $summary['present_days']]);
        fputcsv($out, ['Absent Days', $summary['absent_days']]);
        fputcsv($out, ['Leave Days', $summary['leave_days']]);
        fputcsv($out, ['Late Arrivals', $summary['late_arrivals']]);
        fputcsv($out, ['Missing Clock-Outs', $summary['missing_clockouts']]);
        fputcsv($out, ['Auto Clock-Outs', $summary['auto_clockouts']]);
        fputcsv($out, ['Total Working Hours', $summary['total_hours']]);
        fputcsv($out, ['Avg Hours Per Attendance Day', $summary['avg_hours_per_day']]);
        fputcsv($out, ['Attendance Compliance Rate', $summary['compliance_rate'] === null
            ? 'N/A' : $summary['compliance_rate'] . '%']);
        fputcsv($out, []);

        // --- Detailed records ---
        fputcsv($out, ['DETAILED REPORT']);
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }

        fclose($out);
        exit();
    }

    /** Human-readable applied-filter list for the export header. */
    private function humanFilters(array $filters): string
    {
        $parts = ['Period: ' . ($filters['from'] ?? '—') . ' to ' . ($filters['to'] ?? '—')];
        if (!empty($filters['department_id'])) {
            $parts[] = 'Department ID: ' . $filters['department_id'];
        }
        if (!empty($filters['office_id'])) {
            $parts[] = 'Office ID: ' . $filters['office_id'];
        }
        if (!empty($filters['employee_id'])) {
            $parts[] = 'Employee ID: ' . $filters['employee_id'];
        }
        if (!empty($filters['employee_type'])) {
            $parts[] = 'Employee Type: ' . implode(', ', (array) $filters['employee_type']);
        }
        if (!empty($filters['statuses'])) {
            $parts[] = 'Status: ' . implode(', ', $filters['statuses']);
        }
        if (!empty($filters['search'])) {
            $parts[] = 'Search: ' . $filters['search'];
        }
        return implode(' | ', $parts);
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Leave;

use App\Controllers\BaseController;
use App\Helpers\OrgScope;
use App\Services\LeaveReportService;

/**
 * LeaveReportController
 *
 * HTTP layer for the Leave Reports module. Each action enforces the
 * `reports:view` permission (and `reports:export` for the CSV download),
 * resolves the caller's organisational scope, then delegates to the
 * LeaveReportService. Role-scoping is always applied server-side.
 */
class LeaveReportController extends BaseController
{
    private LeaveReportService $service;

    public function __construct()
    {
        $this->service = new LeaveReportService();
    }

    /**
     * GET /reports/leave/options - filter dropdown values.
     */
    public function optionsAction(): void
    {
        $this->requirePermission('reports', 'view');
        $this->success($this->service->options(OrgScope::current()));
    }

    /**
     * GET /reports/leave/summary - KPI cards.
     */
    public function summaryAction(): void
    {
        $this->requirePermission('reports', 'view');
        $scope = OrgScope::current();
        $filters = $this->service->query()->normalizeFilters($_GET);
        $this->success($this->service->statistics()->summary($filters, $scope));
    }

    /**
     * GET /reports/leave/trends - applications trend (auto-grouped).
     */
    public function trendsAction(): void
    {
        $this->requirePermission('reports', 'view');
        $scope = OrgScope::current();
        $filters = $this->service->query()->normalizeFilters($_GET);
        $this->success($this->service->analytics()->trends($filters, $scope));
    }

    /**
     * GET /reports/leave/by-type - leave type distribution.
     */
    public function byTypeAction(): void
    {
        $this->requirePermission('reports', 'view');
        $scope = OrgScope::current();
        $filters = $this->service->query()->normalizeFilters($_GET);
        $this->success($this->service->analytics()->byType($filters, $scope));
    }

    /**
     * GET /reports/leave/by-department - departmental leave activity.
     */
    public function byDepartmentAction(): void
    {
        $this->requirePermission('reports', 'view');
        $scope = OrgScope::current();
        $filters = $this->service->query()->normalizeFilters($_GET);
        $this->success($this->service->analytics()->byDepartment($filters, $scope));
    }

    /**
     * GET /reports/leave/by-status - status distribution.
     */
    public function byStatusAction(): void
    {
        $this->requirePermission('reports', 'view');
        $scope = OrgScope::current();
        $filters = $this->service->query()->normalizeFilters($_GET);
        $this->success($this->service->analytics()->byStatus($filters, $scope));
    }

    /**
     * GET /reports/leave/duration - duration bucket analysis.
     */
    public function durationAction(): void
    {
        $this->requirePermission('reports', 'view');
        $scope = OrgScope::current();
        $filters = $this->service->query()->normalizeFilters($_GET);
        $this->success($this->service->analytics()->duration($filters, $scope));
    }

    /**
     * GET /reports/leave/insights - dynamically computed insights.
     */
    public function insightsAction(): void
    {
        $this->requirePermission('reports', 'view');
        $scope = OrgScope::current();
        $filters = $this->service->query()->normalizeFilters($_GET);
        $this->success($this->service->insights($filters, $scope));
    }

    /**
     * GET /reports/leave/records - server-paginated detail table.
     */
    public function recordsAction(): void
    {
        $this->requirePermission('reports', 'view');
        $scope = OrgScope::current();
        $filters = $this->service->query()->normalizeFilters($_GET);
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 15)));
        $this->success($this->service->records($filters, $scope, $page, $perPage));
    }

    /**
     * GET /reports/leave/export - CSV respecting all active filters.
     */
    public function exportAction(): void
    {
        $this->requirePermission('reports', 'export');

        $scope = OrgScope::current();
        $filters = $this->service->query()->normalizeFilters($_GET);

        $filename = 'leave_report_' . date('Ymd_His') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel

        $summary = $this->service->statistics()->summary($filters, $scope);
        [$headers, $rows] = $this->service->export()->records($filters, $scope);

        $basisLabels = ['applied_at' => 'Application Date', 'start_date' => 'Leave Start Date', 'end_date' => 'Leave End Date'];
        $basis = $basisLabels[$filters['date_basis']] ?? $filters['date_basis'];
        $from = $filters['from'] ?? '—';
        $to = $filters['to'] ?? '—';

        // --- Report metadata block ---
        fputcsv($out, ['Leave Report']);
        fputcsv($out, ['Reporting Period (' . $basis . ')', $from . ' to ' . $to]);
        fputcsv($out, ['Generated On:', date('Y-m-d H:i:s')]);
        fputcsv($out, ['Applied Filters:', $this->humanFilters($filters)]);
        fputcsv($out, []);

        // --- Summary metrics ---
        fputcsv($out, ['SUMMARY METRICS']);
        fputcsv($out, ['Total Applications', $summary['total_applications']]);
        fputcsv($out, ['Total Leave Days', $summary['total_days']]);
        fputcsv($out, ['Average Duration', number_format($summary['avg_duration'], 1) . ' days']);
        fputcsv($out, ['Approved', $summary['approved']]);
        fputcsv($out, ['Pending', $summary['pending']]);
        fputcsv($out, ['Rejected', $summary['rejected']]);
        fputcsv($out, ['Cancelled', $summary['cancelled']]);
        fputcsv($out, []);

        // --- Detailed records ---
        fputcsv($out, ['DETAILED RECORDS']);
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }

        fclose($out);
        exit();
    }

    private function humanFilters(array $filters): string
    {
        $parts = [];
        $basisLabels = ['applied_at' => 'Application Date', 'start_date' => 'Leave Start Date', 'end_date' => 'Leave End Date'];
        $parts[] = 'Date basis: ' . ($basisLabels[$filters['date_basis']] ?? $filters['date_basis']);
        if ($filters['from'] !== null) {
            $parts[] = 'From: ' . $filters['from'];
        }
        if ($filters['to'] !== null) {
            $parts[] = 'To: ' . $filters['to'];
        }
        if (!empty($filters['statuses'])) {
            $parts[] = 'Status: ' . implode(', ', $filters['statuses']);
        }
        if (!empty($filters['department_id'])) {
            $parts[] = 'Department ID: ' . $filters['department_id'];
        }
        if (!empty($filters['leave_type_id'])) {
            $parts[] = 'Leave Type ID: ' . $filters['leave_type_id'];
        }
        if (!empty($filters['financial_year_id'])) {
            $parts[] = 'Financial Year ID: ' . $filters['financial_year_id'];
        }
        return implode(' | ', $parts);
    }
}
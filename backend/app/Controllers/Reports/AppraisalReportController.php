<?php

declare(strict_types=1);

namespace App\Controllers\Reports;

use App\Controllers\BaseController;
use App\Services\Reports\AppraisalPerformanceReportService;

/**
 * AppraisalReportController
 *
 * HTTP layer for the company-wide Appraisal Reports module.
 *
 * Every action enforces `reports:view` (and `reports:export` for the CSV), then
 * delegates to AppraisalPerformanceReportService. All organisational scoping is
 * applied server-side inside that service, reusing the appraisal archive's own
 * scope rule - a frontend filter can never widen what a caller may see.
 *
 * Routes (registered in api.php BEFORE the /reports/{type}/export/{format}
 * wildcard, mirroring the Attendance and Leave modules):
 *   GET /reports/appraisal/options
 *   GET /reports/appraisal/summary
 *   GET /reports/appraisal/trends
 *   GET /reports/appraisal/by-department
 *   GET /reports/appraisal/by-section
 *   GET /reports/appraisal/by-subsection
 *   GET /reports/appraisal/by-status
 *   GET /reports/appraisal/performers
 *   GET /reports/appraisal/insights
 *   GET /reports/appraisal/employees   (paginated, sortable)
 *   GET /reports/appraisal/export      (CSV, all active filters applied)
 */
class AppraisalReportController extends BaseController
{
    private AppraisalPerformanceReportService $service;

    public function __construct()
    {
        $this->service = new AppraisalPerformanceReportService();
    }

    /**
     * Normalised filters from the query string.
     *
     * `status` accepts repeated or comma-separated values because the page
     * filters by a set of stages. `$_GET['status']` is cast to an array so a
     * scalar still works, and unknown values are dropped downstream.
     *
     * @return array<string,mixed>
     */
    private function filters(): array
    {
        $status = $_GET['status'] ?? [];
        if (!is_array($status)) {
            $status = explode(',', (string) $status);
        }

        return [
            'status'        => $status,
            'cycle_id'      => $_GET['cycle_id'] ?? null,
            'department_id' => $_GET['department_id'] ?? null,
            'section_id'    => $_GET['section_id'] ?? null,
            'subsection_id' => $_GET['subsection_id'] ?? null,
            'search'        => $_GET['search'] ?? $_GET['q'] ?? null,
        ];
    }

    /**
     * GET /reports/appraisal/options
     */
    public function optionsAction(): void
    {
        $this->requirePermission('reports', 'view');
        $this->success($this->service->options());
    }

    /**
     * GET /reports/appraisal/summary
     */
    public function summaryAction(): void
    {
        $this->requirePermission('reports', 'view');
        $this->success($this->service->summary($this->filters()));
    }

    /**
     * GET /reports/appraisal/trends
     */
    public function trendsAction(): void
    {
        $this->requirePermission('reports', 'view');
        $this->success($this->service->trends($this->filters()));
    }

    /**
     * GET /reports/appraisal/by-department
     */
    public function byDepartmentAction(): void
    {
        $this->requirePermission('reports', 'view');
        $this->success($this->service->byUnit('department', $this->filters()));
    }

    /**
     * GET /reports/appraisal/by-section
     */
    public function bySectionAction(): void
    {
        $this->requirePermission('reports', 'view');
        $this->success($this->service->byUnit('section', $this->filters()));
    }

    /**
     * GET /reports/appraisal/by-subsection
     */
    public function bySubsectionAction(): void
    {
        $this->requirePermission('reports', 'view');
        $this->success($this->service->byUnit('subsection', $this->filters()));
    }

    /**
     * GET /reports/appraisal/by-status
     */
    public function byStatusAction(): void
    {
        $this->requirePermission('reports', 'view');
        $this->success($this->service->byStatus($this->filters()));
    }

    /**
     * GET /reports/appraisal/performers
     */
    public function performersAction(): void
    {
        $this->requirePermission('reports', 'view');
        $this->success($this->service->performers($this->filters(), 10));
    }

    /**
     * GET /reports/appraisal/insights
     */
    public function insightsAction(): void
    {
        $this->requirePermission('reports', 'view');
        $this->success($this->service->insights($this->filters()));
    }

    /**
     * GET /reports/appraisal/employees
     */
    public function employeesAction(): void
    {
        $this->requirePermission('reports', 'view');
        $this->success($this->service->employees(
            $this->filters(),
            max(1, (int) ($_GET['page'] ?? 1)),
            (int) ($_GET['per_page'] ?? 25),
            (string) ($_GET['sort'] ?? 'percentage'),
            (string) ($_GET['dir'] ?? 'desc')
        ));
    }

    /**
     * GET /reports/appraisal/appraisals - one row per scored appraisal.
     *
     * The register is what the page's filters describe, so a filter visibly
     * changes a table and not only the charts above it.
     */
    public function appraisalsAction(): void
    {
        $this->requirePermission('reports', 'view');
        $this->success($this->service->appraisals(
            $this->filters(),
            max(1, (int) ($_GET['page'] ?? 1)),
            (int) ($_GET['per_page'] ?? 25),
            (string) ($_GET['sort'] ?? 'submitted'),
            (string) ($_GET['dir'] ?? 'desc')
        ));
    }

    /**
     * GET /reports/appraisal/export - CSV of the appraisal register.
     *
     * Streams the whole filtered set, not the visible page: an export that
     * silently stops at 25 rows is worse than no export at all. Matches the
     * table on screen rather than a different aggregation of it.
     */
    public function exportAction(): void
    {
        $this->requirePermission('reports', 'export');

        $csv = $this->service->exportRegister($this->filters());
        $filename = 'appraisal-report-' . date('Y-m-d') . '.csv';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        // A BOM so Excel opens the accented names in the African locale without
        // mangling them; harmless for every other consumer.
        echo "\xEF\xBB\xBF" . $csv;
        exit;
    }
}

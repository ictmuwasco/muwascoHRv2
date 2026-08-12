<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditService;

/**
 * AuditLogController
 *
 * REST API for the audit trail dashboard. All endpoints require the
 * `audit:view` permission (RBAC); export requires `audit:export`.
 *
 * Endpoints:
 *   GET /api/audit          - Paginated, searchable, filterable, sortable list
 *   GET /api/audit/{id}     - Single audit log with decoded JSON columns
 *   GET /api/audit/statistics - Summary cards
 *   GET /api/audit/filters  - Distinct values for filter dropdowns
 *   GET /api/audit/export   - CSV export honoring active filters (audit:export)
 */
class AuditLogController extends BaseController
{
    private AuditService $auditService;

    public function __construct()
    {
        $this->auditService = AuditService::getInstance();
    }

    /**
     * GET /api/audit
     */
    public function index(): void
    {
        $this->requirePermission('audit', 'view');

        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
        $sort    = $_GET['sort'] ?? 'created_at';
        $order   = $_GET['order'] ?? 'DESC';

        $filters = $this->auditFilters();

        $result = $this->auditService->search($filters, $page, $perPage, (string) $sort, (string) $order);
        $this->success($result, 'Audit logs retrieved successfully');
    }

    /**
     * GET /api/audit/{id}
     */
    public function show(int $id): void
    {
        $this->requirePermission('audit', 'view');

        $log = $this->auditService->getById($id);
        if ($log === null) {
            $this->notFound('Audit log not found');
        }

        $this->success($log);
    }

    /**
     * GET /api/audit/statistics
     */
    public function statistics(): void
    {
        $this->requirePermission('audit', 'view');

        $stats = $this->auditService->statistics();
        $this->success($stats, 'Audit statistics retrieved successfully');
    }

    /**
     * GET /api/audit/filters
     */
    public function filters(): void
    {
        $this->requirePermission('audit', 'view');

        $options = $this->auditService->getFilterOptions();
        $this->success($options, 'Audit filter options retrieved successfully');
    }

    /**
     * GET /api/audit/export?format=csv
     *
     * CSV export honoring the active filters. Emits an audit EXPORT event.
     */
    public function export(): void
    {
        $this->requirePermission('audit', 'export');

        $filters = $this->auditFilters();

        // Fetch up to a sane limit (pagination enforced; no unbounded exports).
        $result = $this->auditService->search($filters, 1, 1000, 'created_at', 'DESC');

        \App\Services\AuditService::getInstance()->log(
            \App\Services\AuditService::MODULE_AUDIT,
            \App\Services\AuditService::ACTION_EXPORT,
            'Exported audit logs (CSV)',
            ['metadata' => ['count' => $result['total'] ?? 0, 'filters' => $filters]]
        );

        // Build CSV from the flattened rows.
        $rows = $result['data'] ?? [];

        $filename = 'audit_logs_' . date('Y-m-d_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM for Excel compatibility
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, [
            'ID', 'Timestamp', 'User', 'Role', 'Action', 'Module',
            'Description', 'Target Type', 'Target ID', 'Target Name',
            'IP Address', 'Location', 'Status',
        ]);

        foreach ($rows as $row) {
            fputcsv($out, [
                $row['id'] ?? '',
                $row['created_at'] ?? '',
                $row['user_name_snapshot'] ?? '',
                $row['user_role_snapshot'] ?? '',
                $row['action'] ?? '',
                $row['module'] ?? '',
                $row['description'] ?? '',
                $row['target_type'] ?? '',
                $row['target_id'] ?? '',
                $row['target_name'] ?? '',
                $row['ip_address'] ?? '',
                $row['location'] ?? '',
                $row['status'] ?? '',
            ]);
        }

        fclose($out);
        exit;
    }

    /**
     * Normalize audit query filters from the request.
     */
    private function auditFilters(): array
    {
        $filters = [];
        foreach ([
            'action', 'module', 'user_id', 'user_name_snapshot', 'user_role_snapshot',
            'status', 'target_type', 'target_id', 'date_from', 'date_to', 'search',
        ] as $key) {
            if (isset($_GET[$key]) && $_GET[$key] !== '') {
                $filters[$key] = $_GET[$key];
            }
        }
        return $filters;
    }
}
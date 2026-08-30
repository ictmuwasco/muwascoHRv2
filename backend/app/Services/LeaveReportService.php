<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;

/**
 * LeaveReportService
 *
 * Top-level orchestrator for the Leave Reports module. It owns the shared
 * filter + scope resolution and delegates to the statistics / analytics /
 * export services, plus provides paginated detail records, filter-dropdown
 * options and dynamically computed insights.
 */
final class LeaveReportService
{
    private LeaveReportQueryService    $queryService;
    private LeaveReportStatisticsService $statisticsService;
    private LeaveReportAnalyticsService  $analyticsService;
    private LeaveReportExportService     $exportService;

    public function __construct(?LeaveReportQueryService $queryService = null)
    {
        $this->queryService     = $queryService ?? new LeaveReportQueryService();
        $this->statisticsService = new LeaveReportStatisticsService($this->queryService);
        $this->analyticsService  = new LeaveReportAnalyticsService($this->queryService);
        $this->exportService     = new LeaveReportExportService($this->queryService);
    }

    public function query(): LeaveReportQueryService
    {
        return $this->queryService;
    }

    public function statistics(): LeaveReportStatisticsService
    {
        return $this->statisticsService;
    }

    public function analytics(): LeaveReportAnalyticsService
    {
        return $this->analyticsService;
    }

    public function export(): LeaveReportExportService
    {
        return $this->exportService;
    }

    /**
     * Ranked lists for the filter dropdowns (departments, leave types,
     * financial years, statuses) so the UI never hard-codes the data.
     */
    public function options(array $scope): array
    {
        $db = Database::getInstance()->getConnection();

        $departments = [];
        $res = $db->query('SELECT id, name FROM departments ORDER BY name ASC');
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $departments[] = ['id' => (int) $row['id'], 'name' => $row['name']];
            }
        }

        $leaveTypes = [];
        $lt = $db->query('SELECT id, name FROM leave_types ORDER BY name ASC');
        if ($lt) {
            while ($row = $lt->fetch_assoc()) {
                $leaveTypes[] = ['id' => (int) $row['id'], 'name' => $row['name']];
            }
        }

        $financialYears = [];
        $fy = $db->query('SELECT id, year_name FROM financial_years ORDER BY start_date DESC');
        if ($fy) {
            while ($row = $fy->fetch_assoc()) {
                $financialYears[] = ['id' => (int) $row['id'], 'year_name' => $row['year_name']];
            }
        }

        // Scoped (non-HR) users are pinned to their own department if resolved.
        $deptFilter = ($scope['department_id'] ?? null) ? (int) $scope['department_id'] : null;
        if ($deptFilter !== null && !(($scope['is_hr'] ?? false) || ($scope['is_super_admin'] ?? false))) {
            $departments = array_values(array_filter($departments, function ($d) use ($deptFilter) {
                return $d['id'] === $deptFilter;
            }));
        }

        return [
            'departments'     => $departments,
            'leave_types'     => $leaveTypes,
            'financial_years' => $financialYears,
            'statuses'        => ['pending', 'approved', 'rejected', 'cancelled', 'invalidated'],
        ];
    }

    /**
     * Server-paginated detail records for the report table.
     *
     * @return array{items: array, total: int, page: int, per_page: int, last_page: int}
     */
    public function records(array $filters, array $scope, int $page = 1, int $perPage = 20): array
    {
        [$where, $types, $params] = $this->queryService->buildWhere($filters, $scope);
        $types = $types ?: null;

        $db = Database::getInstance()->getConnection();

        $countStmt = $db->prepare("SELECT COUNT(*) AS c FROM leave_applications la JOIN employees e ON la.employee_id = e.id LEFT JOIN departments d ON e.department_id = d.id {$where}");
        if (!$countStmt) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'last_page' => 1];
        }
        if ($types) {
            $countStmt->bind_param($types, ...$params);
        }
        $countStmt->execute();
        $countRow = $countStmt->get_result()->fetch_assoc();
        $countStmt->close();
        $total = $countRow ? (int) $countRow['c'] : 0;

        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;

        $sql = "
            SELECT la.id AS id,
                   e.employee_id AS employee_number,
                   CONCAT(COALESCE(e.first_name,''), ' ', COALESCE(e.last_name,'')) AS employee_name,
                   COALESCE(d.name, 'Unassigned') AS department,
                   lt.name AS leave_type,
                   lt.id AS leave_type_id,
                   la.start_date, la.end_date, la.days_requested AS days,
                   la.status, la.applied_at, la.approved_at, la.rejection_reason
            FROM leave_applications la
            JOIN employees e ON la.employee_id = e.id
            JOIN leave_types lt ON la.leave_type_id = lt.id
            LEFT JOIN departments d ON e.department_id = d.id
            {$where}
            ORDER BY la.applied_at DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return ['items' => [], 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => $lastPage];
        }
        $allParams = array_merge($params, [$perPage, $offset]);
        $allTypes = ($types ?: '') . 'ii';
        $stmt->bind_param($allTypes, ...$allParams);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id'              => (int) $row['id'],
                'employee_number' => $row['employee_number'],
                'employee_name'   => $row['employee_name'],
                'department'      => $row['department'],
                'leave_type'      => $row['leave_type'],
                'leave_type_id'   => (int) $row['leave_type_id'],
                'start_date'      => $row['start_date'],
                'end_date'        => $row['end_date'],
                'days'            => (int) $row['days'],
                'status'          => $row['status'],
                'applied_at'      => $row['applied_at'],
                'approved_at'     => $row['approved_at'],
            ];
        }

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => $lastPage,
        ];
    }

    /**
     * Dynamically computed insight statements (never hard-coded).
     */
    public function insights(array $filters, array $scope): array
    {
        $summary = $this->statisticsService->summary($filters, $scope);
        $depts    = $this->analyticsService->byDepartment($filters, $scope);
        $types    = $this->analyticsService->byType($filters, $scope);
        $trend    = $this->analyticsService->trends($filters, $scope);

        $insights = [];
        $total = $summary['total_applications'];

        if ($total === 0) {
            return ['No leave activity matches the current filters.'];
        }

        // Top month by applications.
        $monthCounts = [];
        foreach ($trend['points'] as $pt) {
            $mo = substr((string) $pt['label'], 0, 7);
            $monthCounts[$mo] = ($monthCounts[$mo] ?? 0) + (int) $pt['count'];
        }
        if ($monthCounts) {
            arsort($monthCounts);
            $peakMonth = (string) array_key_first($monthCounts);
            $insights[] = sprintf(
                'Highest leave activity occurred in %s (%d applications).',
                date('F Y', strtotime($peakMonth . '-01')),
                (int) reset($monthCounts)
            );
        }

        // Top department by leave days.
        if ($depts) {
            usort($depts, fn($a, $b) => (int) ($b['days'] ?? 0) <=> (int) ($a['days'] ?? 0));
            $topDept = $depts[0];
            $insights[] = sprintf(
                '%s recorded the most leave days (%d).',
                $topDept['department'] ?? 'Unassigned',
                (int) ($topDept['days'] ?? 0)
            );
        }

        // Most requested leave type.
        if ($types) {
            $topType = $types[0];
            $insights[] = sprintf(
                '%s is the most requested leave type (%d applications).',
                $topType['leave_type'] ?? 'Unspecified',
                (int) ($topType['count'] ?? 0)
            );
        }

        // Pending attention.
        if ($summary['pending'] > 0) {
            $insights[] = sprintf('%d application(s) are currently pending approval.', $summary['pending']);
        }

        // Approval rate.
        $insights[] = sprintf('Approval rate for the selected period is %.1f%%.', $summary['approval_rate']);

        // Compare to previous period.
        $prev = $this->statisticsService->previousPeriod($filters, $scope);
        $previous = (int) ($prev['previous_applications'] ?? 0);
        if ($total > 0 && $previous > 0) {
            $delta = round((($total - $previous) / $previous) * 100, 1);
            $word = $delta >= 0 ? 'increased' : 'decreased';
            $insights[] = sprintf(
                'Leave applications %s by %s%% compared with the previous period (%d vs %d).',
                $word, abs($delta), $total, $previous
            );
        }

        return array_slice($insights, 0, 6);
    }
}
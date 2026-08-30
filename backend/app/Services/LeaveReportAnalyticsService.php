<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;

/**
 * LeaveReportAnalyticsService
 *
 * Provides the visualisation datasets for the Leave Report: an applications
 * trend (grouping that adapts to the selected period), distribution by leave
 * type, distribution by department, distribution by status, and a duration
 * bucket breakdown. All aggregates are computed in SQL.
 */
final class LeaveReportAnalyticsService
{
    private const DURATION_BUCKETS = [
        '1-2 days'    => [1, 2],
        '3-5 days'    => [3, 5],
        '6-10 days'   => [6, 10],
        '>10 days'    => [11, PHP_INT_MAX],
    ];

    public function __construct(
        private LeaveReportQueryService $queryService
    ) {
    }

    /**
     * Applications trend. The grouping (daily / weekly / monthly) adapts to the
     * width of the selected range:
     *   < 32 days  -> daily
     *   < 190 days -> weekly
     *   otherwise  -> monthly
     */
    public function trends(array $filters, array $scope): array
    {
        [$where, $bindTypes, $params] = $this->queryService->buildWhere($filters, $scope);
        $bindTypes = $bindTypes ?: null;

        $from = isset($filters['from']) ? new \DateTimeImmutable($filters['from']) : null;
        $to   = isset($filters['to'])   ? new \DateTimeImmutable($filters['to']) : null;
        $days = ($from !== null && $to !== null) ? ($from->diff($to)->days + 1) : 0;

        $grouping = $this->resolveGrouping($days);

        if ($grouping === 'monthly') {
            $expr = "DATE_FORMAT(la.{$filters['date_basis']}, '%Y-%m') AS label";
        } elseif ($grouping === 'weekly') {
            $expr = "DATE(la.{$filters['date_basis']}) AS label";
        } else {
            $expr = "DATE(la.{$filters['date_basis']}) AS label";
        }

        $sql = "
            SELECT {$expr},
                   COUNT(*) AS count,
                   COALESCE(SUM(days_requested), 0) AS days
            FROM leave_applications la
            JOIN employees e ON la.employee_id = e.id
            LEFT JOIN departments d ON e.department_id = d.id
            {$where}
            GROUP BY label
            ORDER BY label ASC
        ";

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return ['grouping' => $grouping, 'points' => []];
        }
        if ($bindTypes) {
            $stmt->bind_param($bindTypes, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        // Post-process: collapse daily points into weeks when grouped weekly.
        if ($grouping === 'weekly') {
            $rows = $this->collapseToWeeks($rows);
        }

        $points = [];
        foreach ($rows as $row) {
            $points[] = [
                'label' => $row['label'],
                'count' => (int) $row['count'],
                'days'  => (int) ($row['days'] ?? 0),
            ];
        }

        return ['grouping' => $grouping, 'points' => $points];
    }

    private function collapseToWeeks(array $rows): array
    {
        $weeks = [];
        foreach ($rows as $row) {
            $dt = new \DateTimeImmutable($row['label']);
            $key = $dt->format('o-\WW');
            if (!isset($weeks[$key])) {
                $weeks[$key] = ['label' => $dt->format('Y-m-d'), 'count' => 0, 'days' => 0];
            }
            $weeks[$key]['count'] += (int) $row['count'];
            $weeks[$key]['days']  += (int) ($row['days'] ?? 0);
        }
        ksort($weeks);
        return array_values($weeks);
    }

    private function resolveGrouping(int $days): string
    {
        if ($days > 0 && $days < 190) {
            return $days < 32 ? 'daily' : 'weekly';
        }
        return 'monthly';
    }

    public function byType(array $filters, array $scope): array
    {
        [$where, $bindTypes, $params] = $this->queryService->buildWhere($filters, $scope);
        $bindTypes = $bindTypes ?: null;

        $sql = "
            SELECT lt.name AS leave_type, lt.id AS leave_type_id,
                   COUNT(*) AS count,
                   COALESCE(SUM(days_requested), 0) AS days
            FROM leave_applications la
            JOIN leave_types lt ON la.leave_type_id = lt.id
            JOIN employees e ON la.employee_id = e.id
            LEFT JOIN departments d ON e.department_id = d.id
            {$where}
            GROUP BY lt.id, lt.name
            ORDER BY count DESC, lt.name ASC
        ";

        return $this->fetchList($sql, $bindTypes, $params);
    }

    public function byDepartment(array $filters, array $scope): array
    {
        [$where, $bindTypes, $params] = $this->queryService->buildWhere($filters, $scope);
        $bindTypes = $bindTypes ?: null;

        $sql = "
            SELECT COALESCE(d.name, 'Unassigned') AS department,
                   COUNT(*) AS count,
                   COALESCE(SUM(days_requested), 0) AS days
            FROM leave_applications la
            JOIN employees e ON la.employee_id = e.id
            LEFT JOIN departments d ON e.department_id = d.id
            {$where}
            GROUP BY COALESCE(d.name, 'Unassigned')
            ORDER BY count DESC, department ASC
        ";

        return $this->fetchList($sql, $bindTypes, $params);
    }

    public function byStatus(array $filters, array $scope): array
    {
        [$where, $bindTypes, $params] = $this->queryService->buildWhere($filters, $scope);
        $bindTypes = $bindTypes ?: null;

        $sql = "
            SELECT CASE
                     WHEN la.status = 'approved' THEN 'approved'
                     WHEN la.status IN ('pending','pending_subsection_head','pending_section_head',
                                        'pending_dept_head','pending_managing_director',
                                        'pending_bod_chair','pending_manager') THEN 'pending'
                     WHEN la.status = 'rejected' THEN 'rejected'
                     WHEN la.status = 'cancelled' THEN 'cancelled'
                     WHEN la.status = 'invalidated' THEN 'invalidated'
                     ELSE la.status
                   END AS status,
                   COUNT(*) AS count
            FROM leave_applications la
            JOIN employees e ON la.employee_id = e.id
            LEFT JOIN departments d ON e.department_id = d.id
            {$where}
            GROUP BY status
            ORDER BY count DESC
        ";

        return $this->fetchList($sql, $bindTypes, $params);
    }

    public function duration(array $filters, array $scope): array
    {
        [$where, $bindTypes, $params] = $this->queryService->buildWhere($filters, $scope);
        $bindTypes = $bindTypes ?: null;

        $sql = "
            SELECT COUNT(*) AS count, days_requested
            FROM leave_applications la
            JOIN employees e ON la.employee_id = e.id
            LEFT JOIN departments d ON e.department_id = d.id
            {$where}
            GROUP BY days_requested
        ";

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if ($bindTypes) {
            $stmt->bind_param($bindTypes, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        $buckets = [];
        foreach (self::DURATION_BUCKETS as $name => $range) {
            $buckets[$name] = 0;
        }
        foreach ($rows as $row) {
            $d = (int) $row['days_requested'];
            foreach (self::DURATION_BUCKETS as $name => [$min, $max]) {
                if ($d >= $min && $d <= $max) {
                    $buckets[$name]++;
                    break;
                }
            }
        }

        $out = [];
        foreach ($buckets as $name => $count) {
            $out[] = ['bucket' => $name, 'count' => $count];
        }

        return $out;
    }

    private function fetchList(string $sql, ?string $bindTypes, array $params): array
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if ($bindTypes) {
            $stmt->bind_param($bindTypes, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        $out = [];
        foreach ($rows as $row) {
            foreach ($row as $key => $val) {
                if (is_numeric($val)) {
                    $row[$key] = $val * 1;
                }
            }
            $out[] = $row;
        }
        return $out;
    }
}
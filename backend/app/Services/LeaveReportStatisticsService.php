<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;

/**
 * LeaveReportStatisticsService
 *
 * Computes the KPI cards for the Leave Report. Aggregates are calculated in
 * SQL (COUNT/SUM/AVG) rather than loading every row into PHP.
 */
final class LeaveReportStatisticsService
{
    public function __construct(
        private LeaveReportQueryService $queryService
    ) {
    }

    /**
     * @return array<string,mixed> KPIs for the filtered scope.
     */
    public function summary(array $filters, array $scope): array
    {
        [$where, $types, $params] = $this->queryService->buildWhere($filters, $scope);
        $types = !$types ? null : $types;

        $sql = "
            SELECT
                COUNT(*)                                AS total_applications,
                COALESCE(SUM(days_requested), 0)        AS total_days,
                COALESCE(AVG(days_requested), 0)        AS avg_duration,
                COALESCE(SUM(CASE WHEN la.status = 'approved' THEN 1 ELSE 0 END), 0)        AS approved,
                COALESCE(SUM(CASE WHEN la.status IN ('pending','pending_subsection_head',
                            'pending_section_head','pending_dept_head','pending_managing_director',
                            'pending_bod_chair','pending_manager') THEN 1 ELSE 0 END), 0)    AS pending,
                COALESCE(SUM(CASE WHEN la.status = 'rejected' THEN 1 ELSE 0 END), 0)         AS rejected,
                COALESCE(SUM(CASE WHEN la.status = 'cancelled' THEN 1 ELSE 0 END), 0)        AS cancelled,
                COALESCE(SUM(CASE WHEN la.status = 'invalidated' THEN 1 ELSE 0 END), 0)      AS invalidated
            FROM leave_applications la
            JOIN employees e ON la.employee_id = e.id
            JOIN leave_types lt ON la.leave_type_id = lt.id
            LEFT JOIN departments d ON e.department_id = d.id
            {$where}
        ";

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return $this->emptySummary();
        }
        if ($types) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return $this->emptySummary();
        }

        $total = (int) $row['total_applications'];
        $approved = (int) $row['approved'];
        $pending = (int) $row['pending'];
        $rejected = (int) $row['rejected'];
        $cancelled = (int) $row['cancelled'];
        $invalidated = (int) $row['invalidated'];
        $totalDays = (float) $row['total_days'];
        $avgDuration = round((float) $row['avg_duration'], 1);

        $decided = $total - $pending;
        $approvedPct = $total > 0 ? round(($approved / $total) * 100, 1) : 0.0;
        $rejectedPct = $total > 0 ? round(($rejected / $total) * 100, 1) : 0.0;
        $approvalRate = $decided > 0 ? round(($approved / $decided) * 100, 1) : 0.0;

        return [
            'total_applications' => $total,
            'total_days'         => $totalDays,
            'avg_duration'       => $avgDuration,
            'approved'           => $approved,
            'pending'            => $pending,
            'rejected'           => $rejected,
            'cancelled'          => $cancelled,
            'invalidated'        => $invalidated,
            'approved_pct'       => $approvedPct,
            'rejected_pct'       => $rejectedPct,
            'approval_rate'      => $approvalRate,
            'has_pending'        => $pending > 0,
        ];
    }

    /**
     * Summary for the immediately previous, equally-sized period (used for
     * "compared to previous period" trend readouts).
     */
    public function previousPeriod(array $filters, array $scope): array
    {
        if (($filters['from'] ?? null) === null || ($filters['to'] ?? null) === null) {
            return ['total_applications' => 0];
        }

        $from = new \DateTimeImmutable($filters['from']);
        $to   = new \DateTimeImmutable($filters['to']);
        $diff = $from->diff($to);
        $span = $diff->days + 1;

        $prevFrom = $from->modify("-{$span} days");
        $prevTo   = $from->modify("-1 day");

        $prev = $filters;
        $prev['from'] = $prevFrom->format('Y-m-d');
        $prev['to']   = $prevTo->format('Y-m-d');

        $cur = $this->summary($filters, $scope);

        return [
            'total_applications' => $cur['total_applications'],
            'previous_applications' => $this->countOnly($prev, $scope),
        ];
    }

    private function countOnly(array $filters, array $scope): int
    {
        [$where, $types, $params] = $this->queryService->buildWhere($filters, $scope);
        $types = $types ?: null;

        $db = \App\Helpers\Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM leave_applications la JOIN employees e ON la.employee_id = e.id LEFT JOIN departments d ON e.department_id = d.id {$where}");
        if (!$stmt) {
            return 0;
        }
        if ($types) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (int) $row['c'] : 0;
    }

    private function emptySummary(): array
    {
        return [
            'total_applications' => 0,
            'total_days'         => 0,
            'avg_duration'       => 0.0,
            'approved'           => 0,
            'pending'            => 0,
            'rejected'           => 0,
            'cancelled'          => 0,
            'invalidated'        => 0,
            'approved_pct'       => 0.0,
            'rejected_pct'       => 0.0,
            'approval_rate'      => 0.0,
            'has_pending'        => false,
        ];
    }
}
<?php

declare(strict_types=1);

namespace App\Services\AttendanceReport;

use App\Helpers\Database;

/**
 * AttendanceReportRecordsService
 *
 * Server-side detail layer for the Attendance Analytics & Reporting module:
 *
 *  - aggregateEmployees(): ONE per-employee aggregate for the filtered range.
 *    Record counters (present/late/auto/missing/hours) come from a single
 *    GROUP BY query in MySQL; expected/absent/on-leave days come from the
 *    analytics service's calendar math (weekends, holidays, approved leave,
 *    hire dates) so the detail table can never disagree with the KPI cards.
 *
 *  - employeeSummary(): aggregateEmployees() + search + whitelisted sorting
 *    + server-side pagination. Only the requested page slice ever reaches
 *    the UI - the full attendance dataset is never transferred.
 *
 *  - dailyRecords(): paginated drill-down of ONE employee's underlying
 *    attendance rows (date, clock-in/out, hours, resolved status label),
 *    sharing the exact same filter pipeline.
 *
 * Record-status lenses (present|late|missing|auto) filter the record-level
 * counters in SQL; the calendar-derived statuses (absent|on_leave) filter the
 * computed per-employee day counts in PHP, since no attendance row exists for
 * a day an employee never showed up.
 */
final class AttendanceReportRecordsService
{
    /** Whitelisted sort columns (anything else falls back to the default). */
    private const SORTABLE = [
        'emp_no', 'name', 'department', 'office', 'days_present', 'absent_days',
        'leave_days', 'late_days', 'auto_days', 'missing_out', 'total_hours',
        'avg_hours', 'attendance_rate',
    ];

    private const DEFAULT_SORT = 'days_present';

    private AttendanceReportQueryService $query;
    private AttendanceReportAnalyticsService $analytics;
    private Database $db;

    public function __construct(
        AttendanceReportQueryService $query,
        AttendanceReportAnalyticsService $analytics
    ) {
        $this->query     = $query;
        $this->analytics = $analytics;
        $this->db        = Database::getInstance();
    }

    /**
     * Paginated per-employee attendance summary.
     *
     * @return array{items:array,total:int,page:int,per_page:int,last_page:int,sort:string,dir:string}
     */
    public function employeeSummary(
        array $filters,
        array $scope,
        int $page = 1,
        int $perPage = 25,
        string $sort = self::DEFAULT_SORT,
        string $dir = 'desc',
        ?string $search = null
    ): array {
        $rows = $this->aggregateEmployees($filters, $scope);

        // Search narrows the DETAIL table only (never the org-wide expected
        // math), mirroring the analytics service's policy.
        if ($search !== null && $search !== '') {
            $needle = mb_strtolower(trim($search));
            $rows = array_values(array_filter(
                $rows,
                static fn (array $r): bool => str_contains(mb_strtolower((string) $r['name']), $needle)
                    || str_contains(mb_strtolower((string) $r['emp_no']), $needle)
                    || str_contains(mb_strtolower((string) $r['department']), $needle)
            ));
        }

        // Whitelisted, direction-aware sort. attendance_rate may be null
        // (employees with no expected days) - nulls always sink to the bottom.
        $sort = in_array($sort, self::SORTABLE, true) ? $sort : self::DEFAULT_SORT;
        $dir  = strtolower($dir) === 'asc' ? 'asc' : 'desc';
        $sign = $dir === 'asc' ? 1 : -1;
        usort($rows, static function (array $a, array $b) use ($sort, $sign): int {
            $va = $a[$sort];
            $vb = $b[$sort];
            if ($va === null && $vb === null) {
                return strcasecmp((string) $a['name'], (string) $b['name']);
            }
            if ($va === null) {
                return 1;
            }
            if ($vb === null) {
                return -1;
            }
            $cmp = is_numeric($va) && is_numeric($vb)
                ? ((float) $va <=> (float) $vb) * $sign
                : strcasecmp((string) $va, (string) $vb) * $sign;
            return $cmp !== 0 ? $cmp : strcasecmp((string) $a['name'], (string) $b['name']);
        });

        $total    = count($rows);
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $page     = min(max(1, $page), $lastPage);
        $offset   = ($page - 1) * $perPage;

        return [
            'items'     => array_slice($rows, $offset, max(1, $perPage)),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => $lastPage,
            'sort'      => $sort,
            'dir'       => $dir,
        ];
    }

    /**
     * Full per-employee aggregate (no pagination). One row per employee that
     * either matches the org filters or holds at least one attendance record
     * in the range, so employees with only absences are never invisible.
     *
     * @return array<int,array<string,mixed>>
     */
    public function aggregateEmployees(array $filters, array $scope): array
    {
        $filters = $this->analytics->capRange($filters);
        $from    = $filters['from'];
        $to      = $filters['to'];

        [$whereBase, $typesBase, $paramsBase] = $this->query->buildWhere($filters, $scope);

        // Record-level status lens (present|late|missing|auto) applies ONLY to
        // the record counters, never to the calendar baseline.
        [$statusSql, $sTypes, $sParams] = $this->query->recordStatusCondition($filters['statuses'] ?? []);
        $whereLens  = $whereBase . ($statusSql !== '' ? ' AND ' . $statusSql : '');
        $typesLens  = $typesBase . $sTypes;
        $paramsLens = array_merge($paramsBase, $sParams);

        // 1) Per-employee record counters (ONE grouped query, lens-aware).
        $totals = [];
        foreach ($this->db->fetchAll(
            "SELECT a.employee_id,
                    COUNT(a.id) AS days_present,
                    COALESCE(SUM(a.is_late = 1), 0) AS late_days,
                    COALESCE(SUM(a.auto_clocked_out = 1), 0) AS auto_days,
                    COALESCE(SUM(a.auto_clocked_out = 0 AND (a.clock_out IS NULL OR a.clock_out = '')), 0) AS missing_out,
                    COALESCE(SUM(CASE WHEN a.clock_in IS NOT NULL AND a.clock_in <> '' AND a.clock_out IS NOT NULL AND a.clock_out <> ''
                                 THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, a.clock_in, a.clock_out)) ELSE 0 END), 0) AS minutes
             FROM attendance a
             JOIN employees e ON a.employee_id = e.id
             WHERE $whereLens
             GROUP BY a.employee_id",
            $typesLens,
            $paramsLens
        ) as $row) {
            $totals[(int) $row['employee_id']] = $row;
        }

        // 2) Lens-free presence per employee per date (absence baseline).
        $presence = [];
        foreach ($this->db->fetchAll(
            "SELECT a.employee_id, a.attendance_date AS d, COUNT(a.id) AS n
             FROM attendance a
             JOIN employees e ON a.employee_id = e.id
             WHERE $whereBase
             GROUP BY a.employee_id, a.attendance_date",
            $typesBase,
            $paramsBase
        ) as $row) {
            $presence[(int) $row['employee_id']][(string) $row['d']] = (int) $row['n'];
        }

        // 3) Employee roster matching the org filters (with hire date +
        //    status so expected/absent math can respect both).
        [$ewhere, $etypes, $eparams] = $this->analytics->employeeWhere($filters, $scope);
        $empSql = "SELECT e.id, e.employee_id AS emp_no,
                          CONCAT(e.first_name, ' ', COALESCE(e.last_name, '')) AS name,
                          COALESCE(d.name, 'Unassigned') AS department,
                          COALESCE(o.name, '') AS office,
                          e.employee_status, e.hire_date
                   FROM employees e
                   LEFT JOIN departments d ON e.department_id = d.id
                   LEFT JOIN offices o ON e.office_id = o.id";
        $empTypes  = '';
        $empParams = [];
        if ($ewhere !== '') {
            $empSql   .= " WHERE $ewhere";
            $empTypes  = $etypes;
            $empParams = $eparams;
        }
        $employees = $this->db->fetchAll($empSql, $empTypes, $empParams);

        // Employees holding records but no longer matching the roster query
        // (e.g. resigned mid-period) still deserve their row.
        $known = [];
        foreach ($employees as $e) {
            $known[(int) $e['id']] = true;
        }
        foreach (array_keys($totals) as $empId) {
            if (!isset($known[$empId])) {
                $extra = $this->db->fetchOne(
                    "SELECT e.id, e.employee_id AS emp_no,
                            CONCAT(e.first_name, ' ', COALESCE(e.last_name, '')) AS name,
                            COALESCE(d.name, 'Unassigned') AS department,
                            COALESCE(o.name, '') AS office,
                            e.employee_status, e.hire_date
                     FROM employees e
                     LEFT JOIN departments d ON e.department_id = d.id
                     LEFT JOIN offices o ON e.office_id = o.id
                     WHERE e.id = ?
                     LIMIT 1",
                    'i',
                    [$empId]
                );
                if ($extra !== null) {
                    $employees[]   = $extra;
                    $known[$empId] = true;
                }
            }
        }

        // 4) Calendar + approved-leave context (shared with the analytics).
        $calendar = $this->analytics->buildCalendar($from, $to);
        $leaves   = $this->analytics->leavesInRange($filters, $scope);
        $leavesByEmp = [];
        foreach ($leaves as $l) {
            $leavesByEmp[(int) $l['employee_id']][] = $l;
        }

        $expectedByEmp   = [];
        $leaveByEmp      = [];
        $absentByEmp     = [];
        $presentAllByEmp = [];
        foreach ($presence as $empId => $dates) {
            $presentAllByEmp[$empId] = array_sum($dates);
        }

        // 5) Calendar-aware per-employee expected/leave/absent day counts:
        //    working days only (weekends + holidays skipped), hire-date aware,
        //    active employees only - exactly like the KPI cards.
        foreach ($calendar as $date => $cal) {
            if ($cal['weekend'] || $cal['holiday'] !== null) {
                continue;
            }
            foreach ($employees as $e) {
                $empId = (int) $e['id'];
                if (($e['employee_status'] ?? '') !== 'active') {
                    continue; // only active employees are "expected"
                }
                $hire = $e['hire_date'] ?? null;
                if ($hire !== null && $hire !== '' && (string) $hire > $date) {
                    continue; // not yet hired on this day
                }
                $expectedByEmp[$empId] = ($expectedByEmp[$empId] ?? 0) + 1;

                $onLeave = false;
                foreach ($leavesByEmp[$empId] ?? [] as $l) {
                    if ($l['start_date'] <= $date && $l['end_date'] >= $date) {
                        $onLeave = true;
                        break;
                    }
                }
                if ($onLeave) {
                    $leaveByEmp[$empId] = ($leaveByEmp[$empId] ?? 0) + 1;
                } elseif (($presence[$empId][$date] ?? 0) === 0) {
                    $absentByEmp[$empId] = ($absentByEmp[$empId] ?? 0) + 1;
                }
            }
        }

        // 6) Assemble rows.
        $rows = [];
        foreach ($employees as $e) {
            $empId      = (int) $e['id'];
            $t          = $totals[$empId] ?? null;
            $expected   = $expectedByEmp[$empId] ?? 0;
            $leaveDays  = $leaveByEmp[$empId] ?? 0;
            $absent     = $absentByEmp[$empId] ?? 0;
            $presentAll = $presentAllByEmp[$empId] ?? 0;
            $minutes    = (int) ($t['minutes'] ?? 0);
            $presentCnt = (int) ($t['days_present'] ?? 0);

            $rows[] = [
                'employee_id'   => $empId,
                'emp_no'        => (string) ($e['emp_no'] ?? ''),
                'name'          => (string) ($e['name'] ?? ''),
                'department'    => (string) ($e['department'] ?? 'Unassigned'),
                'office'        => (string) ($e['office'] ?? ''),
                'expected_days' => $expected,
                // days_present honours the record-status lens; the attendance
                // rate below always uses the full presence baseline.
                'days_present'  => $presentCnt,
                'absent_days'   => $absent,
                'leave_days'    => $leaveDays,
                'late_days'     => (int) ($t['late_days'] ?? 0),
                'auto_days'     => (int) ($t['auto_days'] ?? 0),
                'missing_out'   => (int) ($t['missing_out'] ?? 0),
                'total_hours'   => round($minutes / 60, 1),
                'avg_hours'     => $presentCnt > 0 ? round($minutes / 60 / $presentCnt, 1) : 0.0,
                'attendance_rate' => $expected > 0
                    ? round(min(100, $presentAll / $expected * 100), 1)
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * Paginated drill-down: the underlying attendance rows for ONE employee
     * inside the filtered range. employee_id must be present in $filters.
     *
     * @return array{items:array,total:int,page:int,per_page:int,last_page:int,employee:?array}
     */
    public function dailyRecords(array $filters, array $scope, int $page = 1, int $perPage = 15): array
    {
        $filters = $this->analytics->capRange($filters);

        if (empty($filters['employee_id'])) {
            return [
                'items' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage,
                'last_page' => 1, 'employee' => null,
            ];
        }

        [$where, $types, $params] = $this->query->buildWhere($filters, $scope);
        [$statusSql, $sTypes, $sParams] = $this->query->recordStatusCondition($filters['statuses'] ?? []);
        if ($statusSql !== '') {
            $where  .= ' AND ' . $statusSql;
            $types  .= $sTypes;
            $params  = array_merge($params, $sParams);
        }

        $total = (int) ($this->db->fetchOne(
            "SELECT COUNT(a.id) AS n
             FROM attendance a
             JOIN employees e ON a.employee_id = e.id
             WHERE $where",
            $types,
            $params
        )['n'] ?? 0);

        $perPage  = min(max(1, $perPage), 100);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page     = min(max(1, $page), $lastPage);
        $offset   = ($page - 1) * $perPage;

        $items = [];
        foreach ($this->db->fetchAll(
            "SELECT a.id, a.attendance_date, a.clock_in, a.clock_out, a.is_late,
                    a.auto_clocked_out,
                    CASE WHEN a.clock_in IS NOT NULL AND a.clock_in <> '' AND a.clock_out IS NOT NULL AND a.clock_out <> ''
                         THEN ROUND(GREATEST(0, TIMESTAMPDIFF(MINUTE, a.clock_in, a.clock_out)) / 60, 1)
                         ELSE NULL END AS hours
             FROM attendance a
             JOIN employees e ON a.employee_id = e.id
             WHERE $where
             ORDER BY a.attendance_date DESC, a.id DESC
             LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset,
            $types,
            $params
        ) as $row) {
            $items[] = [
                'id'               => (int) $row['id'],
                'attendance_date'  => (string) $row['attendance_date'],
                'clock_in'         => $row['clock_in'] ?: null,
                'clock_out'        => $row['clock_out'] ?: null,
                'hours'            => $row['hours'] !== null ? (float) $row['hours'] : null,
                'is_late'          => (int) $row['is_late'] === 1,
                'auto_clocked_out' => (int) $row['auto_clocked_out'] === 1,
                'status_label'     => $this->statusLabel((array) $row),
            ];
        }

        // Employee header for the drawer (single small query).
        $employee = $this->db->fetchOne(
            "SELECT e.id, e.employee_id AS emp_no,
                    CONCAT(e.first_name, ' ', COALESCE(e.last_name, '')) AS name,
                    COALESCE(d.name, 'Unassigned') AS department,
                    COALESCE(o.name, '') AS office
             FROM employees e
             LEFT JOIN departments d ON e.department_id = d.id
             LEFT JOIN offices o ON e.office_id = o.id
             WHERE e.id = ?
             LIMIT 1",
            'i',
            [(int) $filters['employee_id']]
        );

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => $lastPage,
            'employee'  => $employee ?: null,
        ];
    }

    /** Authoritative display label for one attendance row (dashboard semantics). */
    private function statusLabel(array $row): string
    {
        if ((int) ($row['auto_clocked_out'] ?? 0) === 1) {
            return 'Auto Clock-Out';
        }
        if ($row['clock_out'] === null || $row['clock_out'] === '') {
            return 'Missing Clock-Out';
        }
        if ((int) ($row['is_late'] ?? 0) === 1) {
            return 'Late';
        }
        return 'Present';
    }
}

<?php

declare(strict_types=1);

namespace App\Services\AttendanceReport;

use App\Helpers\Database;
use App\Services\AttendanceDashboardService;
use App\Services\CalendarContextService;

/**
 * AttendanceReportAnalyticsService
 *
 * Computes the aggregate analytics slices behind the Attendance Analytics &
 * Reporting dashboard: KPI summary, attendance trend, status distribution,
 * department analysis, late-arrival analysis, working hours and dynamic
 * insights.
 *
 * Design rules (mirrors the LeaveReport analytics services):
 *  - ALL aggregation happens in MySQL; no raw attendance rows reach the UI.
 *  - The org calendar (weekends, holidays, approved leave) comes from the
 *    authoritative CalendarContextService, and record interpretation follows
 *    AttendanceDashboardService's status semantics.
 *  - Absence is NEVER "employees minus records": per working day it is
 *    expected - present - on-leave, skipping weekends/holidays and counting
 *    only active employees hired on or before that date.
 */
final class AttendanceReportAnalyticsService
{
    /** Hard cap so a runaway custom range cannot freeze the server. */
    private const MAX_RANGE_DAYS = 366;

    /** Late days at which an employee counts as a repeat late offender. */
    public const REPEAT_LATE_THRESHOLD = 3;

    private AttendanceReportQueryService $query;
    private CalendarContextService $calendar;
    private AttendanceDashboardService $dashboard;
    private Database $db;

    public function __construct(
        AttendanceReportQueryService $query,
        CalendarContextService $calendar,
        AttendanceDashboardService $dashboard
    ) {
        $this->query     = $query;
        $this->calendar  = $calendar;
        $this->dashboard = $dashboard;
        $this->db        = Database::getInstance();
    }

    // ===============================================================
    // KPI summary
    // ===============================================================

    /**
     * Headline KPIs for the selected period (cards + export header).
     */
    public function summary(array $filters, array $scope): array
    {
        $filters = $this->capRange($filters);
        $from    = $filters['from'];
        $to      = $filters['to'];

        // Record counters honour the record-status lens; the calendar baseline
        // (presence / absence / leave) always stays lens-free so a status
        // filter can never inflate the absence or leave figures.
        $daily    = $this->dailyAggregate($filters, $scope);
        $dailyAll = $this->dailyAggregate($filters, $scope, false);
        $leaves   = $this->leavesInRange($filters, $scope);
        $pack     = $this->expectedAndAbsent($filters, $scope, $dailyAll, $leaves);

        $records     = 0;
        $lateTotal   = 0;
        $auto        = 0;
        $missing     = 0;
        $hours       = 0.0;
        foreach ($daily as $row) {
            $records   += (int) $row['records'];
            $lateTotal += (int) $row['late_total'];
            $auto      += (int) $row['auto_closed'];
            $missing   += (int) $row['missing_only'];
            $hours     += (float) $row['work_hours'];
        }

        // True presence baseline (independent of any record-status lens) so
        // present_days / compliance stay accurate under a status filter.
        $recordsAll = 0;
        foreach ($dailyAll as $row) {
            $recordsAll += (int) $row['records'];
        }

        $employeesWithRecords = $this->distinctEmployees($filters, $scope);
        $employeesOnLeave     = count(array_unique(array_map(
            static fn (array $l): int => (int) $l['employee_id'],
            $leaves
        )));

        return [
            'start_date'             => $from,
            'end_date'               => $to,
            'grouping'               => $this->groupingFor($from, $to),
            'range_days'             => count($this->buildCalendar($from, $to)),
            'holidays_in_range'      => $this->countHolidays($from, $to),

            'attendance_records'     => $records,
            'employees_with_records' => $employeesWithRecords,
            'employees_on_leave'     => $employeesOnLeave,
            'late_arrivals'          => $lateTotal,
            'auto_clockouts'         => $auto,
            'missing_clockouts'      => $missing,
            'total_hours'            => round($hours, 1),
            'avg_hours_per_day'      => $records > 0 ? round($hours / $records, 1) : 0,
            'avg_hours_per_employee' => $employeesWithRecords > 0 ? round($hours / $employeesWithRecords, 1) : 0,

            'expected_working_days'  => $pack['expected'],
            'present_days'           => $recordsAll,
            'leave_days'             => $pack['leave_days'],
            'absent_days'            => $pack['absent'],
            'compliance_rate'        => $pack['compliance_rate'],
        ];
    }

    /**
     * Expected working days / leave days / absent days / compliance rate for
     * the range. Weekend and holiday dates are excluded entirely; expected
     * employees per date respect hire dates and the active-status filter.
     */
    private function expectedAndAbsent(array $filters, array $scope, array $daily, array $leaves): array
    {
        $presentByDate = [];
        foreach ($daily as $row) {
            $presentByDate[$row['d']] = (int) $row['records'];
        }

        $employees = $this->activeEmployees($filters, $scope);
        $calendar  = $this->buildCalendar($filters['from'], $filters['to']);

        $expected  = 0;
        $leaveDays = 0;
        $absent    = 0;
        foreach (array_keys($calendar) as $date) {
            $cal = $calendar[$date];
            if ($cal['weekend'] || $cal['holiday'] !== null) {
                continue; // org calendar: nobody is expected on these days
            }
            $exp = $this->expectedOn($employees, $date);
            if ($exp === 0) {
                continue;
            }
            $lv      = $this->leaveCoverageOn($leaves, $date);
            $present = $presentByDate[$date] ?? 0;
            $expected  += $exp;
            $leaveDays += $lv;
            $absent    += max(0, $exp - $present - $lv);
        }

        return [
            'expected'        => $expected,
            'leave_days'      => $leaveDays,
            'absent'          => $absent,
            'compliance_rate' => $expected > 0 ? round(($expected - $absent) / $expected * 100, 1) : null,
        ];
    }

    // ===============================================================
    // Range + org-calendar helpers
    // ===============================================================

    /**
     * Clamp the reporting range: swap inverted dates, keep a sane floor and
     * cap the span so a runaway custom range cannot freeze the server.
     * Public: shared with the records/export services.
     */
    public function capRange(array $filters): array
    {
        $from = $filters['from'] ?? null;
        $to   = $filters['to'] ?? null;
        if (!$from || !$to) {
            $from = date('Y-m-01');
            $to   = date('Y-m-d');
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $floor = new \DateTimeImmutable('2000-01-01');
        $start = \DateTimeImmutable::createFromFormat('Y-m-d', $from) ?: $floor;
        $end   = \DateTimeImmutable::createFromFormat('Y-m-d', $to) ?: $start;

        if ($start < $floor) {
            $start = $floor;
        }
        if ($end < $start) {
            $end = $start;
        }
        if ((int) $start->diff($end)->days > self::MAX_RANGE_DAYS) {
            $end = $start->modify('+' . self::MAX_RANGE_DAYS . ' days');
        }

        $filters['from'] = $start->format('Y-m-d');
        $filters['to']   = $end->format('Y-m-d');
        return $filters;
    }

    /**
     * Trend grouping for the range: short periods show daily points, longer
     * ones roll up to weeks, and year-long ranges to months.
     */
    private function groupingFor(string $from, string $to): string
    {
        $days = (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1;
        if ($days <= 31) {
            return 'daily';
        }
        if ($days <= 120) {
            return 'weekly';
        }
        return 'monthly';
    }

    /**
     * Materialise the org calendar for the range in ONE query (the
     * per-date CalendarContextService would issue one lookup per day).
     * Semantics mirror CalendarContextService exactly:
     *   - weekend  = ISO weekday 6/7 (Sat/Sun);
     *   - holiday  = exact `holidays.date` match, or a recurring match on
     *     month-day regardless of year.
     *
     * @return array<string,array{weekend:bool,holiday:?string}> keyed by Y-m-d
     */
    public function buildCalendar(string $from, string $to): array
    {
        $rows = $this->db->fetchAll(
            "SELECT name, date, is_recurring
             FROM holidays
             WHERE (date BETWEEN ? AND ?) OR is_recurring = 1",
            'ss',
            [$from, $to]
        );

        $exact = [];
        $recurring = [];
        foreach ($rows as $row) {
            if ((int) $row['is_recurring'] === 1) {
                $md = date('m-d', strtotime((string) $row['date']));
                $recurring[$md] = (string) $row['name'];
            } else {
                $exact[(string) $row['date']] = (string) $row['name'];
            }
        }

        $calendar = [];
        $cursor = new \DateTimeImmutable($from);
        $end    = new \DateTimeImmutable($to);
        while ($cursor <= $end) {
            $date = $cursor->format('Y-m-d');
            $calendar[$date] = [
                'weekend' => (int) $cursor->format('N') >= 6,
                'holiday' => $exact[$date] ?? $recurring[$cursor->format('m-d')] ?? null,
            ];
            $cursor = $cursor->modify('+1 day');
        }
        return $calendar;
    }

    /** Number of public holidays falling inside the range. */
    private function countHolidays(string $from, string $to): int
    {
        $count = 0;
        foreach ($this->buildCalendar($from, $to) as $day) {
            if ($day['holiday'] !== null) {
                $count++;
            }
        }
        return $count;
    }

    // ===============================================================
    // Shared single-query data helpers
    // ===============================================================

    /**
     * Employee-only WHERE fragment for queries that start from
     * `employees e` (no attendance join): applies department, office,
     * single-employee and employee-type filters plus OrgScope pinning.
     *
     * @return array{0:string,1:string,2:array} [clause, bindTypes, bindParams]
     */
    public function employeeWhere(array $filters, array $scope): array
    {
        $where  = [];
        $types  = '';
        $params = [];

        $departmentId = $filters['department_id'] ?? null;
        if ($departmentId === null) {
            $departmentId = $this->query->scopeDepartmentId($scope);
        }
        if ($departmentId !== null) {
            $where[]  = 'e.department_id = ?';
            $types   .= 'i';
            $params[] = (int) $departmentId;
        }
        if (!empty($filters['office_id'])) {
            $where[]  = 'e.office_id = ?';
            $types   .= 'i';
            $params[] = (int) $filters['office_id'];
        }
        if (!empty($filters['employee_id'])) {
            $where[]  = 'e.id = ?';
            $types   .= 'i';
            $params[] = (int) $filters['employee_id'];
        }
        if (!empty($filters['employee_type'])) {
            $list = is_array($filters['employee_type'])
                ? $filters['employee_type']
                : [$filters['employee_type']];
            if (count($list) > 1) {
                $where[]  = 'e.employee_type IN (' . implode(',', array_fill(0, count($list), '?')) . ')';
                $types   .= str_repeat('s', count($list));
                $params   = array_merge($params, $list);
            } else {
                $where[]  = 'e.employee_type = ?';
                $types   .= 's';
                $params[] = (string) $list[0];
            }
        }

        return [implode(' AND ', $where), $types, $params];
    }

    /**
     * ONE grouped query for the whole range: per-date record count, late /
     * auto-closed / missing-clock-out counts and worked hours. Record-level
     * status filters (present|late|missing|auto) are applied here.
     *
     * @return array<int,array{d:string,records:int,late_total:int,auto_closed:int,missing_only:int,work_hours:float}>
     */
    private function dailyAggregate(array $filters, array $scope, bool $withStatusLens = true): array
    {
        [$where, $types, $params]     = $this->query->buildWhere($filters, $scope);
        $statuses                     = is_array($filters['statuses'] ?? null) ? $filters['statuses'] : [];
        [$statusSql, $sTypes, $sParams] = $withStatusLens
            ? $this->query->recordStatusCondition($statuses)
            : ['', '', []];

        $sql = "SELECT a.attendance_date AS d,
                       COUNT(a.id) AS records,
                       SUM(CASE WHEN a.is_late = 1 THEN 1 ELSE 0 END) AS late_total,
                       SUM(CASE WHEN a.auto_clocked_out = 1 THEN 1 ELSE 0 END) AS auto_closed,
                       SUM(CASE WHEN a.auto_clocked_out = 0 AND (a.clock_out IS NULL OR a.clock_out = '') THEN 1 ELSE 0 END) AS missing_only,
                       ROUND(SUM(CASE WHEN a.clock_in IS NOT NULL AND a.clock_in <> '' AND a.clock_out IS NOT NULL AND a.clock_out <> ''
                                 THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, a.clock_in, a.clock_out)) ELSE 0 END) / 60, 2) AS work_hours
                FROM attendance a
                LEFT JOIN employees e ON a.employee_id = e.id
                WHERE $where";
        if ($statusSql !== '') {
            $sql    .= " AND $statusSql";
            $types  .= $sTypes;
            $params  = array_merge($params, $sParams);
        }
        $sql .= ' GROUP BY a.attendance_date ORDER BY a.attendance_date ASC';

        return array_map(
            static fn (array $r): array => [
                'd'            => (string) $r['d'],
                'records'      => (int) $r['records'],
                'late_total'   => (int) $r['late_total'],
                'auto_closed'  => (int) $r['auto_closed'],
                'missing_only' => (int) $r['missing_only'],
                'work_hours'   => (float) $r['work_hours'],
            ],
            $this->db->fetchAll($sql, $types, $params)
        );
    }

    /** Distinct employees with at least one attendance record in the range. */
    private function distinctEmployees(array $filters, array $scope): int
    {
        [$where, $types, $params] = $this->query->buildWhere($filters, $scope);
        $row = $this->db->fetchOne(
            "SELECT COUNT(DISTINCT a.employee_id) AS n
             FROM attendance a
             LEFT JOIN employees e ON a.employee_id = e.id
             WHERE $where",
            $types,
            $params
        );
        return (int) ($row['n'] ?? 0);
    }

    /**
     * Approved leave applications overlapping the range for the filtered
     * employee set. One row per application; coverage per date is resolved
     * in leaveCoverageOn().
     *
     * @return array<int,array{employee_id:int,start_date:string,end_date:string,department_id:?int}>
     */
    public function leavesInRange(array $filters, array $scope): array
    {
        [$ewhere, $etypes, $eparams] = $this->employeeWhere($filters, $scope);

        $sql = "SELECT la.employee_id, la.start_date, la.end_date, e.department_id
                FROM leave_applications la
                INNER JOIN employees e ON la.employee_id = e.id
                WHERE la.status = 'approved'
                  AND la.start_date <= ?
                  AND la.end_date   >= ?";
        $types  = 'ss' . $etypes;
        $params = array_merge([$filters['to'], $filters['from']], $eparams);
        if ($ewhere !== '') {
            $sql .= " AND $ewhere";
        }

        return array_map(
            static fn (array $r): array => [
                'employee_id'   => (int) $r['employee_id'],
                'start_date'    => (string) $r['start_date'],
                'end_date'      => (string) $r['end_date'],
                'department_id' => $r['department_id'] !== null ? (int) $r['department_id'] : null,
            ],
            $this->db->fetchAll($sql, $types, $params)
        );
    }

    /** How many of the in-range approved leaves cover the given date. Public: shared with the records service. */
    public function leaveCoverageOn(array $leaves, string $date): int
    {
        $n = 0;
        foreach ($leaves as $l) {
            if ($l['start_date'] <= $date && $l['end_date'] >= $date) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Active employees matching the org filters — the expected-workforce
     * baseline. Search is intentionally NOT applied here: it narrows the
     * detail table, not the organisation's expected attendance.
     *
     * @return array<int,array{id:int,department_id:?int,hire_date:?string}>
     */
    public function activeEmployees(array $filters, array $scope): array
    {
        [$ewhere, $etypes, $eparams] = $this->employeeWhere($filters, $scope);

        $sql    = "SELECT e.id, e.department_id, e.hire_date
                   FROM employees e
                   WHERE e.employee_status = 'active'";
        $types  = '';
        $params = [];
        if ($ewhere !== '') {
            $sql    .= " AND $ewhere";
            $types   = $etypes;
            $params  = $eparams;
        }

        return $this->db->fetchAll($sql, $types, $params);
    }

    /** Employees expected to work on $date: active and hired on/before it. Public: shared with the records service. */
    public function expectedOn(array $employees, string $date): int
    {
        $n = 0;
        foreach ($employees as $e) {
            $hire = $e['hire_date'] ?? null;
            if ($hire === null || $hire === '' || $hire <= $date) {
                $n++;
            }
        }
        return $n;
    }

    // ===============================================================
    // Public analytics slices (controller-facing)
    // ===============================================================

    /**
     * Attendance trend across the range. Grouping follows the range length
     * (daily <= 31 days, weekly <= 120 days, monthly beyond) and every point
     * carries present/late/missing/auto/hours plus calendar-aware on-leave
     * and absent counts (weekends and holidays are excluded from the
     * expected-workforce metrics, mirroring summary()).
     *
     * @return array{grouping:string, points:array<int,array>}
     */
    public function trends(array $filters, array $scope): array
    {
        $filters  = $this->capRange($filters);
        $from     = $filters['from'];
        $to       = $filters['to'];
        $grouping = $this->groupingFor($from, $to);
        $calendar = $this->buildCalendar($from, $to);

        // Per-date metrics from ONE grouped query. Presence feeds the
        // calendar-derived present/absent/on-leave series and therefore uses
        // the lens-free baseline; late/missing/auto counters honour the
        // record-status lens when one is active.
        $presentByDate = [];
        $lateByDate    = [];
        $missingByDate = [];
        $autoByDate    = [];
        $hoursByDate   = [];
        $dailyAll  = $this->dailyAggregate($filters, $scope, false);
        $dailyLens = !empty($filters['statuses'])
            ? $this->dailyAggregate($filters, $scope)
            : $dailyAll;
        foreach ($dailyAll as $row) {
            $presentByDate[(string) $row['d']] = (int) $row['records'];
        }
        foreach ($dailyLens as $row) {
            $d = (string) $row['d'];
            $lateByDate[$d]    = (int) $row['late_total'];
            $missingByDate[$d] = (int) $row['missing_only'];
            $autoByDate[$d]    = (int) $row['auto_closed'];
            $hoursByDate[$d]   = (float) $row['work_hours'];
        }

        $employees = $this->activeEmployees($filters, $scope);
        $leaves    = $this->leavesInRange($filters, $scope);

        // Bucket key: the date itself, the week's Monday, or the month.
        $bucketOf = static function (string $date) use ($grouping): string {
            if ($grouping === 'monthly') {
                return substr($date, 0, 7);
            }
            if ($grouping === 'weekly') {
                return (new \DateTimeImmutable($date))->modify('monday this week')->format('Y-m-d');
            }
            return $date;
        };

        $points = [];
        foreach (array_keys($calendar) as $date) {
            $cal = $calendar[$date];
            $key = $bucketOf($date);

            if (!isset($points[$key])) {
                $points[$key] = [
                    'label'    => $key,
                    'present'  => 0,
                    'late'     => 0,
                    'missing'  => 0,
                    'auto'     => 0,
                    'on_leave' => 0,
                    'absent'   => 0,
                    'hours'    => 0.0,
                ];
            }

            $points[$key]['present'] += $presentByDate[$date] ?? 0;
            $points[$key]['late']    += $lateByDate[$date] ?? 0;
            $points[$key]['missing'] += $missingByDate[$date] ?? 0;
            $points[$key]['auto']    += $autoByDate[$date] ?? 0;
            $points[$key]['hours']   += $hoursByDate[$date] ?? 0.0;

            if ($cal['weekend'] || $cal['holiday'] !== null) {
                continue; // expected-workforce metrics skip weekends/holidays
            }
            $exp  = $this->expectedOn($employees, $date);
            $lv   = $this->leaveCoverageOn($leaves, $date);
            $pres = $presentByDate[$date] ?? 0;
            $points[$key]['on_leave'] += $lv;
            if ($exp > 0) {
                $points[$key]['absent'] += max(0, $exp - $pres - $lv);
            }
        }

        return [
            'grouping' => $grouping,
            'points'   => array_values($points),
        ];
    }

    /**
     * Attendance status distribution for the range. Record-level buckets are
     * deliberately computed WITHOUT the status filter so the distribution
     * always shows the full picture; absence/on-leave come from the same
     * calendar-aware computation as the summary card.
     *
     * @return array<int,array{status:string,count:int,hours?:float}>
     */
    public function byStatus(array $filters, array $scope): array
    {
        $filters = $this->capRange($filters);
        // Drop BOTH keys: 'status' (raw) and 'statuses' (the normalised list
        // dailyAggregate() actually reads) so the distribution always shows
        // the full picture regardless of the active record-status lens.
        unset($filters['status'], $filters['statuses']);

        $daily   = $this->dailyAggregate($filters, $scope);
        $leaves  = $this->leavesInRange($filters, $scope);

        $present = 0;
        $late    = 0;
        $auto    = 0;
        $missing = 0;
        $hours   = 0.0;
        foreach ($daily as $row) {
            $present += (int) $row['records'];
            $late    += (int) $row['late_total'];
            $auto    += (int) $row['auto_closed'];
            $missing += (int) $row['missing_only'];
            $hours   += (float) $row['work_hours'];
        }

        $pack = $this->expectedAndAbsent($filters, $scope, $daily, $leaves);

        return [
            ['status' => 'present',          'count' => $present, 'hours' => round($hours, 1)],
            ['status' => 'late',             'count' => $late],
            ['status' => 'missing_clockout', 'count' => $missing],
            ['status' => 'auto_clockout',    'count' => $auto],
            ['status' => 'on_leave',         'count' => $pack['leave_days']],
            ['status' => 'absent',           'count' => $pack['absent']],
        ];
    }

    // ===============================================================
    // Department analysis
    // ===============================================================

    /**
     * Calendar-aware per-department breakdown: attendance records, late /
     * auto-closed / missing counters, approved leave days, expected working
     * days and absence - so departments can be compared on real compliance
     * rather than raw record counts.
     *
     * Like byStatus(), the record-status lens is deliberately NOT applied:
     * the analysis always shows the full picture for the active
     * organisational filters (date range, department, office, employee, type).
     *
     * @return array<int,array<string,mixed>>
     */
    public function byDepartment(array $filters, array $scope): array
    {
        $filters = $this->capRange($filters);
        unset($filters['status']);
        $from = $filters['from'];
        $to   = $filters['to'];

        // ONE grouped query: department x day record counters.
        [$where, $types, $params] = $this->query->buildWhere($filters, $scope);
        $sql = "SELECT e.department_id AS dept_id, a.attendance_date AS d,
                       COUNT(a.id) AS records,
                       COALESCE(SUM(a.is_late = 1), 0) AS late_total,
                       COALESCE(SUM(a.auto_clocked_out = 1), 0) AS auto_closed,
                       COALESCE(SUM(a.clock_out IS NULL OR a.clock_out = ''), 0) AS missing_only,
                       COALESCE(SUM(CASE WHEN a.clock_in IS NOT NULL AND a.clock_in <> '' AND a.clock_out IS NOT NULL AND a.clock_out <> ''
                                    THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, a.clock_in, a.clock_out)) ELSE 0 END), 0) AS minutes
                FROM attendance a
                JOIN employees e ON a.employee_id = e.id"
            . ($where !== '' ? ' WHERE ' . $where : '')
            . ' GROUP BY e.department_id, a.attendance_date';
        $rows = $this->db->fetchAll($sql, $types, $params);

        $employees = $this->activeEmployees($filters, $scope);
        $leaves    = $this->leavesInRange($filters, $scope);
        $calendar  = $this->buildCalendar($from, $to);

        $init = static fn (): array => [
            'present' => 0, 'late' => 0, 'auto' => 0, 'missing' => 0,
            'minutes' => 0, 'expected' => 0, 'on_leave' => 0, 'absent' => 0,
        ];

        $stats = [];
        $presentByDeptDate = [];
        foreach ($rows as $row) {
            $dept = (int) ($row['dept_id'] ?? 0);
            $date = (string) $row['d'];
            if (!isset($stats[$dept])) {
                $stats[$dept] = $init();
            }
            $stats[$dept]['present'] += (int) $row['records'];
            $stats[$dept]['late']    += (int) $row['late_total'];
            $stats[$dept]['auto']    += (int) $row['auto_closed'];
            $stats[$dept]['missing'] += (int) $row['missing_only'];
            $stats[$dept]['minutes'] += (int) $row['minutes'];
            $presentByDeptDate[$dept][$date] = (int) $row['records'];
        }

        $empByDept = [];
        foreach ($employees as $e) {
            $dept = (int) ($e['department_id'] ?? 0);
            $empByDept[$dept][] = $e;
            if (!isset($stats[$dept])) {
                $stats[$dept] = $init();
            }
        }

        $leaveByDept = [];
        foreach ($leaves as $l) {
            $leaveByDept[(int) ($l['department_id'] ?? 0)][] = $l;
        }

        // Weekends and holidays are excluded from the expected-workforce math.
        foreach ($calendar as $date => $cal) {
            if ($cal['weekend'] || $cal['holiday'] !== null) {
                continue;
            }
            foreach ($empByDept as $dept => $emps) {
                $exp = $this->expectedOn($emps, $date);
                if ($exp <= 0) {
                    continue;
                }
                $lv = $this->leaveCoverageOn($leaveByDept[$dept] ?? [], $date);
                $stats[$dept]['expected'] += $exp;
                $stats[$dept]['on_leave'] += $lv;
                $stats[$dept]['absent']   += max(0, $exp - $lv - ($presentByDeptDate[$dept][$date] ?? 0));
            }
        }

        $names = [];
        foreach ($this->db->fetchAll('SELECT id, name FROM departments') as $r) {
            $names[(int) $r['id']] = (string) $r['name'];
        }

        $out = [];
        foreach ($stats as $dept => $s) {
            $out[] = [
                'department_id'   => $dept,
                'department'      => $names[$dept] ?? ($dept > 0 ? 'Department #' . $dept : 'Unassigned'),
                'present'         => $s['present'],
                'late'            => $s['late'],
                'auto'            => $s['auto'],
                'missing'         => $s['missing'],
                'on_leave'        => $s['on_leave'],
                'absent'          => $s['absent'],
                'expected_days'   => $s['expected'],
                'total_hours'     => round($s['minutes'] / 60, 1),
                'attendance_rate' => $s['expected'] > 0 ? round($s['present'] / $s['expected'] * 100, 1) : null,
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$b['present'], $a['department']] <=> [$a['present'], $b['department']]);

        return $out;
    }

    // ===============================================================
    // Late arrival analysis
    // ===============================================================

    /**
     * Late-arrival analysis: totals, repeat offenders and the employees /
     * departments with the most late days. Employee-level detail is only
     * reachable through the reports permission + OrgScope pinning applied
     * by buildWhere().
     *
     * @return array{total_late:int,repeat_offenders:int,threshold:int,employees:array,by_department:array}
     */
    public function lateArrivals(array $filters, array $scope): array
    {
        $filters = $this->capRange($filters);

        [$where, $types, $params] = $this->query->buildWhere($filters, $scope);
        $lateWhere = ($where !== '' ? $where . ' AND ' : '') . 'a.is_late = 1';
        $base = "FROM attendance a
                JOIN employees e ON a.employee_id = e.id
                LEFT JOIN departments d ON e.department_id = d.id
                WHERE {$lateWhere}";

        $employees = $this->db->fetchAll(
            "SELECT e.id AS employee_id,
                    e.employee_id AS emp_no,
                    CONCAT(e.first_name, ' ', COALESCE(e.last_name, '')) AS name,
                    COALESCE(d.name, 'Unassigned') AS department,
                    COUNT(a.id) AS late_days
             {$base}
             GROUP BY e.id, e.employee_id, e.first_name, e.last_name, d.name
             HAVING late_days > 0
             ORDER BY late_days DESC, name ASC",
            $types,
            $params
        );

        $byDept = $this->db->fetchAll(
            "SELECT COALESCE(d.name, 'Unassigned') AS department,
                    COUNT(a.id) AS late_days
             {$base}
             GROUP BY d.name
             HAVING late_days > 0
             ORDER BY late_days DESC, department ASC",
            $types,
            $params
        );

        $total  = 0;
        $repeat = 0;
        foreach ($employees as $r) {
            $total += (int) $r['late_days'];
            if ((int) $r['late_days'] >= self::REPEAT_LATE_THRESHOLD) {
                $repeat++;
            }
        }

        return [
            'total_late'       => $total,
            'repeat_offenders' => $repeat,
            'threshold'        => self::REPEAT_LATE_THRESHOLD,
            'employees'        => array_slice($employees, 0, 10),
            'by_department'    => $byDept,
        ];
    }

    // ===============================================================
    // Working hours analysis
    // ===============================================================

    /**
     * Working-hours analysis: totals, averages and an hours trend bucketed
     * with the same grouping rules as trends(). Hours are derived from the
     * clock_in -> clock_out span already normalised by dailyAggregate();
     * records without a clock-out contribute no hours (they surface in the
     * missing clock-out analysis instead).
     *
     * @return array{grouping:string,total_hours:float,avg_hours_per_day:float,avg_hours_per_employee:float,trend:array}
     */
    public function workingHours(array $filters, array $scope): array
    {
        $filters  = $this->capRange($filters);
        $from     = $filters['from'];
        $to       = $filters['to'];
        $grouping = $this->groupingFor($from, $to);

        $total   = 0.0;
        $records = 0;
        $buckets = [];

        foreach ($this->dailyAggregate($filters, $scope) as $row) {
            $hours    = (float) $row['work_hours'];
            $rowCount = (int) $row['records'];
            $total   += $hours;
            $records += $rowCount;

            $date = (string) $row['d'];
            if ($grouping === 'monthly') {
                $key = substr($date, 0, 7);
            } elseif ($grouping === 'weekly') {
                $ts  = strtotime($date);
                $key = date('Y-m-d', $ts - (((int) date('N', $ts)) - 1) * 86400);
            } else {
                $key = $date;
            }

            if (!isset($buckets[$key])) {
                $buckets[$key] = ['hours' => 0.0, 'records' => 0];
            }
            $buckets[$key]['hours']   += $hours;
            $buckets[$key]['records'] += $rowCount;
        }

        ksort($buckets);

        $trend = [];
        foreach ($buckets as $key => $b) {
            if ($grouping === 'monthly') {
                $label = date('M Y', strtotime($key . '-01'));
            } elseif ($grouping === 'weekly') {
                $label = 'W' . date('W', strtotime($key)) . ' · ' . date('M j', strtotime($key));
            } else {
                $label = date('M j', strtotime($key));
            }

            $trend[] = [
                'label'     => $label,
                'hours'     => round($b['hours'], 1),
                'records'   => $b['records'],
                'avg_hours' => $b['records'] > 0 ? round($b['hours'] / $b['records'], 1) : 0,
            ];
        }

        $employees = $this->distinctEmployees($filters, $scope);

        return [
            'grouping'               => $grouping,
            'total_hours'            => round($total, 1),
            'avg_hours_per_day'      => $records > 0 ? round($total / $records, 1) : 0,
            'avg_hours_per_employee' => $employees > 0 ? round($total / $employees, 1) : 0,
            'trend'                  => $trend,
        ];
    }

    // ===============================================================
    // Dynamic insights
    // ===============================================================

    /**
     * Human-readable insights derived strictly from the same aggregates the
     * summary cards and charts use. The selected range is compared against
     * the equal-length period immediately preceding it. Every bullet is
     * computed from real data — none are hardcoded — and all respect the
     * active filters.
     *
     * @return array{insights:string[],previous_period:array{from:string,to:string}}
     */
    public function insights(array $filters, array $scope): array
    {
        $cur = $this->capRange($filters);
        $from = $cur['from'];
        $to = $cur['to'];

        // Previous comparable period: same number of days, ending the day
        // before the selected range starts.
        $span = (strtotime($to) - strtotime($from)) / 86400 + 1;
        $prevTo = date('Y-m-d', strtotime($from) - 86400);
        $prevFrom = date('Y-m-d', strtotime($prevTo) - ((int) $span - 1) * 86400);

        $summary  = $this->summary(array_merge($cur, ['from' => $from, 'to' => $to]), $scope);
        $previous = $this->summary(array_merge($cur, ['from' => $prevFrom, 'to' => $prevTo]), $scope);
        $late     = $this->lateArrivals($cur, $scope);

        $insights = [];

        // 1. Headline compliance statement.
        $insights[] = sprintf(
            'Attendance compliance rate was %.1f%% (%d present day(s) out of %d expected working day(s)).',
            (float) $summary['compliance_rate'],
            (int) $summary['present_days'],
            (int) $summary['expected_working_days']
        );

        // 2. Attendance volume vs the previous comparable period.
        $insights[] = sprintf(
            'Attendance %s compared to the previous period (%d vs %d record(s)).',
            $this->movement((int) $summary['attendance_records'], (int) $previous['attendance_records']),
            (int) $summary['attendance_records'],
            (int) $previous['attendance_records']
        );

        // 3. Absence movement (only meaningful when either period had absences).
        if ((int) $summary['absent_days'] > 0 || (int) $previous['absent_days'] > 0) {
            $insights[] = sprintf(
                'Absence %s compared to the previous period (%d vs %d absent day(s)).',
                $this->movement((int) $summary['absent_days'], (int) $previous['absent_days']),
                (int) $summary['absent_days'],
                (int) $previous['absent_days']
            );
        }

        // 4. Late arrivals + repeated-offender call-out.
        if ((int) $late['total_late'] > 0) {
            $insights[] = sprintf(
                '%d late arrival(s) recorded; %d employee(s) were late %d+ time(s).',
                (int) $late['total_late'],
                (int) $late['repeat_offenders'],
                (int) $late['threshold']
            );
            $topDept = $late['by_department'][0] ?? null;
            if (is_array($topDept) && !empty($topDept['department'])) {
                $insights[] = sprintf(
                    '%s recorded the highest number of late arrivals (%d).',
                    (string) $topDept['department'],
                    (int) ($topDept['late_days'] ?? 0)
                );
            }
        }

        // 5. Missing clock-outs movement — an operational follow-up signal.
        if ((int) $summary['missing_clockouts'] > 0 || (int) $previous['missing_clockouts'] > 0) {
            $insights[] = sprintf(
                'Missing clock-outs %s compared to the previous period (%d vs %d).',
                $this->movement((int) $summary['missing_clockouts'], (int) $previous['missing_clockouts']),
                (int) $summary['missing_clockouts'],
                (int) $previous['missing_clockouts']
            );
        }

        // 6. Auto clock-outs requiring HR review.
        if ((int) $summary['auto_clockouts'] > 0) {
            $insights[] = sprintf(
                '%d attendance record(s) were closed automatically by the system and may require review.',
                (int) $summary['auto_clockouts']
            );
        }

        // 7. Peak attendance bucket from the trend series.
        $trend = $this->trends($cur, $scope);
        $peak = null;
        foreach (($trend['points'] ?? []) as $point) {
            $present = (int) ($point['present'] ?? 0);
            if ($peak === null || $present > (int) ($peak['present'] ?? 0)) {
                $peak = $point;
            }
        }
        if (is_array($peak) && (int) ($peak['present'] ?? 0) > 0) {
            $insights[] = sprintf(
                'Attendance peaked during %s with %d present day(s).',
                (string) ($peak['label'] ?? 'the period'),
                (int) ($peak['present'] ?? 0)
            );
        }

        return [
            'insights'        => $insights,
            'previous_period' => ['from' => $prevFrom, 'to' => $prevTo],
        ];
    }

    /** Relative-movement phrase comparing a metric to its previous value. */
    private function movement(int $current, int $previous): string
    {
        if ($previous <= 0 && $current <= 0) {
            return 'remained at zero';
        }
        if ($previous <= 0) {
            return 'increased from zero';
        }
        $pct = (int) round((($current - $previous) / $previous) * 100);
        if ($pct > 0) {
            return "increased by {$pct}%";
        }
        if ($pct < 0) {
            return 'decreased by ' . abs($pct) . '%';
        }
        return 'remained steady';
    }
}

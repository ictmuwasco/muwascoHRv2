<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Attendance Dashboard Service
 *
 * Single source of truth for organisation-wide attendance status
 * calculation. The frontend NEVER derives attendance statuses itself;
 * it renders whatever authoritative status this service computes.
 *
 * Status resolution priority (per approved business rules):
 *   1. HOLIDAY            - date is a configured public holiday
 *   2. NON_WORKING_DAY    - Saturday/Sunday (matches LeaveCalculationService)
 *   3. ON_LEAVE           - approved leave application covering the date
 *   4. AUTO_CLOCKED_OUT   - session closed automatically at midnight
 *   5. LATE               - clocked in after the late cutoff (08:30 Africa/Nairobi)
 *   6. MISSING_CLOCK_OUT  - past-day record still missing clock-out (defensive)
 *   7. PRESENT            - valid clock-in, day/session in progress
 *   8. CLOCKED_OUT        - completed clock-in + clock-out
 *   9. NOT_CLOCKED_IN     - expected but not yet clocked in (grace policy only)
 *  10. ABSENT             - expected employee with no clock-in (IMMEDIATE per
 *                             business decision: no grace period; see
 *                             NOT_CLOCKED_IN_GRACE_ENABLED below)
 *
 * Working-day rule: Mon-Fri are working days, Sat+Sun are non-working days,
 * minus configured holidays. This mirrors LeaveCalculationService so leave
 * maths and attendance maths can never disagree.
 */
class AttendanceDashboardService
{
    // ---- Authoritative attendance statuses ----
    public const STATUS_PRESENT          = 'PRESENT';
    public const STATUS_NOT_CLOCKED_IN   = 'NOT_CLOCKED_IN';
    public const STATUS_ABSENT           = 'ABSENT';
    public const STATUS_ON_LEAVE         = 'ON_LEAVE';
    public const STATUS_HOLIDAY          = 'HOLIDAY';
    public const STATUS_NON_WORKING_DAY  = 'NON_WORKING_DAY';
    public const STATUS_LATE             = 'LATE';
    public const STATUS_CLOCKED_OUT      = 'CLOCKED_OUT';
    public const STATUS_AUTO_CLOCKED_OUT = 'AUTO_CLOCKED_OUT';
    public const STATUS_MISSING_CLOCK_OUT = 'MISSING_CLOCK_OUT';

    /** All statuses an employee row may carry, in display order. */
    public const ALL_STATUSES = [
        self::STATUS_PRESENT,
        self::STATUS_LATE,
        self::STATUS_CLOCKED_OUT,
        self::STATUS_NOT_CLOCKED_IN,
        self::STATUS_ABSENT,
        self::STATUS_ON_LEAVE,
        self::STATUS_HOLIDAY,
        self::STATUS_NON_WORKING_DAY,
        self::STATUS_AUTO_CLOCKED_OUT,
        self::STATUS_MISSING_CLOCK_OUT,
    ];

    /**
     * BUSINESS RULE (locked with product owner):
     * Any expected employee without a valid clock-in is ABSENT immediately -
     * there is NO end-of-day grace period. With this switch disabled the
     * NOT_CLOCKED_IN state never occurs (it stays defined for forward
     * compatibility). Flip to true to defer absence until the day rolls over.
     */
    private const NOT_CLOCKED_IN_GRACE_ENABLED = false;

    /** Late cutoff - must match AttendanceController::clockInAction (08:30). */
    private const LATE_CUTOFF_HOUR   = 8;
    private const LATE_CUTOFF_MINUTE = 30;

    /** Hard cap on rows in the lightweight "not clocked in / absent" panel. */
    private const ABSENT_PANEL_LIMIT = 200;

    /**
     * Resolve the final attendance status for one employee-day.
     *
     * Public so it is unit-testable without a database connection.
     *
     * @param array $ctx {
     *   is_holiday        bool
     *   is_non_working_day bool
     *   leave_type        string|null  Approved leave type covering the date
     *   record            array|null   attendance row (status,is_late,clock_out,auto_clocked_out)
     *   is_today          bool
     * }
     */
    public function resolveEmployeeStatus(array $ctx): string
    {
        // 1-2. Organisation calendar wins over everything.
        if (!empty($ctx['is_holiday'])) {
            return self::STATUS_HOLIDAY;
        }
        if (!empty($ctx['is_non_working_day'])) {
            return self::STATUS_NON_WORKING_DAY;
        }

        // 3. Approved leave - never absent simply because there is no clock-in.
        if (!empty($ctx['leave_type'])) {
            return self::STATUS_ON_LEAVE;
        }

        $record = $ctx['record'] ?? null;
        if ($record !== null && count($record) > 0) {
            // 4. Midnight auto-close flag (column or legacy status value).
            $autoClockedOut = !empty($record['auto_clocked_out'])
                || (string)($record['status'] ?? '') === 'auto_clocked_out';
            if ($autoClockedOut) {
                return self::STATUS_AUTO_CLOCKED_OUT;
            }

            // 5. Late arrivals keep their LATE identity even after clock-out.
            if (!empty($record['is_late'])) {
                return self::STATUS_LATE;
            }

            $hasClockOut = !empty($record['clock_out']);
            if (!$hasClockOut && empty($ctx['is_today'])) {
                // 6. Defensive: reconciler should have closed these already.
                return self::STATUS_MISSING_CLOCK_OUT;
            }
            if (!$hasClockOut) {
                // 7. Currently clocked in - present, session in progress.
                return self::STATUS_PRESENT;
            }

            // 8. Completed session.
            return self::STATUS_CLOCKED_OUT;
        }

        // No attendance record at all.
        if (self::NOT_CLOCKED_IN_GRACE_ENABLED && !empty($ctx['is_today'])) {
            return self::STATUS_NOT_CLOCKED_IN;
        }

        // 9/10. Immediate absence per business rule.
        return self::STATUS_ABSENT;
    }

    /**
     * Build the full attendance dashboard payload for one date.
     *
     * @param array $params {
     *   date          string|null  Y-m-d (default: today)
     *   trend_days    int
     *   department_id int|null
     *   section_id    int|null
     *   subsection_id int|null     server-side scope clamp (sub-section heads)
     *   status        string|null  filter rows by computed status
     *   search        string       name / staff no match
     *   page          int
     *   limit         int
     * }
     */
    public function getDashboard(array $params): array
    {
        $date = $this->normaliseDate($params['date'] ?? null);
        $isToday = ($date === date('Y-m-d'));
        $trendDays = max(1, min(31, (int)($params['trend_days'] ?? 7)));
        $departmentId = isset($params['department_id']) ? (int)$params['department_id'] : null;
        $sectionId = isset($params['section_id']) && $params['section_id'] ? (int)$params['section_id'] : null;
        $subsectionId = isset($params['subsection_id']) && $params['subsection_id'] ? (int)$params['subsection_id'] : null;
        $statusFilter = strtoupper(trim((string)($params['status'] ?? '')));
        $search = trim((string)($params['search'] ?? ''));
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = max(1, min(200, (int)($params['limit'] ?? 25)));

        // ---- Organisation calendar context for the selected date ----
        $holiday = $this->getHolidayForDate($date);
        $isHoliday = $holiday !== null;
        $isNonWorkingDay = $this->isWeekend($date);

        // ---- Batched data (no N+1: three queries total for the day) ----
        $rows = $this->getRosterRows($date, $departmentId, $sectionId, $subsectionId);
        $leaves = $this->getApprovedLeavesOnDate($date);

        // ---- Compute authoritative statuses ----
        $employees = [];
        foreach ($rows as $row) {
            $status = $this->resolveEmployeeStatus([
                'is_holiday'         => $isHoliday,
                'is_non_working_day' => $isNonWorkingDay,
                'leave_type'         => $leaves[(int)$row['id']] ?? null,
                'record'             => $row['attendance_id'] !== null ? [
                    'status'           => $row['attendance_status'],
                    'is_late'          => (int)$row['is_late'] === 1,
                    'clock_out'        => $row['clock_out'],
                    'auto_clocked_out' => (int)$row['auto_clocked_out'] === 1,
                ] : null,
                'is_today'           => $isToday,
            ]);

            $employees[] = $this->formatEmployeeRow($row, $date, $status, $leaves[(int)$row['id']] ?? null);
        }

        // ---- Summary over the scoped set (dept/section filters applied,
        //      status/search deliberately excluded so cards stay meaningful) ----
        $summary = $this->buildSummary($employees, $isHoliday || $isNonWorkingDay);

        // ---- Department breakdown (same scope as summary) ----
        $departments = $this->buildDepartmentBreakdown($employees, $isHoliday || $isNonWorkingDay);

        // ---- Row-level filters (search + status), then paginate ----
        $filtered = array_values(array_filter($employees, function (array $e) use ($search, $statusFilter): bool {
            if ($search !== '') {
                $haystack = mb_strtolower($e['name'] . ' ' . (string)$e['employee_no']);
                if (mb_strpos($haystack, mb_strtolower($search)) === false) {
                    return false;
                }
            }

            if ($statusFilter !== '' && $statusFilter !== 'ALL') {
                if ($statusFilter === self::STATUS_PRESENT) {
                    // The "Present" tab matches the summary's `present` figure
                    // exactly: every employee who clocked in today, whether
                    // they are PRESENT, LATE, CLOCKED_OUT, or (on past dates)
                    // AUTO_CLOCKED_OUT / MISSING_CLOCK_OUT. Without this,
                    // a late or clocked-out employee is counted on the card
                    // but disappears when the tab is clicked.
                    $clockedInFamily = [
                        self::STATUS_PRESENT,
                        self::STATUS_LATE,
                        self::STATUS_CLOCKED_OUT,
                        self::STATUS_AUTO_CLOCKED_OUT,
                        self::STATUS_MISSING_CLOCK_OUT,
                    ];
                    if (!in_array($e['status'], $clockedInFamily, true)) {
                        return false;
                    }
                } elseif ($e['status'] !== $statusFilter) {
                    return false;
                }
            }

            return true;
        }));

        $total = count($filtered);
        $totalPages = max(1, (int)ceil($total / $limit));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $limit;

        // ---- Lightweight panel of employees who have not clocked in ----
        $absentPanel = array_values(array_filter($employees, fn(array $e): bool =>
            $e['status'] === self::STATUS_ABSENT || $e['status'] === self::STATUS_NOT_CLOCKED_IN));
        usort($absentPanel, fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        $absentPanel = array_slice($absentPanel, 0, self::ABSENT_PANEL_LIMIT);

        return [
            'date'     => $date,
            'is_today' => $isToday,
            'context'  => [
                'working_day'            => !$isHoliday && !$isNonWorkingDay,
                'is_holiday'             => $isHoliday,
                'holiday_name'           => $holiday,
                'is_non_working_day'     => $isNonWorkingDay,
                'not_clocked_in_enabled' => self::NOT_CLOCKED_IN_GRACE_ENABLED,
            ],
            'summary'          => $summary,
            'employees'        => array_slice($filtered, $offset, $limit),
            'pagination'       => [
                'page'        => $page,
                'limit'       => $limit,
                'total'       => $total,
                'total_pages' => $totalPages,
            ],
            'departments'      => $departments,
            'trend'            => $this->buildTrend($date, $trendDays, $departmentId, $sectionId, $subsectionId),
            'absent_employees' => $absentPanel,
            'statuses'         => self::ALL_STATUSES,
            'filters_applied'  => [
                'department_id' => $departmentId,
                'section_id'    => $sectionId,
                'subsection_id' => $subsectionId,
                'status'        => $statusFilter ?: null,
                'search'        => $search ?: null,
            ],
        ];
    }

    /**
     * Employee profile + recent attendance history for the detail modal.
     *
     * Uses the live schema directly (the legacy AttendanceRepository methods
     * reference retired columns and cannot serve this data).
     */
    public function getEmployeeHistory(int $employeeId, string $startDate, string $endDate, int $limit = 30): array
    {
        $startDate = $this->normaliseDate($startDate);
        $endDate = $this->normaliseDate($endDate);
        if ($startDate > $endDate) {
            throw new \InvalidArgumentException('Start date cannot be after end date');
        }

        $db = \db();
        $employee = $db->fetchOne(
            "SELECT e.id, e.employee_id,
                    TRIM(CONCAT(e.first_name, ' ', e.last_name)) AS name,
                    e.designation, e.position, e.email, e.phone, e.employee_status,
                    d.name AS department, s.name AS section
             FROM employees e
             LEFT JOIN departments d ON e.department_id = d.id
             LEFT JOIN sections s ON e.section_id = s.id
             WHERE e.id = ?
             LIMIT 1",
            'i',
            [$employeeId]
        );

        if (!$employee) {
            throw new \InvalidArgumentException('Employee not found');
        }

        $records = $db->fetchAll(
            "SELECT a.id,
                    DATE(a.clock_in) AS date,
                    TIME(a.clock_in) AS clock_in_time,
                    TIME(a.clock_out) AS clock_out_time,
                    a.status AS attendance_status,
                    a.is_late, a.auto_clocked_out
             FROM attendance a
             WHERE a.employee_id = ? AND DATE(a.clock_in) BETWEEN ? AND ?
             ORDER BY a.clock_in DESC
             LIMIT " . max(1, min(100, $limit)),
            'iss',
            [$employeeId, $startDate, $endDate]
        );

        $history = [];
        foreach ($records as $r) {
            $status = $this->resolveEmployeeStatus([
                'is_holiday'         => $this->getHolidayForDate($r['date']) !== null,
                'is_non_working_day' => $this->isWeekend($r['date']),
                // History reflects what was recorded on the day; leave context
                // for that date is intentionally not re-derived here.
                'leave_type'         => null,
                'record'             => [
                    'status'           => $r['attendance_status'],
                    'is_late'          => (int)$r['is_late'] === 1,
                    'clock_out'        => $r['clock_out_time'],
                    'auto_clocked_out' => (int)$r['auto_clocked_out'] === 1,
                ],
                'is_today'           => $r['date'] === date('Y-m-d'),
            ]);

            $history[] = [
                'id'               => (int)$r['id'],
                'date'             => $r['date'],
                'clock_in_time'    => $r['clock_in_time'] ? substr($r['clock_in_time'], 0, 5) : null,
                'clock_out_time'   => $r['clock_out_time'] ? substr($r['clock_out_time'], 0, 5) : null,
                'work_hours'       => $this->formatWorkHours($r['clock_in_time'], $r['clock_out_time']),
                'status'           => $status,
                'status_label'     => $this->statusLabel($status),
                'is_late'          => (int)$r['is_late'] === 1,
                'auto_clocked_out' => (int)$r['auto_clocked_out'] === 1,
            ];
        }

        return [
            'employee' => $employee,
            'history'  => $history,
            'range'    => ['start_date' => $startDate, 'end_date' => $endDate],
        ];
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private function normaliseDate(?string $date): string
    {
        $date = trim((string)$date);
        if ($date === '') {
            return date('Y-m-d');
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Invalid date. Expected format: Y-m-d');
        }
        return $date;
    }

    /** Sat/Sun are non-working days (mirrors LeaveCalculationService). */
    private function isWeekend(string $date): bool
    {
        return (int)\DateTime::createFromFormat('Y-m-d', $date)->format('N') >= 6;
    }

    /**
     * Holiday name for a date, or null. Recurring holidays match on
     * month+day regardless of year.
     */
    private function getHolidayForDate(string $date): ?string
    {
        static $cache = [];
        if (isset($cache[$date])) {
            return $cache[$date];
        }

        $dt = \DateTime::createFromFormat('Y-m-d', $date);
        $row = \db()->fetchOne(
            "SELECT name FROM holidays
             WHERE date = ?
                OR (is_recurring = 1 AND MONTH(date) = ? AND DAY(date) = ?)
             LIMIT 1",
            'sii',
            [$date, (int)$dt->format('n'), (int)$dt->format('j')]
        );

        return $cache[$date] = $row ? (string)$row['name'] : null;
    }

    /**
     * All active employees in scope for a date with that day's attendance
     * record (if any) attached in the same query - one round trip.
     *
     * $subsectionId additionally narrows to a single sub-section (used by the
     * server-side data-scope clamp for sub-section heads — never from
     * untrusted input).
     */
    private function getRosterRows(string $date, ?int $departmentId, ?int $sectionId, ?int $subsectionId = null): array
    {
        $sql = "SELECT e.id, e.employee_id,
                       TRIM(CONCAT(e.first_name, ' ', e.last_name)) AS name,
                       e.first_name, e.last_name,
                       e.designation, e.position, e.phone, e.email,
                       e.profile_image_url,
                       d.id AS department_id, d.name AS department,
                       s.id AS section_id, s.name AS section,
                       a.id AS attendance_id,
                       a.clock_in, a.clock_out,
                       a.status AS attendance_status,
                       a.is_late, a.auto_clocked_out
                FROM employees e
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN sections s ON e.section_id = s.id
                LEFT JOIN attendance a ON a.id = (
                    SELECT a2.id FROM attendance a2
                    WHERE a2.employee_id = e.id
                      AND a2.clock_in >= ?
                      AND a2.clock_in < ? + INTERVAL 1 DAY
                    ORDER BY a2.clock_in DESC
                    LIMIT 1
                )
                WHERE (e.employee_status = 'active' OR e.employee_status IS NULL)";
        $types = 'ss';
        $params = [$date, $date];

        if ($departmentId) {
            $sql .= " AND e.department_id = ?";
            $types .= 'i';
            $params[] = $departmentId;
        }
        if ($sectionId) {
            $sql .= " AND e.section_id = ?";
            $types .= 'i';
            $params[] = $sectionId;
        }
        if ($subsectionId) {
            $sql .= " AND e.subsection_id = ?";
            $types .= 'i';
            $params[] = $subsectionId;
        }

        $sql .= " ORDER BY e.first_name ASC, e.last_name ASC";

        return \db()->fetchAll($sql, $types, $params);
    }

    /**
     * Approved leave covering the date, keyed by employee_id.
     * One BETWEEN query for the whole roster - no per-employee lookups.
     *
     * @return array<int,string> employee_id => leave type name
     */
    private function getApprovedLeavesOnDate(string $date): array
    {
        $rows = \db()->fetchAll(
            "SELECT la.employee_id, lt.name AS leave_type
             FROM leave_applications la
             LEFT JOIN leave_types lt ON la.leave_type_id = lt.id
             WHERE la.status = 'approved'
               AND la.start_date <= ?
               AND la.end_date >= ?",
            'ss',
            [$date, $date]
        );

        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['employee_id']] = (string)($r['leave_type'] ?: 'Approved Leave');
        }
        return $map;
    }

    private function formatEmployeeRow(array $row, string $date, string $status, ?string $leaveType = null): array
    {
        $hasRecord = $row['attendance_id'] !== null;

        return [
            'employee_db_id'   => (int)$row['id'],
            'employee_no'      => (string)($row['employee_id'] ?? ''),
            'name'             => (string)$row['name'],
            'department'       => $row['department'],
            'department_id'    => $row['department_id'] !== null ? (int)$row['department_id'] : null,
            'section'          => $row['section'],
            'position'         => ($row['position'] ?? '') !== '' ? $row['position'] : ($row['designation'] ?? null),
            'phone'            => $row['phone'],
            'email'            => $row['email'],
            'profile_image_url'=> $row['profile_image_url'],
            'date'             => $date,
            'has_record'       => $hasRecord,
            'leave_type'       => $leaveType,
            'clock_in_time'    => $row['clock_in'] ? date('H:i', strtotime($row['clock_in'])) : null,
            'clock_out_time'   => $row['clock_out'] ? date('H:i', strtotime($row['clock_out'])) : null,
            'work_hours'       => $hasRecord
                ? $this->formatWorkHours($row['clock_in'], $row['clock_out'])
                : null,
            'worked_minutes'   => $hasRecord && $row['clock_in']
                ? $this->minutesBetween($row['clock_in'], $row['clock_out'])
                : null,
            'status'           => $status,
            'status_label'     => $this->statusLabel($status),
            'is_late'          => $hasRecord && (int)$row['is_late'] === 1,
            'auto_clocked_out' => $hasRecord && (int)$row['auto_clocked_out'] === 1,
            'attendance_status'=> $hasRecord ? $row['attendance_status'] : null,
        ];
    }

    /**
     * Summary cards. Expected-to-work excludes everyone the organisation has
     * excused from attendance (leave / holiday / non-working day), so the
     * attendance rate is present / genuinely-expected x 100.
     */
    private function buildSummary(array $employees, bool $dayExcluded): array
    {
        $counts = array_fill_keys(self::ALL_STATUSES, 0);
        foreach ($employees as $e) {
            $counts[$e['status']]++;
        }

        $present = $counts[self::STATUS_PRESENT]
            + $counts[self::STATUS_LATE]
            + $counts[self::STATUS_CLOCKED_OUT]
            + $counts[self::STATUS_AUTO_CLOCKED_OUT]
            + $counts[self::STATUS_MISSING_CLOCK_OUT];

        $onLeave = $counts[self::STATUS_ON_LEAVE];
        $totalEmployees = count($employees);
        $expectedToWork = $dayExcluded ? 0 : max(0, $totalEmployees - $onLeave);

        return [
            'total_employees'   => $totalEmployees,
            'expected_to_work'  => $expectedToWork,
            'present'           => $present,
            'not_clocked_in'    => $counts[self::STATUS_NOT_CLOCKED_IN],
            'absent'            => $counts[self::STATUS_ABSENT],
            'on_leave'          => $onLeave,
            'holiday'           => $counts[self::STATUS_HOLIDAY],
            'non_working_day'   => $counts[self::STATUS_NON_WORKING_DAY],
            'late'              => $counts[self::STATUS_LATE],
            'missing_clock_out' => $counts[self::STATUS_MISSING_CLOCK_OUT],
            'auto_clocked_out'  => $counts[self::STATUS_AUTO_CLOCKED_OUT],
            'attendance_rate'   => $expectedToWork > 0
                ? round(($present / $expectedToWork) * 100, 2)
                : null,
        ];
    }

    /**
     * Department-level breakdown over the same scope as the summary.
     */
    private function buildDepartmentBreakdown(array $employees, bool $dayExcluded): array
    {
        $groups = [];
        foreach ($employees as $e) {
            $key = $e['department'] ?: 'Unassigned';
            $groups[$key]['department_id'] = $e['department_id'];
            $groups[$key]['rows'][] = $e;
        }

        ksort($groups, SORT_STRING | SORT_FLAG_CASE);

        $breakdown = [];
        foreach ($groups as $name => $group) {
            $summary = $this->buildSummary($group['rows'], $dayExcluded);
            $breakdown[] = [
                'department_id'    => $group['department_id'],
                'department'       => $name,
                'total_employees'  => $summary['total_employees'],
                'expected_to_work' => $summary['expected_to_work'],
                'present'          => $summary['present'],
                'absent'           => $summary['absent'],
                'on_leave'         => $summary['on_leave'],
                'late'             => $summary['late'],
                'attendance_rate'  => $summary['attendance_rate'],
            ];
        }

        return $breakdown;
    }

    /**
     * Per-day trend for the trailing N days ending at the selected date.
     *
    /**
     * Attendance counts come from one GROUP BY query over the range; leave
     * overlaps are expanded in PHP (bounded by the <=31 day window); the
     * holidays table is tiny and fetched once for the whole range.
     *
     * The optional department/section/subsection filters scope the trend to
     * the caller's organisational unit (server-side data-scope clamp for
     * heads) so unit-scoped callers never see org-wide aggregates.
     */
    private function buildTrend(
        string $endDate,
        int $days,
        ?int $departmentId = null,
        ?int $sectionId = null,
        ?int $subsectionId = null
    ): array {
        $startTs = strtotime($endDate . ' -' . ($days - 1) . ' days');
        $startDate = date('Y-m-d', $startTs);

        $unitJoins = ' FROM attendance a JOIN employees e ON a.employee_id = e.id';
        $unitWhere = '';
        if ($departmentId || $sectionId || $subsectionId) {
            $unitWhere = ($departmentId ? ' AND e.department_id = ' . (int) $departmentId : '')
                . ($sectionId ? ' AND e.section_id = ' . (int) $sectionId : '')
                . ($subsectionId ? ' AND e.subsection_id = ' . (int) $subsectionId : '');
        }

        // Present + late per day (single grouped query, sargable on clock_in).
        $attRows = \db()->fetchAll(
            "SELECT DATE(a.clock_in) AS d,
                    COUNT(*) AS present,
                    SUM(CASE WHEN a.is_late = 1 THEN 1 ELSE 0 END) AS late
             {$unitJoins}
             WHERE a.clock_in >= ? AND a.clock_in < ? + INTERVAL 1 DAY{$unitWhere}
             GROUP BY DATE(a.clock_in)",
            'ss',
            [$startDate, $endDate]
        );
        $attendanceByDay = [];
        foreach ($attRows as $r) {
            $attendanceByDay[$r['d']] = $r;
        }

        // Approved leave applications overlapping the window (scoped to the
        // same organisational unit when one is applied).
        $leaveRows = \db()->fetchAll(
            "SELECT la.employee_id, la.start_date, la.end_date
             FROM leave_applications la
             JOIN employees e ON la.employee_id = e.id
             WHERE la.status = 'approved'
               AND la.start_date <= ?
               AND la.end_date >= ?{$unitWhere}",
            'ss',
            [$endDate, $startDate]
        );
        $leaveByDay = [];
        foreach ($leaveRows as $r) {
            $from = max($startDate, (string)$r['start_date']);
            $to = min($endDate, (string)$r['end_date']);
            for ($ts = strtotime($from); $ts <= strtotime($to); $ts += 86400) {
                $leaveByDay[date('Y-m-d', $ts)][(int)$r['employee_id']] = true;
            }
        }

        // Holidays in range (recurring ones matched by month+day).
        $holidayRows = \db()->fetchAll(
            "SELECT name, date, is_recurring FROM holidays
             WHERE date BETWEEN ? AND ? OR is_recurring = 1",
            'ss',
            [$startDate, $endDate]
        );
        $holidayNames = [];
        foreach ($holidayRows as $h) {
            $hDate = (string)$h['date'];
            if ((int)$h['is_recurring'] === 1 && ($hDate < $startDate || $hDate > $endDate)) {
                $monthDay = substr($hDate, 5);
                for ($ts = strtotime($startDate); $ts <= strtotime($endDate); $ts += 86400) {
                    $d = date('Y-m-d', $ts);
                    if (substr($d, 5) === $monthDay && !isset($holidayNames[$d])) {
                        $holidayNames[$d] = (string)$h['name'];
                    }
                }
            } else {
                $holidayNames[$hDate] = (string)$h['name'];
            }
        }

        // Active headcount once (expected headcount approximation per day).
        $activeCount = (int)\db()->fetchValue(
            "SELECT COUNT(*) FROM employees WHERE employee_status = 'active' OR employee_status IS NULL"
        );

        $trend = [];
        for ($i = 0; $i < $days; $i++) {
            $day = date('Y-m-d', $startTs + $i * 86400);
            $isHoliday = isset($holidayNames[$day]);
            $isNonWorking = $this->isWeekend($day);
            $workingDay = !$isHoliday && !$isNonWorking;

            $present = (int)($attendanceByDay[$day]['present'] ?? 0);
            $onLeaveCount = count($leaveByDay[$day] ?? []);
            $expected = $workingDay ? max(0, $activeCount - $onLeaveCount) : 0;

            $trend[] = [
                'date'               => $day,
                'label'              => date('D j M', strtotime($day)),
                'is_today'           => $day === date('Y-m-d'),
                'working_day'        => $workingDay,
                'is_holiday'         => $isHoliday,
                'holiday_name'       => $isHoliday ? $holidayNames[$day] : null,
                'is_non_working_day' => $isNonWorking,
                'present'            => $present,
                'late'               => (int)($attendanceByDay[$day]['late'] ?? 0),
                'on_leave'           => $onLeaveCount,
                // Immediate-absence policy: expected minus anyone accounted for.
                'absent'             => $workingDay ? max(0, $expected - $present) : 0,
                'not_clocked_in'     => 0,
                'attendance_rate'    => $expected > 0
                    ? round(($present / $expected) * 100, 2)
                    : null,
            ];
        }

        return $trend;
    }

    /** Human-readable label; the client never relies on colour alone. */
    private function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PRESENT           => 'Present',
            self::STATUS_NOT_CLOCKED_IN    => 'Not Clocked In',
            self::STATUS_ABSENT            => 'Absent',
            self::STATUS_ON_LEAVE          => 'On Leave',
            self::STATUS_HOLIDAY           => 'Holiday',
            self::STATUS_NON_WORKING_DAY   => 'Non-Working Day',
            self::STATUS_LATE              => 'Late',
            self::STATUS_CLOCKED_OUT       => 'Clocked Out',
            self::STATUS_AUTO_CLOCKED_OUT  => 'Auto Clocked Out',
            self::STATUS_MISSING_CLOCK_OUT => 'Missing Clock Out',
            default                        => ucfirst(strtolower(str_replace('_', ' ', $status))),
        };
    }

    /** "Xh Ym" duration between two datetimes (null-safe). */
    private function formatWorkHours(?string $clockIn, ?string $clockOut): ?string
    {
        $minutes = $this->minutesBetween($clockIn, $clockOut);
        if ($minutes === null) {
            return null;
        }
        return sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
    }

    private function minutesBetween(?string $clockIn, ?string $clockOut): ?int
    {
        if (!$clockIn || !$clockOut) {
            return null;
        }
        $in = strtotime($clockIn);
        $out = strtotime($clockOut);
        if ($in === false || $out === false || $out < $in) {
            return null;
        }
        return (int)round(($out - $in) / 60);
    }
}










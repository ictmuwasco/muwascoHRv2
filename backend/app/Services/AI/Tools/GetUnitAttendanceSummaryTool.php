<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * getUnitAttendanceSummary — attendance roll-up for the caller's unit.
 *
 * ROLE RESTRICTION: gated on attendance:manage, which is seeded to department /
 * section / sub-section heads, HR, the Managing Director and super admin —
 * NOT to officers or employees (they keep the self-service getMyAttendance
 * tool). The model therefore cannot use this to inspect a colleague's
 * attendance on behalf of a non-supervisory account.
 *
 * DATA SCOPE: managers see their own unit broken down PER EMPLOYEE (they manage
 * those people); organisation-wide callers (dashboard:hr_insights, PME/Audit
 * oversight) get a PER-DEPARTMENT aggregate with no individual names, which is
 * enough for oversight questions without turning the AI into a surveillance
 * feed. SENSITIVE-FIELD FILTERING: no locations, IPs or device fingerprints are
 * ever selected.
 */
final class GetUnitAttendanceSummaryTool implements AiToolInterface
{
    /** Cap for the per-employee breakdown (unit scope). */
    private const MAX_EMPLOYEES = 60;

    public function name(): string
    {
        return 'getUnitAttendanceSummary';
    }

    public function description(): string
    {
        return 'Summarise attendance for the caller\'s own department/section (or per department for '
            . 'organisation-wide roles) for one month: how many employees have attendance records, '
            . 'how many days were recorded, how many records were late and how many are missing a '
            . 'clock-out. Use this for questions about team attendance, punctuality or lateness in '
            . 'the caller\'s unit. Individual clock-in times are only available through the '
            . 'self-service attendance tool for the caller\'s own record.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'month' => [
                    'type'        => 'string',
                    'pattern'     => '^[0-9]{4}-(0[1-9]|1[0-2])$',
                    'description' => 'Month as YYYY-MM. Defaults to the previous month.',
                ],
            ],
            'required'   => [],
        ];
    }

    /** Supervisory roll-up: heads, HR, MD and super admin only. */
    public function requiredPermission(): string
    {
        return 'attendance:manage';
    }

    public function execute(AiToolContext $ctx, array $args): array
    {
        $month = isset($args['month']) && is_string($args['month'])
            && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $args['month']) === 1
            ? $args['month']
            : date('Y-m', strtotime('first day of previous month'));

        $first = $month . '-01';
        $last  = date('Y-m-t', strtotime($first));

        [$scope, $scopeParams, $scopeTypes] = AiUnitScope::where($ctx, 'e');

        if (AiUnitScope::isOrgWide($ctx)) {
            // Oversight view: aggregate per department, never per person.
            $rows = $ctx->db()->fetchAll(
                'SELECT COALESCE(d.name, \'Unassigned\') AS department,
                        COUNT(DISTINCT a.employee_id) AS employees_recorded,
                        COUNT(*) AS attendance_records,
                        SUM(CASE WHEN a.is_late = 1 THEN 1 ELSE 0 END) AS late_records,
                        SUM(CASE WHEN a.clock_out IS NULL THEN 1 ELSE 0 END) AS missing_clock_out
                 FROM attendance a
                 JOIN employees e ON e.id = a.employee_id
                 LEFT JOIN departments d ON d.id = e.department_id
                 WHERE a.attendance_date BETWEEN ? AND ?' . $scope . '
                 GROUP BY department
                 ORDER BY department ASC',
                'ss' . $scopeTypes,
                array_merge([$first, $last], $scopeParams)
            );

            $departments = [];
            foreach ($rows as $r) {
                $departments[] = [
                    'department'             => (string) $r['department'],
                    'employees_with_records' => (int) $r['employees_recorded'],
                    'attendance_records'     => (int) $r['attendance_records'],
                    'late_records'           => (int) $r['late_records'],
                    'missing_clock_out'      => (int) $r['missing_clock_out'],
                ];
            }

            return [
                'month'        => $month,
                'scope'        => AiUnitScope::label($ctx),
                'breakdown'    => 'department',
                'working_days' => $this->workingDays($first, $last),
                'departments'  => $departments,
                'note'         => $departments === []
                    ? 'No attendance records were found for ' . $month . '.'
                    : 'Counts are attendance records (one per employee per working day), not scheduled days.',
            ];
        }

        // Manager view: their own unit, broken down per employee.
        $rows = $ctx->db()->fetchAll(
            'SELECT CONCAT_WS(\' \', e.first_name, e.last_name) AS employee_name,
                    e.employee_id AS employee_number,
                    COUNT(*) AS days_recorded,
                    SUM(CASE WHEN a.is_late = 1 THEN 1 ELSE 0 END) AS late_days,
                    SUM(CASE WHEN a.clock_out IS NULL THEN 1 ELSE 0 END) AS missing_clock_out
             FROM attendance a
             JOIN employees e ON e.id = a.employee_id
             WHERE a.attendance_date BETWEEN ? AND ?' . $scope . '
             GROUP BY e.id
             ORDER BY e.first_name ASC, e.last_name ASC
             LIMIT ' . self::MAX_EMPLOYEES,
            'ss' . $scopeTypes,
            array_merge([$first, $last], $scopeParams)
        );

        $employees = [];
        foreach ($rows as $r) {
            $employees[] = [
                'employee'          => trim((string) $r['employee_name']),
                'employee_number'   => (string) $r['employee_number'],
                'days_recorded'     => (int) $r['days_recorded'],
                'late_days'         => (int) $r['late_days'],
                'missing_clock_out' => (int) $r['missing_clock_out'],
            ];
        }

        return [
            'month'        => $month,
            'scope'        => AiUnitScope::label($ctx),
            'breakdown'    => 'employee',
            'working_days' => $this->workingDays($first, $last),
            'employees'    => $employees,
            'note'         => $employees === []
                ? 'No attendance records were found for ' . $month . ' in your unit.'
                : 'Counts are attendance records per employee, not scheduled days. '
                    . 'Clock-in times, locations and device data are not available.',
        ];
    }

    /** Weekday count in the month, up to today when the month is current. */
    private function workingDays(string $first, string $last): int
    {
        $today = date('Y-m-d');
        $end   = $last > $today ? $today : $last;
        if ($end < $first) {
            return 0;
        }

        $days = 0;
        for ($d = strtotime($first); $d <= strtotime($end); $d += 86400) {
            $dow = (int) date('w', $d);
            if ($dow !== 0 && $dow !== 6) {
                $days++;
            }
        }

        return $days;
    }

    public function summarize(array $payload): string
    {
        if (!empty($payload['error'])) {
            return 'unavailable: ' . $payload['error'];
        }
        $month = (string) ($payload['month'] ?? '?');

        if (($payload['breakdown'] ?? '') === 'department') {
            $records = 0;
            $late    = 0;
            foreach ($payload['departments'] ?? [] as $d) {
                $records += (int) $d['attendance_records'];
                $late    += (int) $d['late_records'];
            }

            return $records . ' attendance record(s) across '
                . count($payload['departments'] ?? []) . ' department(s) in ' . $month
                . '; ' . $late . ' late record(s)';
        }

        return count($payload['employees'] ?? []) . ' employee(s) with attendance records in '
            . $month . ' (' . (string) ($payload['scope'] ?? 'unit') . ')';
    }
}

<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * getMyAttendance — the caller's OWN attendance records for one month,
 * owner-scoped by the server-side context (employee_id from the session,
 * never from model arguments).
 *
 * SENSITIVE-FIELD FILTERING: lat/lng/accuracy (location), ip_address and
 * device_fingerprint are deliberately NEVER returned — the tool result goes
 * into the provider conversation and must stay work-related.
 */
final class GetMyAttendanceTool implements AiToolInterface
{
    public function name(): string
    {
        return 'getMyAttendance';
    }

    public function description(): string
    {
        return 'Retrieve the signed-in employee\'s own attendance records. '
            . 'Returns one month (default: last month) or a FULL YEAR when year is provided. '
            . 'Each day includes clock-in, clock-out, late flag, status, hours, and absent flag. '
            . 'Use this for questions about attendance, clock-ins, lateness, missing clock-outs, '
            . 'present/absent days, or yearly summaries.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'month' => [
                    'type'        => 'string',
                    'pattern'     => '^[0-9]{4}-(0[1-9]|1[0-2])$',
                    'description' => 'Month as YYYY-MM. Defaults to the previous month. Ignored if year is set.',
                ],
                'year' => [
                    'type'        => 'string',
                    'pattern'     => '^[0-9]{4}$',
                    'description' => 'Year as YYYY (e.g. 2026). When set, returns the full year summary in one call.',
                ],
            ],
            'required'   => [],
        ];
    }

    public function requiredPermission(): string
    {
        return 'attendance:view';
    }

    public function execute(AiToolContext $ctx, array $args): array
    {
        $employeeId = $ctx->employeeId();
        if ($employeeId === null) {
            return [
                'error' => 'No employee record is linked to your account, so no attendance can be shown.',
            ];
        }

        // Year mode: return full year data in ONE call (avoids hitting the
        // 3-tool-call limit when the user asks for a yearly summary).
        $year = isset($args['year']) && is_string($args['year'])
            && preg_match('/^\d{4}$/', $args['year']) === 1
            ? $args['year']
            : null;

        if ($year !== null) {
            return $this->executeYear($ctx, $employeeId, $year);
        }

        // Month mode: model-supplied YYYY-MM, validated; anything else falls
        // back to the previous month (the most common question).
        $month = isset($args['month']) && is_string($args['month'])
            && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $args['month']) === 1
            ? $args['month']
            : date('Y-m', strtotime('first day of previous month'));

        $first = $month . '-01 00:00:00';
        $last  = date('Y-m-t 23:59:59', strtotime($first));

        // SELECT * + explicit safe-field extraction: the payload is built
        // from a whitelist, so sensitive columns (lat/lng/accuracy/ip/
        // device_fingerprint) may enter the row but never the result. The
        // clock_in range is SARGable (clock_in is indexed) and works on any
        // schema shape.
        $rows = $ctx->db()->fetchAll(
            'SELECT * FROM attendance
             WHERE employee_id = ? AND clock_in BETWEEN ? AND ?
             ORDER BY clock_in',
            'iss',
            [$employeeId, $first, $last]
        );

        $days         = [];
        $lateCount    = 0;
        $missingOut   = 0;
        $autoCount    = 0;
        $totalMinutes = 0;

        foreach ($rows as $row) {
            $clockIn  = (string) ($row['clock_in'] ?? '');
            $clockOut = (string) ($row['clock_out'] ?? '');
            $in       = $clockIn !== '' ? substr($clockIn, 11, 5) : null;
            $out      = $clockOut !== '' ? substr($clockOut, 11, 5) : null;
            $isLate   = (int) ($row['is_late'] ?? 0) === 1;
            $isAuto   = (int) ($row['auto_clocked_out'] ?? 0) === 1;
            $hours    = null;

            if ($in !== null && $out !== null) {
                $minutes = (int) round((strtotime('2000-01-01 ' . $out) - strtotime('2000-01-01 ' . $in)) / 60);
                if ($minutes >= 0) {
                    $totalMinutes += $minutes;
                    $hours = round($minutes / 60, 1);
                }
            } elseif ($out === '') {
                $missingOut++;
            }

            if ($isLate) {
                $lateCount++;
            }
            if ($isAuto) {
                $autoCount++;
            }

            $days[] = [
                'date'             => $clockIn !== '' ? substr($clockIn, 0, 10) : null,
                'clock_in'         => $in,
                'clock_out'        => $out,
                'late'             => $isLate,
                'auto_clocked_out' => $isAuto,
                'status'           => isset($row['status']) && $row['status'] !== null
                    ? (string) $row['status']
                    : null,
                'hours'            => $hours,
            ];
        }

        return [
            'month'   => $month,
            'days'    => $days,
            'summary' => [
                'days_recorded'     => count($days),
                'late_days'         => $lateCount,
                'missing_clock_out' => $missingOut,
                'auto_clocked_out'  => $autoCount,
                'total_hours'       => round($totalMinutes / 60, 1),
            ],
        ];
    }

    /**
     * Full-year attendance query — single DB call, returns monthly breakdowns
     * plus a yearly summary. Lets the AI answer "summarize my attendance for
     * this year" in ONE tool call instead of 12.
     */
    private function executeYear(AiToolContext $ctx, int $employeeId, string $year): array
    {
        $first = $year . '-01-01 00:00:00';
        $last  = $year . '-12-31 23:59:59';

        $rows = $ctx->db()->fetchAll(
            'SELECT * FROM attendance
             WHERE employee_id = ? AND clock_in BETWEEN ? AND ?
             ORDER BY clock_in',
            'iss',
            [$employeeId, $first, $last]
        );

        $recorded = [];
        foreach ($rows as $row) {
            $clockIn  = (string) ($row['clock_in'] ?? '');
            $clockOut = (string) ($row['clock_out'] ?? '');
            $date     = $clockIn !== '' ? substr($clockIn, 0, 10) : null;
            if ($date === null) {
                continue;
            }

            $in    = substr($clockIn, 11, 5);
            $out    = $clockOut !== '' ? substr($clockOut, 11, 5) : null;
            $isLate = (int) ($row['is_late'] ?? 0) === 1;
            $isAuto = (int) ($row['auto_clocked_out'] ?? 0) === 1;
            $hours  = null;

            if ($out !== null) {
                $minutes = (int) round((strtotime('2000-01-01 ' . $out) - strtotime('2000-01-01 ' . $in)) / 60);
                if ($minutes >= 0) {
                    $hours = round($minutes / 60, 1);
                }
            }

            $recorded[$date] = [
                'date'             => $date,
                'clock_in'         => $in,
                'clock_out'        => $out,
                'late'             => $isLate,
                'auto_clocked_out' => $isAuto,
                'status'           => isset($row['status']) && $row['status'] !== null
                    ? (string) $row['status']
                    : null,
                'hours'            => $hours,
            ];
        }

        $presentCount   = 0;
        $absentCount    = 0;
        $lateCount      = 0;
        $missingOut     = 0;
        $autoCount      = 0;
        $totalMinutes   = 0;
        $monthlyPresent = array_fill(1, 12, 0);
        $monthlyAbsent  = array_fill(1, 12, 0);

        $today      = new \DateTime('today');
        $start      = new \DateTime($year . '-01-01');
        $end        = new \DateTime($year . '-12-31');
        $currentEnd = $end > $today ? $today : $end;

        for ($d = $start; $d <= $currentEnd; $d->modify('+1 day')) {
            $dateStr = $d->format('Y-m-d');
            $dow     = (int) $d->format('w');
            $month   = (int) $d->format('n');

            if ($dow === 0 || $dow === 6) {
                continue;
            }

            if (isset($recorded[$dateStr])) {
                $r        = $recorded[$dateStr];
                $hours    = $r['hours'] ?? 0;
                $isAbsent = $hours === null || $hours <= 0;

                if ($isAbsent) {
                    $absentCount++;
                    $monthlyAbsent[$month]++;
                } else {
                    $presentCount++;
                    $monthlyPresent[$month]++;
                    $totalMinutes += (int) round($hours * 60);
                }

                if ($r['late']) {
                    $lateCount++;
                }
                if ($r['auto_clocked_out']) {
                    $autoCount++;
                }
                if ($r['clock_out'] === null) {
                    $missingOut++;
                }
            } else {
                $absentCount++;
                $monthlyAbsent[$month]++;
            }
        }

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthName = date('F', mktime(0, 0, 0, $m, 1, (int) $year));
            $months[$monthName] = [
                'present' => $monthlyPresent[$m],
                'absent'  => $monthlyAbsent[$m],
            ];
        }

        return [
            'year'              => $year,
            'mode'              => 'year',
            'months'            => $months,
            'working_days'      => $presentCount + $absentCount,
            'days_present'      => $presentCount,
            'days_absent'       => $absentCount,
            'late_days'         => $lateCount,
            'missing_clock_out' => $missingOut,
            'auto_clocked_out'  => $autoCount,
            'total_hours'       => round($totalMinutes / 60, 1),
        ];
    }

    public function summarize(array $payload): string
    {
        if (!empty($payload['error'])) {
            return 'unavailable: ' . $payload['error'];
        }
        if (($payload['mode'] ?? '') === 'year') {
            return ($payload['days_present'] ?? 0) . ' day(s) present, '
                . ($payload['days_absent'] ?? 0) . ' absent in ' . ($payload['year'] ?? '?')
                . '; ' . ($payload['late_days'] ?? 0) . ' late; '
                . ($payload['total_hours'] ?? 0) . ' total hours';
        }
        $s = $payload['summary'] ?? [];
        return ($s['days_recorded'] ?? 0) . ' day(s) recorded in ' . ($payload['month'] ?? '?')
            . '; ' . ($s['late_days'] ?? 0) . ' late; '
            . ($s['missing_clock_out'] ?? 0) . ' missing clock-out';
    }
}

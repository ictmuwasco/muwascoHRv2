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
        return 'Retrieve the signed-in employee\'s own attendance records for one month (clock-in, '
            . 'clock-out, late flag, status and computed hours per day, plus a summary). '
            . 'Defaults to LAST month. Use this for questions about the employee\'s own '
            . 'attendance, clock-ins, lateness or missing clock-outs.';
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

        // Month: model-supplied YYYY-MM, validated; anything else falls back
        // to the previous month (the most common question).
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

    public function summarize(array $payload): string
    {
        if (!empty($payload['error'])) {
            return 'unavailable: ' . $payload['error'];
        }
        $s = $payload['summary'] ?? [];
        return ($s['days_recorded'] ?? 0) . ' day(s) recorded in ' . ($payload['month'] ?? '?')
            . '; ' . ($s['late_days'] ?? 0) . ' late; '
            . ($s['missing_clock_out'] ?? 0) . ' missing clock-out';
    }
}

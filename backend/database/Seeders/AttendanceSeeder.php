<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeder;
use App\Database\DatabaseConnection;

/**
 * Attendance Seeder
 *
 * The demo database's attendance history ended 2026-07-31, so every
 * "today"-scoped widget (dashboard stats, charts/attendance, hr-insights
 * attendance block) rendered 0% / "No attendance data yet". This seeder
 * backfills realistic recent attendance for the last 14 calendar days
 * (weekdays only) so the live widgets have data:
 *
 *   - ~5% of the active roster is absent each workday (no row)
 *   - ~8% arrive late (is_late = 1, clock_in after 08:15)
 *   - the rest clock in 07:42-08:12 and out between 16:45-17:59
 *   - employees on APPROVED leave spanning a date get no attendance row
 *     (keeps "on leave" and "absent" mutually consistent)
 *   - TODAY is left partially "clocked_in" (~40%) so the live clock-in
 *     counters have signal too
 *
 * Idempotent: dates that already have attendance rows are skipped, so
 * re-running only fills the gap since the last run.
 */
class AttendanceSeeder extends Seeder
{
    /** Calendar days to look back (weekdays within the window are seeded). */
    private const WINDOW_DAYS = 14;

    public function run(): void
    {
        $conn = DatabaseConnection::getInstance()->getConnection();

        // Active roster (falls back to office 1 when the column is absent).
        try {
            $employees = $conn->executeQuery(
                "SELECT id, COALESCE(office_id, 1) AS office_id
                 FROM employees WHERE employee_status = 'active'"
            )->fetchAllAssociative();
        } catch (\Throwable $e) {
            $employees = array_map(
                static fn (array $r): array => ['id' => (int) $r['id'], 'office_id' => 1],
                $conn->executeQuery(
                    "SELECT id, 1 AS office_id FROM employees WHERE employee_status = 'active'"
                )->fetchAllAssociative()
            );
        }

        if ($employees === []) {
            echo "  - no active employees; nothing to seed\n";
            return;
        }

        // Approved leave windows - attendance must not contradict them.
        $cutoff = date('Y-m-d', strtotime('-' . self::WINDOW_DAYS . ' days'));
        $onLeave = [];
        foreach (
            $conn->executeQuery(
                "SELECT employee_id, start_date, end_date FROM leave_applications
                 WHERE status = 'approved' AND end_date >= ?",
                [$cutoff]
            )->fetchAllAssociative() as $l
        ) {
            $onLeave[(int) $l['employee_id']][] = [(string) $l['start_date'], (string) $l['end_date']];
        }

        $inserted = 0;
        $skippedDates = 0;
        $today = date('Y-m-d');

        for ($i = self::WINDOW_DAYS - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i day"));

            // Weekends are non-working days.
            if (in_array((int) date('N', strtotime($date)), [6, 7], true)) {
                continue;
            }

            // Idempotent: skip dates that already have attendance rows.
            if ((int) ($conn->executeQuery(
                'SELECT COUNT(*) FROM attendance WHERE attendance_date = ?',
                [$date]
            )->fetchOne() ?? 0) > 0) {
                $skippedDates++;
                continue;
            }

            $isToday = ($date === $today);

            foreach ($employees as $emp) {
                $employeeId = (int) $emp['id'];

                // On approved leave that day -> no attendance row.
                if (isset($onLeave[$employeeId])) {
                    $covered = false;
                    foreach ($onLeave[$employeeId] as [$start, $end]) {
                        if ($date >= $start && $date <= $end) { $covered = true; break; }
                    }
                    if ($covered) {
                        continue;
                    }
                }

                // Deterministic pseudo-random split per employee+date so
                // re-runs reproduce the same pattern.
                $roll = crc32($employeeId . '|' . $date) % 100;
                if ($roll < 5) {
                    continue; // absent - no row
                }
                $late = $roll < 13; // next ~8% arrive late

                $inH = $late ? 8 : 7;
                $inM = $late ? 16 + ($roll % 31) : 42 + ($roll % 21); // late 08:16-08:46, on-time 07:42-08:02
                $clockIn = sprintf('%s %02d:%02d:00', $date, $inH, $inM);

                if ($isToday && ($roll % 5) < 2) {
                    $status = 'clocked_in';   // still on the clock right now
                    $clockOut = null;
                } else {
                    $status = 'clocked_out';
                    $clockOut = sprintf('%s 16:%02d:00', $date, 45 + ($roll % 15));
                    if ($roll % 3 === 0) {
                        $clockOut = sprintf('%s 17:%02d:00', $date, $roll % 60);
                    }
                }

                try {
                    $conn->executeStatement(
                        'INSERT INTO attendance
                            (employee_id, office_id, clock_in_office_id, clock_out_office_id,
                             clock_in, clock_out, is_late, status, created_at, updated_at)
                         VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)',
                        [
                            $employeeId,
                            (int) $emp['office_id'],
                            (int) $emp['office_id'],
                            $clockIn,
                            $clockOut,
                            $late ? 1 : 0,
                            $status,
                            $clockIn,
                            $clockOut ?? $clockIn,
                        ]
                    );
                    $inserted++;
                } catch (\Throwable $e) {
                    // Duplicate (unique employee+date) or constraint conflict:
                    // skip the row, never abort the whole seed.
                    error_log('[AttendanceSeeder] row skipped: ' . $e->getMessage());
                }
            }
        }

        echo "  - attendance seeded: {$inserted} rows across "
            . (self::WINDOW_DAYS - $skippedDates) . " workday(s)"
            . ($skippedDates > 0 ? " ({$skippedDates} already-seeded date(s) skipped)" : '')
            . "\n";
    }
}

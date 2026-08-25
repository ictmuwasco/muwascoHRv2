<?php

declare(strict_types=1);

/**
 * Attendance midnight closer (CLI).
 *
 * Closes every attendance session left open from a previous day:
 *   clock_out        = end of that employee's attendance day (Africa/Nairobi)
 *   status           = 'auto_clocked_out'
 *   auto_clocked_out = 1
 *
 * This is the scheduled safety net for employees who never reopen the app.
 * The identical closure also runs lazily per employee on every attendance
 * read/write via AttendanceController::reconcileStaleSession(), so state can
 * never go stale even if this job does not fire.
 *
 * Windows Task Scheduler (daily 00:05):
 *   Program : C:\xampp\php\php.exe
 *   Args    : "C:\xampp\htdocs\hrdemo\backend\cron\auto_clockout.php"
 *   Start in: C:\xampp\htdocs\hrdemo\backend\cron
 *
 * Manual run:
 *   php backend/cron/auto_clockout.php
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Helpers\Database;

try {
    $db = Database::getInstance();

    // Preview which sessions will be closed (for logging / dry insight)
    $open = $db->fetchAll(
        "SELECT id, employee_id, clock_in
         FROM attendance
         WHERE clock_out IS NULL AND DATE(clock_in) < ?",
        's',
        [date('Y-m-d')]
    );

    if (empty($open)) {
        echo '[' . date('Y-m-d H:i:s') . "] No open sessions to close.\n";
        exit(0);
    }

    // Single atomic statement - mirrors AttendanceController::reconcileStaleSession()
    // and DashboardController::autoClockOutPreviousDays().
    $db->query(
        "UPDATE attendance
           SET clock_out = DATE_FORMAT(clock_in, '%Y-%m-%d 23:59:59'),
               status = 'auto_clocked_out',
               auto_clocked_out = 1,
               updated_at = NOW()
         WHERE clock_out IS NULL AND DATE(clock_in) < ?",
        's',
        [date('Y-m-d')]
    );

    echo '[' . date('Y-m-d H:i:s') . '] Auto-closed ' . count($open) . ' session(s) for employee id(s): '
        . implode(',', array_unique(array_column($open, 'employee_id'))) . "\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

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
 * Phase 5: this script is a thin CLI wrapper around
 * Services\Attendance\AttendanceCloseService — the SINGLE implementation of
 * the missed clock-out rule, shared with the per-employee lazy reconcile
 * (AttendanceController::dashboardAction) and the manual ops trigger
 * (POST /attendance/auto-clockout). The service is idempotent: repeated
 * runs find nothing left to close, so "when did it run?" never matters.
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

use App\Services\Attendance\AttendanceCloseService;

try {
    $result = (new AttendanceCloseService())->closeStaleOpenSessions();

    if ($result['closed'] === 0) {
        echo '[' . date('Y-m-d H:i:s') . "] No open sessions to close.\n";
        exit(0);
    }

    echo '[' . date('Y-m-d H:i:s') . '] Auto-closed ' . $result['closed'] . ' session(s) for employee id(s): '
        . implode(',', $result['employee_ids']) . "\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

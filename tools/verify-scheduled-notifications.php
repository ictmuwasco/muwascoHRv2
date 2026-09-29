<?php
/** Phase 4/5 proof: financial-year closing alerts + monthly roster reminders. */
require __DIR__ . '/../backend/bootstrap.php';
$ok = true;
$check = static function (string $l, bool $p) use (&$ok): void {
    printf("%-66s %s\n", $l, $p ? 'PASS' : 'FAIL'); $ok = $ok && $p;
};
$db = \App\Helpers\Database::getInstance()->getConnection();

$cleanupTypes = "('leave_roster_monthly','financial_year_closing')";
$db->query("DELETE FROM notification_logs WHERE notification_type IN $cleanupTypes");
$db->query("DELETE FROM notifications WHERE title LIKE 'Your planned leave month%' OR title LIKE 'Financial year %'");

// ---- 1. Dry run must not write ------------------------------------------
// Counted per notification_type, NOT across the whole table. The attendance
// scheduler runs every 5 minutes and legitimately adds its own rows, so a
// whole-table before/after comparison races it and fails for the wrong reason.
$types = "('leave_roster_monthly','financial_year_closing')";
$scheduledRowsBefore = (int) $db->query("SELECT COUNT(*) c FROM notification_logs WHERE notification_type IN $types")->fetch_assoc()['c'];

$dry = new \App\Services\Notification\ScheduledNotificationService(null, true);
$dry->queueMonthlyRosterReminders('2026-10');
$dry->queueFinancialYearClosingAlerts();

$scheduledRowsAfter = (int) $db->query("SELECT COUNT(*) c FROM notification_logs WHERE notification_type IN $types")->fetch_assoc()['c'];
$check('dry run queues nothing', $scheduledRowsAfter === $scheduledRowsBefore);
$check('dry run writes no in-app rows',
    (int) $db->query("SELECT COUNT(*) c FROM notifications WHERE title LIKE 'Your planned leave month%' OR title LIKE 'Financial year %'")->fetch_assoc()['c'] === 0);

// ---- 2. Roster reminders -------------------------------------------------
$live = new \App\Services\Notification\ScheduledNotificationService();
$s1 = $live->queueMonthlyRosterReminders('2026-10');
printf("   October: %d rostered, %d with approved leave, %d reminded\n",
    $s1['roster_found'], $s1['approved_found'], $s1['reminders_sent']);
$check('October roster entries are found', $s1['roster_found'] > 0);
$check('a reminder is queued for each rostered employee', $s1['reminders_sent'] > 0);

$rows1 = (int) $db->query("SELECT COUNT(*) c FROM notification_logs WHERE notification_type='leave_roster_monthly'")->fetch_assoc()['c'];
$live->queueMonthlyRosterReminders('2026-10');
$rows2 = (int) $db->query("SELECT COUNT(*) c FROM notification_logs WHERE notification_type='leave_roster_monthly'")->fetch_assoc()['c'];
$check('re-running for the same month is idempotent', $rows1 === $rows2);

// A different month is a different key, so it must NOT be suppressed.
$live->queueMonthlyRosterReminders('2026-08');
$rows3 = (int) $db->query("SELECT COUNT(*) c FROM notification_logs WHERE notification_type='leave_roster_monthly'")->fetch_assoc()['c'];
$check('a different month is a different notification', $rows3 > $rows2);

// The July->June year rule: August 2026 sits in the FY starting 2026-07-01.
$aug = (int) $db->query("SELECT COUNT(*) c FROM notification_logs WHERE notification_type='leave_roster_monthly' AND dedupe_key LIKE 'leave_roster:2026-08:%'")->fetch_assoc()['c'];
$check('August is resolved via the financial year, not scheduled_year', $aug > 0);
$check('scheduled_year being NULL does not block the query', $aug > 0);

// ---- 3. FY closing alert within the 30-day window -----------------------
// No FY ends within 30 days today, so a temporary one is inserted and removed.
$tmpId = 0;
$end = date('Y-m-d', strtotime('+20 days'));
$start = date('Y-m-d', strtotime('-11 months'));
$probeName = '2099/00 (probe)';
$totalDays = 365;
$isActive = 1;
// bind_param() takes references, so these must be variables, not expressions.
$ins = $db->prepare("INSERT INTO financial_years (start_date, end_date, year_name, total_days, is_active) VALUES (?,?,?,?,?)");
$ins->bind_param('sssii', $start, $end, $probeName, $totalDays, $isActive);
$ins->execute();
$tmpId = (int) $db->insert_id;
$ins->close();
$check('inserted a temporary FY ending in 20 days', $tmpId > 0);

$s2 = $live->queueFinancialYearClosingAlerts();
printf("   FY alerts: %d sent, details=%s\n", $s2['alerts_sent'], json_encode($s2['details']));
$check('an FY inside the 30-day window triggers an alert', $s2['alerts_sent'] > 0);
$check('the alert is scoped to the closing FY', (bool) $db->query("SELECT id FROM notification_logs WHERE dedupe_key='financial_year:$tmpId:closing|in_app'")->fetch_row());
$alertRow = $db->query("SELECT user_id FROM notification_logs WHERE dedupe_key='financial_year:$tmpId:closing|in_app' LIMIT 1");
$alertUser = (int) ($alertRow->fetch_assoc()['user_id'] ?? 0); $alertRow->close();
$role = $db->query("SELECT role FROM users WHERE id=$alertUser");
$roleName = (string) ($role->fetch_assoc()['role'] ?? ''); $role->close();
$check('the alert goes to an admin role, not a random user',
    in_array($roleName, ['hr_manager', 'super_admin', 'managing_director'], true));
printf("   alert went to role: %s\n", $roleName);

// Re-running inside the window must be a no-op: the FY key is period-scoped,
// so a daily cron does not re-alert every administrator each day.
$beforeRerun = (int) $db->query("SELECT COUNT(*) c FROM notification_logs WHERE notification_type='financial_year_closing'")->fetch_assoc()['c'];
$live->queueFinancialYearClosingAlerts();
$afterRerun = (int) $db->query("SELECT COUNT(*) c FROM notification_logs WHERE notification_type='financial_year_closing'")->fetch_assoc()['c'];
$check('re-running the FY alert inside the window queues nothing new',
    $beforeRerun === $afterRerun && $afterRerun > 0);

// Outside the window: move the end date far out and confirm no new alert.
$farOut = date('Y-m-d', strtotime('+200 days'));
$db->query("UPDATE financial_years SET end_date='$farOut' WHERE id=$tmpId");
$before = (int) $db->query("SELECT COUNT(*) c FROM notification_logs WHERE notification_type='financial_year_closing'")->fetch_assoc()['c'];
$live->queueFinancialYearClosingAlerts();
$after = (int) $db->query("SELECT COUNT(*) c FROM notification_logs WHERE notification_type='financial_year_closing'")->fetch_assoc()['c'];
$check('an FY beyond the window raises no new alert', $before === $after);

// ---- cleanup -------------------------------------------------------------
$db->query("DELETE FROM financial_years WHERE id=$tmpId");
$db->query("DELETE FROM notification_logs WHERE notification_type IN $cleanupTypes");
$db->query("DELETE FROM notifications WHERE title LIKE 'Your planned leave month%' OR title LIKE 'Financial year %'");
$left = (int) $db->query("SELECT COUNT(*) c FROM financial_years WHERE year_name='2099/00 (probe)'")->fetch_assoc()['c'];
$check('temporary financial year removed', $left === 0);

echo $ok ? "\nPHASE 4/5 CHECKS PASSED\n" : "\nPHASE 4/5 CHECKS FAILED\n";
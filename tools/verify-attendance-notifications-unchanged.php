<?php
/** Regression guard: the 025-era scheduled claim() must behave identically. */
require __DIR__ . '/../backend/bootstrap.php';
$ok = true; $check = function(string $l, bool $p) use (&$ok) { printf("%-62s %s\n", $l, $p?'PASS':'FAIL'); $ok = $ok && $p; };
$db = \App\Helpers\Database::getInstance()->getConnection();
$r = $db->query("SELECT id FROM users WHERE is_active=1 LIMIT 1"); $uid = (int) $r->fetch_assoc()['id']; $r->close();
$db->query("DELETE FROM notification_logs WHERE notification_type='attendance_absence'");
$logs = new \App\Repositories\NotificationLogRepository();
$d = '2026-09-28';
$a = $logs->claim($uid, null, 'attendance_absence', 'sms', 'warning', $d);
$check('scheduled claim() returns an id on first call', $a !== null);
$b = $logs->claim($uid, null, 'attendance_absence', 'sms', 'warning', $d);
$check('scheduled claim() returns null on duplicate run', $b === null);
$c = $logs->claim($uid, null, 'attendance_absence', 'sms', 'absent', $d);
$check('different stage still claims (dedupe per stage)', $c !== null);
$row = $db->query("SELECT dedupe_key FROM notification_logs WHERE id={$a}");
$key = (string) $row->fetch_assoc()['dedupe_key']; $row->close();
$check('dedupe_key matches the 094 backfill format', $key === $d . '|attendance_absence|warning');
// Cross-day must be independent.
$e = $logs->claim($uid, null, 'attendance_absence', 'sms', 'warning', '2026-09-29');
$check('next day is a fresh notification', $e !== null);
$db->query("DELETE FROM notification_logs WHERE notification_type='attendance_absence'");
echo $ok ? "\nATTENDANCE PATH UNCHANGED\n" : "\nREGRESSION\n";
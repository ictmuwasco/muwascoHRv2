<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Services\ErrorTracking\ErrorTrackerService;

function mk(string $m): Throwable { return new RuntimeException($m); }

$svc = ErrorTrackerService::getInstance();
$msg = 'Database timeout while saving clock-in record for employee ZEBRASMOKE-42';
$ctx = ['http_status' => 500, 'endpoint' => '/hrdemo/api/attendance/clock-in'];

$r1 = $svc->captureThrowable(mk($msg), $ctx + ['user_id' => 7]);
$r2 = $svc->captureThrowable(mk($msg), $ctx + ['user_id' => 7]);
$r3 = $svc->captureThrowable(mk($msg), $ctx + ['user_id' => 12, 'employee_id' => 88]);
echo ($r1 && $r2 && $r3) ? "PASS capture x3\n" : "FAIL capture\n";
if (!$r1) { exit(1); }
$u = $r1['error_uuid'];
echo ($u !== $r2['error_uuid']) ? "PASS unique uuids ($u ...)\n" : "FAIL dup uuid\n";

$g = db()->fetchOne(
    "SELECT g.* FROM error_groups g JOIN application_errors ae ON ae.error_group_id=g.id WHERE ae.error_uuid=?",
    's',
    [$u]
);
echo $g['occurrence_count'] == 3 ? "PASS occurrence_count=3\n" : "FAIL occ={$g['occurrence_count']}\n";
echo $g['affected_user_count'] == 2 ? "PASS affected_users=2 distinct\n" : "FAIL aff={$g['affected_user_count']}\n";
echo $g['severity'] === 'CRITICAL' ? "PASS severity=CRITICAL (business-critical bump)\n" : "FAIL sev={$g['severity']}\n";
echo $g['module'] === 'Attendance' ? "PASS module=Attendance (prefix stripped)\n" : "FAIL mod={$g['module']}\n";
echo strpos($g['fingerprint'], 'attendance') === 0 ? "PASS fingerprint={$g['fingerprint']}\n" : "FAIL fp={$g['fingerprint']}\n";

$occ = db()->fetchOne("SELECT stack_trace, request_id, employee_id FROM application_errors WHERE error_uuid=?", 's', [$u]);
echo strlen((string) $occ['stack_trace']) > 50 ? "PASS stack_trace stored\n" : "FAIL stack missing\n";
echo preg_match('/^req_/i', (string) $occ['request_id']) ? "PASS request_id={$occ['request_id']}\n" : "FAIL rid\n";
$occ3 = db()->fetchOne("SELECT employee_id FROM application_errors WHERE error_uuid=?", 's', [$r3['error_uuid']]);
echo $occ3['employee_id'] == 88 ? "PASS employee_id propagated (on 3rd occurrence)\n" : "FAIL emp={$occ3['employee_id']}\n";

db()->query("DELETE ae FROM application_errors ae JOIN error_groups g ON g.id=ae.error_group_id WHERE g.sample_message LIKE '%ZEBRASMOKE%'", '', []);
db()->query("DELETE FROM error_groups WHERE sample_message LIKE '%ZEBRASMOKE%'", '', []);
$left = db()->fetchValue("SELECT COUNT(*) FROM application_errors WHERE message LIKE '%ZEBRASMOKE%'");
echo $left == 0 ? "PASS cleanup pristine\n" : "WARN left=$left\n";
echo "SMOKE COMPLETE\n";

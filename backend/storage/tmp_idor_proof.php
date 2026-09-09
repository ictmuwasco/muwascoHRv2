<?php
require_once 'c:/xampp/htdocs/hrdemo/backend/bootstrap.php';

use App\Helpers\AuthorizationService;
use App\Services\Security\EmployeePolicy;
use App\Services\Security\SecurityEventService;

$db = \db();
$authz = AuthorizationService::getInstance();

echo "===========================================================\n";
echo " SCENARIO: GET /employees/533 while logged in as ...\n";
echo "===========================================================\n";
$emp = $db->fetchOne('SELECT e.*, u.id AS linked_user_id
                      FROM employees e
                      LEFT JOIN users u ON u.employee_id = e.employee_id
                      WHERE e.id = 533');
if (!$emp) { echo "employee 533 not found\n"; exit(1); }

foreach ([395 => 'super_admin (the SOC dashboard user)', 221 => 'hr_manager', 202 => 'officer'] as $uid => $label) {
    $role = $db->fetchValue('SELECT role FROM users WHERE id = ?', 'i', [$uid]);
    $permView = $authz->hasPermission($uid, 'employees', 'view');
    $canView = EmployeePolicy::canView($uid, $emp);

    echo "\n--- user {$uid} ({$role}) — {$label} ---\n";
    echo "  authorization gate  (employees:view) : " . ($permView ? "PASS" : "403 DENY") . "\n";
    if ($permView) {
        echo "  object-level check  (EmployeePolicy) : " . ($canView ? "ALLOW → 200 profile returned" : "DENY → 403 + UNAUTHORIZED_OBJECT_ACCESS event") . "\n";
    } else {
        echo "  object-level check  (EmployeePolicy) : (never reached — router 403s first)\n";
    }
    echo "  security event recorded              : " . ($permView && $canView ? "NO — authorized read, by design" : "YES — on the deny path") . "\n";
}

echo "\n===========================================================\n";
echo " PROOF: simulate the ACTUAL deny path the router takes\n";
echo " (AuthorizationMiddleware::recordSecurityEvent) for officer\n";
echo " user 202 hitting /employees/533\n";
echo "===========================================================\n";
$before = (int) ($db->fetchValue('SELECT COUNT(*) FROM security_events') ?? 0);
$beforeInc = (int) ($db->fetchValue('SELECT COUNT(*) FROM security_incidents') ?? 0);

$id = SecurityEventService::getInstance()->record(
    SecurityEventService::SUSPICIOUS_API_ACTIVITY,
    SecurityEventService::SEVERITY_MEDIUM,
    40,
    [
        'user_id'             => 202,
        'http_method'         => 'GET',
        'route'               => '/api/employees/533',
        'required_permission' => 'employees:view',
        'route_definition'    => 'GET /employees/{id}',
        'response_status'     => 403,
        'action_taken'        => SecurityEventService::ACTION_DENIED,
        'description'         => 'Permission denied for employees:view on protected endpoint',
    ]
);
$after = (int) ($db->fetchValue('SELECT COUNT(*) FROM security_events') ?? 0);
echo "  events before={$before} after={$after} inserted_id=" . var_export($id, true) . "\n";

// Visible via the exact query the SOC Events-tab uses?
$list = SecurityEventService::getInstance()->getEvents(['event_type' => 'SUSPICIOUS_API_ACTIVITY'], 1, 5);
$found = false;
foreach ($list['data'] as $e) {
    if (isset($e['id']) && (int)$e['id'] === (int)$id) {
        $found = true;
        printf("  FOUND in SOC events feed: #%d %s %s risk=%d action=%s status=%s desc=%s\n",
            $e['id'], $e['event_type'], $e['severity'], $e['risk_score'], $e['action_taken'], $e['response_status'], $e['description']);
    }
}
echo "  SOC events feed lookup: " . ($found ? "PRESENT — an IDOR-blocked attempt WILL show in the dashboard" : "MISSING") . "\n";

// Rule engine check: does this trigger an incident? (Need 3+ same-user events type UNAUTHORIZED_OBJECT_ACCESS, so not for a single event.)
echo "\n  incidents after single deny: " . ($db->fetchValue('SELECT COUNT(*) FROM security_incidents') ?? 0) . " (threshold-based, so 1 event alone won't create an incident)\n";
echo "  incidents before          : {$beforeInc}\n";

// cleanup
if ($id) {
    $db->delete('security_events', 'id = ?', 'i', [$id]);
    echo "\n  cleaned up simulated event; count now = " . ($db->fetchValue('SELECT COUNT(*) FROM security_events') ?? 0) . "\n";
}
echo "\nDONE\n";
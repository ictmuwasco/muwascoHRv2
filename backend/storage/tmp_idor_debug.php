<?php
require_once 'c:/xampp/htdocs/hrdemo/backend/bootstrap.php';

use App\Services\Security\EmployeePolicy;
use App\Services\Security\SecurityEventService;

$db = \db();

function canViewDebug(int $userId, array $employee): array {
    if ($userId <= 0 || empty($employee)) return ['allowed' => false, 'role' => '?', 'perms' => '-', 'is_self' => 'N', 'same_dept' => 'N'];
    $authz = \App\Helpers\AuthorizationService::getInstance();
    $hasView  = $authz->hasPermission($userId, 'employees', 'view');
    $hasEdit  = $authz->hasPermission($userId, 'employees', 'edit');
    $hasSec   = $authz->hasPermission($userId, 'security', 'view');
    $user = \App\Helpers\Auth::getInstance()->getUserById($userId);
    $role = $user['role'] ?? '?';
    $isSelf = isset($employee['user_id']) && (int) $employee['user_id'] === $userId;
    $sameDept = ($user['role'] ?? '') === 'dept_head'
        && isset($employee['department_id'], $user['department_id'])
        && (int) $employee['department_id'] === (int) $user['department_id'];
    $allowed = EmployeePolicy::canView($userId, $employee);
    return [
        'allowed' => $allowed,
        'role' => $role,
        'perms' => "emp-view=" . ($hasView ? 'Y' : 'N') . " emp-edit=" . ($hasEdit ? 'Y' : 'N') . " sec-view=" . ($hasSec ? 'Y' : 'N'),
        'is_self' => $isSelf ? 'Y' : 'N',
        'same_dept' => $sameDept ? 'Y' : 'N',
    ];
}

echo "=== Employee records around 533/535 ===\n";
$employees = $db->fetchAll('SELECT id, employee_id, department_id, first_name, last_name FROM employees WHERE id BETWEEN 525 AND 545 ORDER BY id');
foreach ($employees as $e) {
    printf("  #%d employee_id=%-10s dept=%s %s %s\n", $e['id'], $e['employee_id'], $e['department_id'] ?? '-', $e['first_name'] ?? '', $e['last_name'] ?? '');
}

echo "\n=== canView for accounts that can open the SOC dashboard (super_admin 395/396, hr_manager 221) on employee 533 ===\n";
$emp = $db->fetchOne('SELECT * FROM employees WHERE id = 533');
if (!$emp) { echo "  employee 533 not found!\n"; } else {
    foreach ([395, 396, 221] as $uid) {
        $r = canViewDebug($uid, $emp);
        printf("  user=%-3d role=%-14s %-28s is_self=%s same_dept=%s => %s\n", $uid, $r['role'], $r['perms'], $r['is_self'], $r['same_dept'], $r['allowed'] ? 'ALLOWED' : 'DENIED');
    }
}

echo "\n=== canView for a regular officer (user 202) on employee 533 ===\n";
if ($emp) {
    $r = canViewDebug(202, $emp);
    printf("  user=202 role=%-14s %-28s is_self=%s same_dept=%s => %s\n", $r['role'], $r['perms'], $r['is_self'], $r['same_dept'], $r['allowed'] ? 'ALLOWED' : 'DENIED');
    // craft a record that WOULD be the officer's own (simulate user_id join)
    $own = $emp; $own['user_id'] = 202;
    printf("  user=202 on a record with user_id=202 (own) => %s  <-- NOTE employees table HAS NO user_id col\n", EmployeePolicy::canView(202, $own) ? 'ALLOWED' : 'DENIED');
}

echo "\n=== Simulate showAction's DENY branch for officer user 202 on employee 533 ===\n";
$before = (int) ($db->fetchValue('SELECT COUNT(*) FROM security_events') ?? 0);
$id = SecurityEventService::getInstance()->record(
    SecurityEventService::UNAUTHORIZED_OBJECT_ACCESS,
    SecurityEventService::SEVERITY_HIGH,
    65,
    [
        'user_id' => 202,
        'resource_type' => 'employee',
        'resource_id' => 533,
        'response_status' => 403,
        'action_taken' => SecurityEventService::ACTION_DENIED,
        'description' => 'Unauthorized access attempt to employee#533',
        'route' => '/api/employees/533',
    ]
);
$after = (int) ($db->fetchValue('SELECT COUNT(*) FROM security_events') ?? 0);
echo "  events before={$before} after={$after} inserted_id=" . var_export($id, true) . " delta=" . ($after - $before) . "\n";
if ($id) {
    $db->delete('security_events', 'id = ?', 'i', [$id]);
    echo "  cleanup done; count now = " . ($db->fetchValue('SELECT COUNT(*) FROM security_events') ?? 0) . "\n";
}
<?php
require_once __DIR__ . '/../bootstrap.php';

echo "Testing RBAC permissions:\n";

$rbac = \App\Helpers\RBAC::getInstance();

$roles = ['officer', 'employee', 'hr_manager', 'super_admin'];
foreach ($roles as $role) {
    $result = $rbac->hasPermission($role, 'leave', 'view');
    echo "  {$role}.leave.view = " . ($result ? 'true' : 'false') . "\n";
}

echo "\nTesting AuthorizationService:\n";
$_SESSION = [];
$_SESSION['session_valid'] = true;
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'officer';

$service = \App\Helpers\AuthorizationService::getInstance();
$result = $service->hasPermission(1, 'leave', 'view');
echo "  officer (uid=1).leave.view = " . ($result ? 'true' : 'false') . "\n";

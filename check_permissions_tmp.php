<?php
require 'backend/bootstrap.php';

$db = db();

// Check what the AuthorizationService returns for a specific user
// Test with the sub_section_head (user 203) and section_head (204)
$testUserIds = [203, 204, 209, 214, 215]; // mix of sub_section_heads and section_heads

$authz = \App\Helpers\AuthorizationService::getInstance();

foreach ($testUserIds as $uid) {
    $user = $db->fetchOne('SELECT id, email, role FROM users WHERE id = ?', 'i', [$uid]);
    if (!$user) continue;

    // Simulate a session for this user
    $_SESSION['user_id']   = (int) $user['id'];
    $_SESSION['user_role'] = $user['role'];

    $hasPerm = $authz->hasPermission($uid, 'performance', 'supervise');

    // Also get the effective permission strings to see if supervise is included
    $authz->clearCache();
    $strings = $authz->getEffectivePermissionStrings($uid);
    $hasInStrings = in_array('performance:supervise', $strings, true);

    echo "User {$uid} ({$user['email']}) role={$user['role']}\n";
    echo "  hasPermission(supervise): " . ($hasPerm ? 'YES' : 'NO') . "\n";
    echo "  supervise in getEffectivePermissionStrings: " . ($hasInStrings ? 'YES' : 'NO') . "\n\n";
}

// Also check what role_permissions the DB has for performance:supervise
echo "=== DB: role_permissions for performance:supervise ===\n";
$rows = $db->fetchAll(
    'SELECT role, is_granted FROM role_permissions WHERE module = ? AND action = ?',
    'ss', ['performance', 'supervise']
);
foreach ($rows as $r) {
    echo "  role={$r['role']} is_granted={$r['is_granted']}\n";
}

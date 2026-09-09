<?php
require_once __DIR__ . '/../bootstrap.php';

echo "Testing RBAC with debug output:\n";

// Clear cache
$rbac = \App\Helpers\RBAC::getInstance();
$rbac->clearCache();

// Test direct RBAC call
echo "Direct RBAC call:\n";
$result = $rbac->hasPermission('officer', 'leave', 'view');
echo "  RBAC->hasPermission('officer', 'leave', 'view') = " . ($result ? 'true' : 'false') . "\n";

// Check database directly
echo "\nDirect database query:\n";
$conn = \App\Helpers\Database::getInstance()->getConnection();
$stmt = $conn->prepare('SELECT is_granted FROM role_permissions WHERE role = ? AND module = ? AND action = ? LIMIT 1');
$role = 'officer';
$module = 'leave';
$action = 'view';
$stmt->bind_param('sss', $role, $module, $action);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

echo "  role_permissions row: " . ($row ? "is_granted = {$row['is_granted']}" : 'not found') . "\n";

// Check if table exists
echo "\nCheck if table exists:\n";
$tableResult = $conn->query("SHOW TABLES LIKE 'role_permissions'");
echo "  role_permissions table exists: " . ($tableResult->num_rows > 0 ? 'yes' : 'no') . "\n";
$tableResult->free();

echo "\nTest complete.\n";

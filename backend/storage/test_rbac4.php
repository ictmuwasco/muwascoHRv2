<?php
require_once __DIR__ . '/../bootstrap.php';

echo "Simulating test with debug output:\n";

// Clear cache
$rbac = \App\Helpers\RBAC::getInstance();
$rbac->clearCache();

// Create a test user
$conn = \App\Helpers\Database::getInstance()->getConnection();
$email = 'matrix-test-' . bin2hex(random_bytes(6)) . '@example.test';
$password = password_hash('matrix-test-password', PASSWORD_DEFAULT);

$stmt = $conn->prepare(
    'INSERT INTO users (email, first_name, last_name, surname, gender, password, role, is_active)
     VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
);
$firstName = 'Matrix';
$lastName = 'RoleTest';
$surname = 'Tester';
$gender = 'male';
$role = 'officer';
$stmt->bind_param('sssssss', $email, $firstName, $lastName, $surname, $gender, $password, $role);
$stmt->execute();
$userId = (int) $conn->insert_id;
$stmt->close();

echo "Created user ID: {$userId} with role: {$role}\n";

// Set session
$_SESSION = [];
$_SESSION['session_valid'] = true;
$_SESSION['user_id'] = $userId;
$_SESSION['user_role'] = $role;

echo "Session user_id: {$_SESSION['user_id']}\n";
echo "Session user_role: {$_SESSION['user_role']}\n";

// Test permission using AuthorizationService
$service = \App\Helpers\AuthorizationService::getInstance();
$service->clearCache();

echo "\nAuthorizationService tests:\n";
echo "  hasPermission({$userId}, 'leave', 'view') = " . ($service->hasPermission($userId, 'leave', 'view') ? 'true' : 'false') . "\n";
echo "  hasPermission({$userId}, 'dashboard', 'view') = " . ($service->hasPermission($userId, 'dashboard', 'view') ? 'true' : 'false') . "\n";

// Test permission using RBAC directly
echo "\nRBAC tests:\n";
echo "  RBAC->hasPermission('officer', 'leave', 'view') = " . ($rbac->hasPermission('officer', 'leave', 'view') ? 'true' : 'false') . "\n";

// Check if the issue is with the resolveUserRole method
echo "\nChecking resolveUserRole:\n";
$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
$sessionRole = (string)($_SESSION['user_role'] ?? '');
echo "  sessionUserId: {$sessionUserId}\n";
echo "  sessionRole: {$sessionRole}\n";
echo "  userId === sessionUserId: " . ($userId === $sessionUserId ? 'true' : 'false') . "\n";

// Cleanup
$stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$stmt->close();

echo "\nTest complete.\n";

<?php
require_once __DIR__ . '/../bootstrap.php';

echo "Testing AuthorizationService with simulated test:\n";

// Simulate what the test does
$_SESSION = [];
$_SESSION['session_valid'] = true;

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
$_SESSION['user_id'] = $userId;
$_SESSION['user_role'] = $role;

// Test permission
$service = \App\Helpers\AuthorizationService::getInstance();
$service->clearCache();

echo "Session user_id: {$_SESSION['user_id']}\n";
echo "Session user_role: {$_SESSION['user_role']}\n";

$result = $service->hasPermission($userId, 'leave', 'view');
echo "hasPermission({$userId}, 'leave', 'view') = " . ($result ? 'true' : 'false') . "\n";

// Check RBAC directly
$rbac = \App\Helpers\RBAC::getInstance();
echo "RBAC->hasPermission('officer', 'leave', 'view') = " . ($rbac->hasPermission('officer', 'leave', 'view') ? 'true' : 'false') . "\n";

// Cleanup
$stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$stmt->close();

echo "\nTest complete.\n";

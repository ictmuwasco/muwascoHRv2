<?php
require __DIR__ . '/backend/bootstrap.php';

echo "=== permissions rows for module 'performance' ===\n";
foreach (db()->fetchAll("SELECT id, module, action, description FROM permissions WHERE module = 'performance' ORDER BY action") as $r) {
    echo json_encode($r), PHP_EOL;
}

echo "\n=== admin user + role ===\n";
foreach (db()->fetchAll("SELECT u.id, u.email, u.role_id, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.email = 'admin@muwasco.org'") as $r) {
    echo json_encode($r), PHP_EOL;
}

echo "\n=== role_permissions for performance/strategic_plan ===\n";
foreach (db()->fetchAll("SELECT r.name AS role_name, p.module, p.action FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id JOIN roles r ON r.id = rp.role_id WHERE p.module IN ('performance','strategic_plan') ORDER BY r.name, p.module, p.action") as $r) {
    echo json_encode($r), PHP_EOL;
}

<?php
declare(strict_types=1);

// Diagnostic: permission catalog + role_permissions DB state.
require_once 'c:/xampp/htdocs/hrdemo/backend/bootstrap.php';

$c = require 'c:/xampp/htdocs/hrdemo/backend/config/permissions.php';
echo 'modules count: ' . count($c['modules']) . PHP_EOL;
if (isset($c['modules']['security'])) { echo "security module: OK" . PHP_EOL; } else { echo "security module: MISSING" . PHP_EOL; }

try {
    $conn = \App\Helpers\Database::getInstance()->getConnection();
    $db = $conn->query('SELECT DATABASE()')->fetch_row()[0] ?? '?';
    echo 'database: ' . $db . PHP_EOL;

    $total = (int) ($conn->query('SELECT COUNT(*) FROM role_permissions')->fetch_row()[0] ?? 0);
    echo 'role_permissions total rows: ' . $total . PHP_EOL;

    $res = $conn->query('SELECT role, COUNT(*) AS c FROM role_permissions GROUP BY role ORDER BY role');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            echo sprintf('  role=%s rows=%d%s', $row['role'], $row['c'], PHP_EOL);
        }
    }

    $stmt = $conn->prepare("SELECT COUNT(*) FROM role_permissions WHERE role = ? AND module = ? AND action = ?");
    $role = 'section_head'; $module = 'dashboard'; $action = 'view';
    $stmt->bind_param('sss', $role, $module, $action);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    echo 'section_head dashboard:view rows: ' . $count . PHP_EOL;
} catch (\Throwable $e) {
    echo 'DB ERROR: ' . $e->getMessage() . PHP_EOL;
}


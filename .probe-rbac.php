<?php
declare(strict_types=1);
require __DIR__ . '/backend/bootstrap.php';

echo 'appraisals now: ', db()->fetchOne('SELECT COUNT(*) c FROM employee_appraisals')['c'], PHP_EOL;
echo 'id51 present: ', db()->fetchOne('SELECT COUNT(*) c FROM employee_appraisals WHERE id = 51')['c'], PHP_EOL;
echo 'by status: ', json_encode(db()->fetchAll('SELECT status, COUNT(*) c FROM employee_appraisals GROUP BY status')), PHP_EOL;

echo PHP_EOL, '=== RBAC-ish tables ===', PHP_EOL;
foreach (db()->fetchAll("SHOW TABLES LIKE '%permission%'") as $r) {
    echo '  perm-table: ', implode(',', array_values($r)), PHP_EOL;
}
foreach (db()->fetchAll("SHOW TABLES LIKE '%role%'") as $r) {
    echo '  role-table: ', implode(',', array_values($r)), PHP_EOL;
}

echo PHP_EOL, '=== roles columns ===', PHP_EOL;
try {
    foreach (db()->fetchAll('SHOW COLUMNS FROM roles') as $c) {
        echo '  ', $c['Field'], ' (', $c['Type'], ')', PHP_EOL;
    }
} catch (\Throwable $e) {
    echo '  ERROR: ', $e->getMessage(), PHP_EOL;
}

echo PHP_EOL, '=== roles rows ===', PHP_EOL;
try {
    foreach (db()->fetchAll('SELECT * FROM roles ORDER BY id') as $r) {
        echo '  ', json_encode($r), PHP_EOL;
    }
} catch (\Throwable $e) {
    echo '  ERROR: ', $e->getMessage(), PHP_EOL;
}

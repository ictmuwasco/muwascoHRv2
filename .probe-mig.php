<?php
require __DIR__ . '/backend/bootstrap.php';

$db = db();

echo "=== migration tracking table ===\n";
foreach ($db->fetchAll("SHOW TABLES LIKE '%migration%'") as $r) {
    echo implode(' | ', array_values($r)), "\n";
}

echo "\n=== 08x migrations recorded ===\n";
foreach ($db->fetchAll("SELECT * FROM migrations WHERE migration LIKE '08%' ORDER BY migration") as $r) {
    echo json_encode($r), "\n";
}

echo "\n=== employee_appraisals columns ===\n";
foreach ($db->fetchAll("SHOW COLUMNS FROM employee_appraisals") as $c) {
    printf("%-26s %-34s null=%s def=%s\n", $c['Field'], $c['Type'], $c['Null'], var_export($c['Default'], true));
}

echo "\n=== sample appraisal rows ===\n";
foreach ($db->fetchAll("SELECT id, employee_id, appraisal_cycle_id, status FROM employee_appraisals ORDER BY id LIMIT 8") as $r) {
    echo json_encode($r), "\n";
}

echo "\n=== appraisal_scores columns ===\n";
foreach ($db->fetchAll("SHOW COLUMNS FROM appraisal_scores") as $c) {
    printf("%-26s %-30s null=%s\n", $c['Field'], $c['Type'], $c['Null']);
}

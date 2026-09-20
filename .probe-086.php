<?php
require __DIR__ . '/backend/bootstrap.php';

echo "=== tables matching migrat ===\n";
foreach (db()->fetchAll("SHOW TABLES LIKE '%migrat%'") as $r) {
    echo json_encode($r), PHP_EOL;
}

echo "\n=== migration tracker (last 10) ===\n";
try {
    foreach (db()->fetchAll("SELECT * FROM migrations ORDER BY id DESC LIMIT 10") as $r) {
        echo json_encode($r), PHP_EOL;
    }
} catch (Throwable $e) {
    echo 'tracker read failed: ', $e->getMessage(), PHP_EOL;
}

echo "\n=== 085/086 present? ===\n";
try {
    foreach (db()->fetchAll("SELECT migration FROM migrations WHERE migration LIKE '08%' ORDER BY migration") as $r) {
        echo $r['migration'], PHP_EOL;
    }
} catch (Throwable $e) {
    echo 'read failed: ', $e->getMessage(), PHP_EOL;
}

echo "\n=== appraisal_cycles now ===\n";
foreach (db()->fetchAll("SELECT id, name, start_date, end_date, status, financial_year_id FROM appraisal_cycles ORDER BY id") as $r) {
    echo json_encode($r), PHP_EOL;
}

echo "\n=== active FY ===\n";
foreach (db()->fetchAll("SELECT id, year_name, start_date, end_date, is_active FROM financial_years WHERE is_active = 1 OR id = 39 ORDER BY id") as $r) {
    echo json_encode($r), PHP_EOL;
}

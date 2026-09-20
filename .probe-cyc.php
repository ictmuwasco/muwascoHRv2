<?php
require 'backend/bootstrap.php';

echo "=== appraisal_cycles columns ===", PHP_EOL;
foreach (db()->fetchAll('SHOW COLUMNS FROM appraisal_cycles') as $c) {
    printf("%-20s %-40s null=%-4s def=%s\n", $c['Field'], $c['Type'], $c['Null'], var_export($c['Default'], true));
}

echo PHP_EOL, "=== financial_years (recent) ===", PHP_EOL;
foreach (db()->fetchAll('SELECT id, year_name, start_date, end_date, is_active FROM financial_years ORDER BY start_date DESC LIMIT 6') as $r) {
    printf("  id=%-4s %-14s %s .. %s active=%s\n", $r['id'], $r['year_name'], $r['start_date'], $r['end_date'], $r['is_active']);
}

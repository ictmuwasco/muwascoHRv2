<?php
require 'backend/bootstrap.php';

echo "=== appraisal_cycles ===\n";
try {
    foreach (db()->fetchAll('SELECT id, name, start_date, end_date, status, financial_year_id FROM appraisal_cycles ORDER BY id') as $r) {
        echo sprintf("  %-3s %-14s %s .. %s  %-10s fy=%s\n", $r['id'], $r['name'], $r['start_date'], $r['end_date'], $r['status'], $r['financial_year_id']);
    }
} catch (\Throwable $e) { echo '  ERR: ', $e->getMessage(), "\n"; }

echo "\n=== employee_appraisals COLUMNS ===\n";
$cols = [];
try {
    foreach (db()->fetchAll('SHOW COLUMNS FROM employee_appraisals') as $c) {
        $cols[] = $c['Field'];
        echo sprintf("  %-26s %-30s null=%s def=%s\n", $c['Field'], $c['Type'], $c['Null'], var_export($c['Default'], true));
    }
} catch (\Throwable $e) { echo '  ERR: ', $e->getMessage(), "\n"; }

echo "\n=== counts ===\n";
try {
    echo '  appraisals: ', db()->fetchOne('SELECT COUNT(*) c FROM employee_appraisals')['c'], "\n";
    echo '  scores: ', db()->fetchOne('SELECT COUNT(*) c FROM appraisal_scores')['c'], "\n";
    foreach (db()->fetchAll('SELECT status, COUNT(*) c FROM employee_appraisals GROUP BY status') as $r) {
        echo "  status {$r['status']} = {$r['c']}\n";
    }
} catch (\Throwable $e) { echo '  ERR: ', $e->getMessage(), "\n"; }

echo "\n=== sample rows ===\n";
$want = array_values(array_intersect(
    ['id', 'employee_id', 'appraiser_id', 'appraisal_cycle_id', 'status'],
    $cols
));
try {
    foreach (db()->fetchAll('SELECT ' . implode(', ', $want) . ' FROM employee_appraisals ORDER BY id LIMIT 4') as $r) {
        echo '  ', json_encode($r), "\n";
    }
} catch (\Throwable $e) { echo '  ERR: ', $e->getMessage(), "\n"; }


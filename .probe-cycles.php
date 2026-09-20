<?php
require __DIR__ . '/backend/bootstrap.php';

echo 'ACTIVE FY: ', json_encode(db()->fetchAll(
    'SELECT id, year_name, start_date, end_date, is_active FROM financial_years WHERE is_active = 1'
)), PHP_EOL, PHP_EOL;

echo 'CYCLES:', PHP_EOL;
foreach (db()->fetchAll('SELECT id, name, start_date, end_date, status, financial_year_id FROM appraisal_cycles ORDER BY id') as $c) {
    printf("  id=%-3s %-12s %s .. %s  %-10s fy=%s\n", $c['id'], $c['name'], $c['start_date'], $c['end_date'], $c['status'], $c['financial_year_id']);
}

echo PHP_EOL, 'APPR STATUSES: ', json_encode(db()->fetchAll(
    'SELECT status, COUNT(*) c FROM employee_appraisals GROUP BY status'
)), PHP_EOL;

echo 'DANGLING CYCLE REFS: ', (int) db()->fetchOne(
    'SELECT COUNT(*) c FROM employee_appraisals a LEFT JOIN appraisal_cycles c ON c.id = a.appraisal_cycle_id WHERE a.appraisal_cycle_id IS NOT NULL AND c.id IS NULL'
)['c'], PHP_EOL;

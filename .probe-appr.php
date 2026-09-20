<?php
require __DIR__ . '/backend/bootstrap.php';

echo "== last 5 tracked migrations ==\n";
foreach (db()->fetchAll('SELECT migration FROM migrations ORDER BY id DESC LIMIT 5') as $r) {
    echo '  ', $r['migration'], "\n";
}

echo "== appraisal_cycles ==\n";
foreach (db()->fetchAll('SELECT id, name, start_date, end_date, status, financial_year_id FROM appraisal_cycles ORDER BY start_date') as $r) {
    printf("  %-4s %-12s %s .. %s  %-10s fy=%s\n", $r['id'], $r['name'], $r['start_date'], $r['end_date'], $r['status'], $r['financial_year_id']);
}

echo "== financial years (active) ==\n";
foreach (db()->fetchAll('SELECT id, year_name, start_date, end_date, is_active FROM financial_years WHERE is_active = 1') as $r) {
    printf("  fy=%s %s  %s .. %s active=%s\n", $r['id'], $r['year_name'], $r['start_date'], $r['end_date'], $r['is_active']);
}

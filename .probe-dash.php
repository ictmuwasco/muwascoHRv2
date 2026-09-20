<?php
require 'C:/xampp/htdocs/hrdemo/backend/bootstrap.php';
$out = [];
// Engine + auto-increment state
foreach (['appraisal_cycles', 'employee_appraisals', 'appraisal_scores', 'performance_indicators'] as $t) {
    $r = db()->fetchOne(
        "SELECT engine, auto_increment FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?",
        's', [$t]
    );
    $out[] = "$t: engine={$r['engine']} auto_increment={$r['auto_increment']}";
}
// Orphan checks (FK candidates)
$checks = [
    'employee_appraisals.appraisal_cycle_id -> appraisal_cycles' =>
        "SELECT COUNT(*) FROM employee_appraisals a LEFT JOIN appraisal_cycles c ON a.appraisal_cycle_id = c.id WHERE c.id IS NULL",
    'employee_appraisals.employee_id -> employees' =>
        "SELECT COUNT(*) FROM employee_appraisals a LEFT JOIN employees e ON a.employee_id = e.id WHERE e.id IS NULL",
    'employee_appraisals.appraiser_id -> employees' =>
        "SELECT COUNT(*) FROM employee_appraisals a LEFT JOIN employees e ON a.appraiser_id = e.id WHERE e.id IS NULL",
    'appraisal_scores.employee_appraisal_id -> employee_appraisals' =>
        "SELECT COUNT(*) FROM appraisal_scores s LEFT JOIN employee_appraisals a ON s.employee_appraisal_id = a.id WHERE a.id IS NULL",
    'appraisal_scores.performance_indicator_id -> performance_indicators' =>
        "SELECT COUNT(*) FROM appraisal_scores s LEFT JOIN performance_indicators p ON s.performance_indicator_id = p.id WHERE p.id IS NULL",
];
foreach ($checks as $label => $sql) {
    $out[] = "orphans $label: " . db()->fetchValue($sql);
}
// Existing FKs on these tables (avoid duplicates)
foreach (['employee_appraisals', 'appraisal_scores', 'appraisal_cycles', 'workplan_objective_cycles'] as $t) {
    $fks = db()->fetchAll(
        "SELECT constraint_name, column_name, referenced_table_name FROM information_schema.key_column_usage
         WHERE table_schema = DATABASE() AND table_name = ? AND referenced_table_name IS NOT NULL",
        's', [$t]
    );
    $out[] = "FKs on $t: " . ($fks ? implode('; ', array_map(fn ($f) => "{$f['constraint_name']}({$f['column_name']})->{$f['referenced_table_name']}", $fks)) : 'none');
}
echo implode("\n", $out), "\n";
require 'C:/xampp/htdocs/hrdemo/backend/bootstrap.php';
$out = [];
$out[] = '-- financial_years --';
foreach (db()->fetchAll('SELECT id, year_name, start_date, end_date, is_active FROM financial_years ORDER BY start_date') as $r) {
    $out[] = json_encode($r);
}
$out[] = '-- employee_appraisals: cycle x status --';
foreach (db()->fetchAll('SELECT appraisal_cycle_id, status, COUNT(*) c FROM employee_appraisals GROUP BY appraisal_cycle_id, status ORDER BY appraisal_cycle_id, status') as $r) {
    $out[] = json_encode($r);
}
$out[] = '-- employee_appraisals: date ranges --';
$out[] = json_encode(db()->fetchOne('SELECT MIN(created_at) mn, MAX(created_at) mx FROM employee_appraisals'));
$out[] = '-- sample rows --';
foreach (db()->fetchAll('SELECT id, employee_id, employee_department_id, appraiser_id, appraisal_cycle_id, status, created_at FROM employee_appraisals ORDER BY id LIMIT 5') as $r) {
    $out[] = json_encode($r);
}
$out[] = '-- scores date range --';
$out[] = json_encode(db()->fetchOne('SELECT MIN(created_at) mn, MAX(created_at) mx, COUNT(*) c FROM appraisal_scores'));
echo implode("\n", $out), "\n";

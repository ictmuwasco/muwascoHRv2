<?php
declare(strict_types=1);
require __DIR__ . '/backend/bootstrap.php';

echo "=== appraisal_cycles (with FY) ===\n";
foreach (db()->fetchAll(
    'SELECT c.id, c.name, c.start_date, c.end_date, c.status, c.financial_year_id, fy.year_name,
            (SELECT COUNT(*) FROM employee_appraisals a WHERE a.appraisal_cycle_id = c.id) AS appraisals
       FROM appraisal_cycles c
       LEFT JOIN financial_years fy ON c.financial_year_id = fy.id
      ORDER BY c.start_date'
) as $r) {
    printf("#%-3d %-12s %s .. %s  %-9s fy=%-3s %-10s appraisals=%d\n",
        $r['id'], $r['name'], $r['start_date'], $r['end_date'], $r['status'],
        $r['financial_year_id'], (string) $r['year_name'], $r['appraisals']);
}

echo "\n=== active financial year ===\n";
echo json_encode(db()->fetchOne('SELECT id, year_name, start_date, end_date, is_active FROM financial_years WHERE is_active = 1')), PHP_EOL;

echo "\n=== cycles usable for NEW appraisals (active FY, not completed) ===\n";
$usable = db()->fetchAll(
    "SELECT c.id, c.name, c.status, c.financial_year_id
       FROM appraisal_cycles c
      WHERE c.financial_year_id = (SELECT id FROM financial_years WHERE is_active = 1 ORDER BY start_date DESC LIMIT 1)
        AND c.status <> 'completed' ORDER BY c.start_date"
);
echo json_encode($usable), PHP_EOL;

echo "\n=== employee_appraisals by status ===\n";
echo json_encode(db()->fetchAll('SELECT status, COUNT(*) c FROM employee_appraisals GROUP BY status')), PHP_EOL;

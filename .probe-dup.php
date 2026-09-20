<?php
/**
 * Duplicate-pair + workflow-shape probe, run BEFORE adding a UNIQUE
 * (employee_id, appraisal_cycle_id) index in migration 086: the index is only
 * safe if legacy data has no duplicate pairs.
 */
require __DIR__ . '/backend/bootstrap.php';

echo '--- duplicate (employee_id, appraisal_cycle_id) pairs ---', PHP_EOL;
$dupes = db()->fetchAll(
    'SELECT employee_id, appraisal_cycle_id, COUNT(*) AS c
     FROM employee_appraisals
     GROUP BY employee_id, appraisal_cycle_id
     HAVING c > 1'
);
echo '  count = ', count($dupes), PHP_EOL;
foreach ($dupes as $d) {
    echo '  ', json_encode($d), PHP_EOL;
}

echo '--- sample rows (id, employee, cycle name, status, submitted_at) ---', PHP_EOL;
$rows = db()->fetchAll(
    "SELECT a.id, a.employee_id, ac.name AS cycle_name, a.status, a.submitted_at,
            a.appraiser_id, LEFT(COALESCE(a.supervisors_comment,''), 28) AS sup_comment
     FROM employee_appraisals a
     LEFT JOIN appraisal_cycles ac ON ac.id = a.appraisal_cycle_id
     ORDER BY a.id ASC LIMIT 12"
);
foreach ($rows as $r) {
    echo '  ', json_encode($r), PHP_EOL;
}

echo '--- score coverage per appraisal (min/max/count) ---', PHP_EOL;
$cov = db()->fetchAll(
    'SELECT a.id, a.status, COUNT(s.id) AS score_rows, ROUND(AVG(s.score),2) AS avg_score
     FROM employee_appraisals a
     LEFT JOIN appraisal_scores s ON s.employee_appraisal_id = a.id
     GROUP BY a.id, a.status
     ORDER BY a.id LIMIT 8'
);
foreach ($cov as $r) {
    echo '  ', json_encode($r), PHP_EOL;
}

<?php
declare(strict_types=1);

require __DIR__ . '/backend/bootstrap.php';

$q = static function (string $sql): array {
    try {
        return db()->fetchAll($sql);
    } catch (\Throwable $e) {
        return [['ERROR' => $e->getMessage()]];
    }
};

echo '=== appraisal_cycles ===', PHP_EOL;
foreach ($q('SELECT id, name, status, financial_year_id, start_date, end_date FROM appraisal_cycles ORDER BY id') as $r) {
    echo json_encode($r), PHP_EOL;
}

echo '=== financial_years (active) ===', PHP_EOL;
foreach ($q('SELECT id, year_name, start_date, end_date, is_active FROM financial_years WHERE is_active = 1') as $r) {
    echo json_encode($r), PHP_EOL;
}

echo '=== financial_years (recent 6) ===', PHP_EOL;
foreach ($q('SELECT id, year_name, start_date, end_date, is_active FROM financial_years ORDER BY start_date DESC LIMIT 6') as $r) {
    echo json_encode($r), PHP_EOL;
}

echo '=== employee_appraisals by status ===', PHP_EOL;
foreach ($q('SELECT status, COUNT(*) AS c FROM employee_appraisals GROUP BY status') as $r) {
    echo json_encode($r), PHP_EOL;
}

echo '=== appraisal_scores count ===', PHP_EOL;
echo json_encode($q('SELECT COUNT(*) AS c FROM appraisal_scores')), PHP_EOL;

echo '=== appraisal_cycles schema ===', PHP_EOL;
foreach (db()->fetchAll('SHOW COLUMNS FROM appraisal_cycles') as $c) {
    echo str_pad((string) $c['Field'], 22), ' | ', str_pad((string) $c['Type'], 26),
        ' | null=', $c['Null'], ' | def=', var_export($c['Default'], true), PHP_EOL;
}

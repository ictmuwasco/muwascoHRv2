<?php
require 'backend/bootstrap.php';

echo "=== employee_appraisals columns ===", PHP_EOL;
foreach (db()->fetchAll('SHOW COLUMNS FROM employee_appraisals') as $c) {
    printf("%-28s %-34s null=%-4s def=%s\n", $c['Field'], $c['Type'], $c['Null'], var_export($c['Default'], true));
}

echo PHP_EOL, "=== OrgScope public statics ===", PHP_EOL;
$rc = new ReflectionClass(App\Helpers\OrgScope::class);
foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC) as $m) {
    $ps = array_map(fn($p) => ($p->getType() ? $p->getType() . ' ' : '') . '$' . $p->getName(), $m->getParameters());
    echo '  ', $m->getName(), '(', implode(', ', $ps), ')', PHP_EOL;
}

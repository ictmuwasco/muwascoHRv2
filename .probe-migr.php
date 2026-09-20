<?php
/**
 * Migration-ledger probe: confirms which of the recent migrations the runner
 * actually recorded, so a new 086 migration is known to be pending.
 */
require __DIR__ . '/backend/bootstrap.php';

foreach (['migrations', 'migration_log', 'schema_migrations'] as $t) {
    try {
        $rows = db()->fetchAll("SELECT * FROM `$t` ORDER BY 1 DESC LIMIT 8");
        echo "=== $t ===", PHP_EOL;
        foreach ($rows as $r) {
            echo '  ', json_encode($r), PHP_EOL;
        }
    } catch (\Throwable $e) {
        echo "=== $t === (absent: ", $e->getMessage(), ')', PHP_EOL;
    }
}

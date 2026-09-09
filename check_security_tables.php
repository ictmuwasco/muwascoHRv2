<?php
// Temporary diagnostic: verify security operations tables exist.
require __DIR__ . '/backend/bootstrap.php';

$tables = db()->fetchAll("SHOW TABLES LIKE 'security%'");
if (empty($tables)) {
    echo "NO SECURITY TABLES FOUND\n";
} else {
    foreach ($tables as $t) { echo array_values($t)[0] . "\n"; }
}
echo "---\n";
foreach (['security_events', 'security_incidents', 'security_incident_events'] as $tbl) {
    try {
        $count = db()->fetchValue("SELECT COUNT(*) FROM {$tbl}");
        echo "{$tbl}: OK ({$count} rows)\n";
    } catch (Throwable $e) {
        echo "{$tbl}: MISSING/BROKEN - " . $e->getMessage() . "\n";
    }
}

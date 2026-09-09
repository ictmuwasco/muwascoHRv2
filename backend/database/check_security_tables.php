<?php
try {
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=hrdemo;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach (['security_events', 'security_incidents', 'security_incident_events'] as $t) {
        try {
            $c = $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
            echo "{$t}: {$c}" . PHP_EOL;
        } catch (Throwable $e) {
            echo "{$t}: MISSING (" . $e->getMessage() . ")" . PHP_EOL;
        }
    }
    $r = $pdo->query("SELECT COUNT(*) FROM permissions WHERE module_key = 'security'")->fetchColumn();
    echo "security permissions rows: {$r}" . PHP_EOL;
    foreach ($pdo->query("SELECT module_key, action_key FROM permissions WHERE module_key = 'security'") as $row) {
        echo "  - {$row['module_key']}:{$row['action_key']}" . PHP_EOL;
    }
} catch (Throwable $e) {
    echo 'DB ERROR: ' . $e->getMessage() . PHP_EOL;
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Helpers\Database;

/**
 * Applies migration 043_security_operations.sql (idempotent):
 *   - Creates the `security_events` table (centralized security telemetry)
 *   - Creates the `security_incidents` table (correlated incidents)
 *   - Creates the `security_incident_events` pivot table
 *
 * Usage: php backend/database/run_migration_043.php
 */

try {
    $db   = Database::getInstance();
    $conn = $db->getConnection();

    $sql = file_get_contents(__DIR__ . '/migrations/043_security_operations.sql');

    if ($conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
        if ($conn->errno) {
            echo 'Error executing migration 043: ' . $conn->error . "\n";
            exit(1);
        }
        echo "043_security_operations.sql executed successfully\n";
    } else {
        echo 'Error executing migration 043: ' . $conn->error . "\n";
        exit(1);
    }

    $checks = [
        'security_events table exists (expect 1)' =>
            "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'security_events'",
        'security_incidents table exists (expect 1)' =>
            "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'security_incidents'",
        'security_incident_events table exists (expect 1)' =>
            "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'security_incident_events'",
    ];

    $failed = false;
    foreach ($checks as $label => $query) {
        $result = $conn->query($query);
        $count = $result ? (int) ($result->fetch_assoc()['c'] ?? 0) : -1;
        echo str_pad($label, 60) . " => {$count}\n";
        if ($count <= 0) {
            $failed = true;
        }
    }

    echo $failed
        ? "Migration 043 completed WITH WARNINGS.\n"
        : "Migration 043 completed successfully.\n";
    exit($failed ? 1 : 0);
} catch (Throwable $e) {
    echo 'Migration 043 failed: ' . $e->getMessage() . "\n";
    exit(1);
}

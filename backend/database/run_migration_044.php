<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Helpers\Database;

/**
 * Applies migration 044_vulnerabilities.sql (idempotent):
 *   - Creates the `vulnerabilities` table
 *   - Creates the `vulnerability_events` / `vulnerability_incidents` pivots
 *   - Creates the `vulnerability_timeline` table
 *   - Adds nullable `vulnerability_id` correlation columns to
 *     `security_events` and `security_incidents` (guarded, re-runnable)
 *
 * Usage: php backend/database/run_migration_044.php
 */

try {
    $db   = Database::getInstance();
    $conn = $db->getConnection();

    $sql = file_get_contents(__DIR__ . '/migrations/044_vulnerabilities.sql');

    if ($conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
        if ($conn->errno) {
            echo 'Error executing migration 044: ' . $conn->error . "\n";
            exit(1);
        }
        echo "044_vulnerabilities.sql executed successfully\n";
    } else {
        echo 'Error executing migration 044: ' . $conn->error . "\n";
        exit(1);
    }

    $checks = [
        'vulnerabilities table exists (expect 1)' =>
            "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'vulnerabilities'",
        'vulnerability_events pivot exists (expect 1)' =>
            "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'vulnerability_events'",
        'vulnerability_timeline table exists (expect 1)' =>
            "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'vulnerability_timeline'",
        'security_events has vulnerability_id (expect 1)' =>
            "SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'security_events' AND COLUMN_NAME = 'vulnerability_id'",
        'security_incidents has vulnerability_id (expect 1)' =>
            "SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'security_incidents' AND COLUMN_NAME = 'vulnerability_id'",
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
        ? "Migration 044 completed WITH WARNINGS.\n"
        : "Migration 044 completed successfully.\n";
    exit($failed ? 1 : 0);
} catch (Throwable $e) {
    echo 'Migration 044 failed: ' . $e->getMessage() . "\n";
    exit(1);
}

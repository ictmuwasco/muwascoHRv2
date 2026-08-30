<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Helpers\Database;

/**
 * Applies migration 031_error_tracking.sql (idempotent):
 *   - error_groups / application_errors / error_group_users / performance_events
 *   - guarded audit_logs.request_id ALTER (skips when already present)
 *   - system_errors RBAC seed rows
 *
 * Usage: php backend/database/run_migration_031.php
 */

try {
    $db   = Database::getInstance();
    $conn = $db->getConnection();

    // Already applied? (CREATE TABLE IF NOT EXISTS makes re-runs safe anyway.)
    $exists = $conn->query("SHOW TABLES LIKE 'error_groups'");
    if ($exists && $exists->num_rows > 0) {
        echo "Migration 031 already applied - error_groups table present.\n";
        exit(0);
    }

    $sql = file_get_contents(__DIR__ . '/migrations/031_error_tracking.sql');

    if ($conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());

        echo "Migration 031_error_tracking.sql executed successfully\n";
    } else {
        echo "Error executing migration 031: " . $conn->error . "\n";
        exit(1);
    }

    // Verify all four tables exist.
    foreach (['error_groups', 'application_errors', 'error_group_users', 'performance_events'] as $table) {
        $result = $conn->query("SHOW TABLES LIKE '{$table}'");
        if ($result && $result->num_rows > 0) {
            echo "✓ {$table} created successfully\n";
        } else {
            echo "✗ {$table} not found\n";
            exit(1);
        }
    }

    // Verify audit correlation column.
    $col = $conn->query("SHOW COLUMNS FROM audit_logs LIKE 'request_id'");
    echo ($col && $col->num_rows > 0)
        ? "✓ audit_logs.request_id column present\n"
        : "✗ audit_logs.request_id missing\n";

    // Verify RBAC seeds landed.
    $seeds = $conn->query("SELECT COUNT(*) AS c FROM role_permissions WHERE module = 'system_errors'");
    $row   = $seeds ? $seeds->fetch_assoc() : null;
    echo '✓ system_errors permission rows: ' . ($row['c'] ?? 0) . "\n";

    echo "\n✓ Migration 031 completed successfully!\n";
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}

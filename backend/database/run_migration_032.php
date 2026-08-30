<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Helpers\Database;

/**
 * Applies migration 032 - Leave report performance indexes.
 * Existence-checked; safe to re-run.
 */
try {
    $conn = Database::getInstance()->getConnection();

    $sql = file_get_contents(__DIR__ . '/migrations/032_leave_report_indexes.sql');
    if ($sql === false) {
        echo "✗ Could not read migration file\n";
        exit(1);
    }

    if ($conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());

        if ($conn->error) {
            echo "✗ Multi-query error: " . $conn->error . "\n";
            exit(1);
        }
        echo "✓ Migration 032_leave_report_indexes.sql executed successfully\n";
    } else {
        echo "✗ Error executing migration 032: " . $conn->error . "\n";
        exit(1);
    }

    // Verify indexes exist.
    $result = $conn->query("SHOW INDEX FROM leave_applications WHERE Key_name IN ('idx_leave_status_dates','idx_leave_emp_date')");
    $found = $result ? $result->num_rows : 0;
    echo $found >= 2
        ? "✓ Leave report index(es) present on leave_applications\n"
        : "⚠ Some indexes not found - they may already exist under different names.\n";

    echo "✓ Done.\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
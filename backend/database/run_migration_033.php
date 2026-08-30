<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Helpers\Database;

/**
 * Applies migration 033 - Attendance report performance indexes.
 * Existence-checked; safe to re-run.
 */
try {
    $conn = Database::getInstance()->getConnection();

    $sql = file_get_contents(__DIR__ . '/migrations/033_attendance_report_indexes.sql');
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
        echo "✓ Migration 033_attendance_report_indexes.sql executed successfully\n";
    } else {
        echo "✗ Error executing migration 033: " . $conn->error . "\n";
        exit(1);
    }

    // Verify the index exists.
    $result = $conn->query("SHOW INDEX FROM attendance WHERE Key_name = 'idx_attendance_date_emp'");
    $found = $result ? $result->num_rows : 0;
    echo $found > 0
        ? "✓ Attendance report index present on attendance (attendance_date, employee_id)\n"
        : "⚠ Index not found - it may already exist under a different name.\n";

    echo "✓ Done.\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

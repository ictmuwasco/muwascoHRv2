<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Helpers\Database;

/**
 * Applies migration 034 - Meeting minutes schema + permissions.
 * Fully idempotent; safe to re-run.
 */
try {
    $conn = Database::getInstance()->getConnection();

    $sql = file_get_contents(__DIR__ . '/migrations/034_meeting_minutes.sql');
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
        echo "✓ Migration 034_meeting_minutes.sql executed successfully\n";
    } else {
        echo "✗ Error executing migration 034: " . $conn->error . "\n";
        exit(1);
    }

    // Verify the core table + a child table exist.
    $result = $conn->query("SHOW TABLES LIKE 'meeting_minutes'");
    $found = $result ? $result->num_rows : 0;
    echo $found > 0
        ? "✓ meeting_minutes table present\n"
        : "⚠ meeting_minutes table not found\n";

    $result = $conn->query("SHOW TABLES LIKE 'meeting_minutes_action_items'");
    $foundChildren = $result ? $result->num_rows : 0;
    echo $foundChildren > 0
        ? "✓ meeting_minutes_action_items (and siblings) present\n"
        : "⚠ Child tables not found\n";

    echo "✓ Done.\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
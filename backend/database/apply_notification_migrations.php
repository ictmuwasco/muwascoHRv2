<?php

declare(strict_types=1);

/**
 * Applies the notification-system migrations (023-025).
 * Idempotent: every statement uses IF NOT EXISTS / safe DDL.
 *
 * Run: php backend/database/apply_notification_migrations.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Helpers\Database;

$migrations = [
    '023_push_subscriptions.sql'      => 'push_subscriptions',
    '024_notification_preferences.sql'=> 'notification_preferences',
    '025_notification_logs.sql'       => 'notification_logs',
];

try {
    $conn = Database::getInstance()->getConnection();

    foreach ($migrations as $file => $table) {
        $sql = file_get_contents(__DIR__ . '/migrations/' . $file);
        if ($sql === false) {
            throw new RuntimeException("Cannot read {$file}");
        }

        if ($conn->multi_query($sql)) {
            do {
                if ($result = $conn->store_result()) {
                    $result->free();
                }
            } while ($conn->more_results() && $conn->next_result());
        } else {
            // 1050 = table already exists - treat as success (idempotent).
            if ((int) $conn->errno !== 1050) {
                throw new RuntimeException("{$file}: " . $conn->error);
            }
        }

        $check = $conn->query("SHOW TABLES LIKE '{$table}'");
        if ($check && $check->num_rows > 0) {
            echo "OK {$file} -> {$table}\n";
        } else {
            throw new RuntimeException("{$table} missing after {$file}");
        }
    }

    // Verify the dedup unique key exists exactly as designed.
    $idx = $conn->query(
        "SHOW INDEX FROM notification_logs WHERE Key_name = 'uq_notification_once'"
    );
    echo $idx && $idx->num_rows > 0
        ? "OK uq_notification_once unique key present\n"
        : "WARNING uq_notification_once missing!\n";

    echo "\nAll notification migrations applied.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

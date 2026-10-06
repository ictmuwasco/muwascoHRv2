<?php

declare(strict_types=1);

/**
 * Support commands for the migration runner.
 *
 * Loaded by backend/database/run.php. Kept separate so run.php stays focused
 * on applying migrations.
 */

/**
 * Record migrations as already applied WITHOUT executing their SQL.
 *
 * This is the deliberate escape hatch offered by the safety guard in run.php
 * when it refuses to migrate a populated database that has no ledger.
 *
 * It is deliberately conservative:
 *   - Refuses to run if the ledger already has entries, because baselining an
 *     already-tracked database hides real drift.
 *   - Refuses to baseline a database that has no tables at all, because then
 *     the correct action is simply to run the migrations.
 *   - Writes ledger rows only. No migration SQL is ever executed.
 *
 * @return int process exit code
 */
function baselineCommand(mysqli $conn, string $migrationsDir): int
{
    $schema = (string) $conn->query('SELECT DATABASE()')->fetch_row()[0];

    $tableCount = (int) ($conn->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = '" . $conn->real_escape_string($schema) . "'"
    )->fetch_row()[0] ?? 0);

    if ($tableCount === 0) {
        fwrite(STDERR, "REFUSING: database '{$schema}' is empty. Run the migrations instead:\n");
        fwrite(STDERR, "  php backend/database/run.php migrate\n");
        return 2;
    }

    $conn->query("CREATE TABLE IF NOT EXISTS migrations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        migration VARCHAR(255) NOT NULL,
        batch INT UNSIGNED NOT NULL,
        executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        duration_ms INT UNSIGNED DEFAULT 0,
        status ENUM('completed','failed','rolled_back') DEFAULT 'completed',
        error_message TEXT DEFAULT NULL,
        UNIQUE KEY uk_migration (migration)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $existing = (int) ($conn->query("SELECT COUNT(*) FROM migrations")->fetch_row()[0] ?? 0);
    if ($existing > 0) {
        fwrite(STDERR, "REFUSING: the ledger already contains {$existing} row(s).\n");
        fwrite(STDERR, "Baselining a tracked database would hide schema drift.\n");
        fwrite(STDERR, "Use: php backend/database/run.php status\n");
        return 2;
    }

    $files = migrationFiles($migrationsDir);
    $batch = (int) ($conn->query("SELECT COALESCE(MAX(batch),0)+1 FROM migrations")->fetch_row()[0] ?? 1);

    $stmt = $conn->prepare(
        "INSERT INTO migrations (migration, batch, status, error_message)
         VALUES (?, ?, 'completed', 'baselined: recorded without executing')"
    );
    if (!$stmt) {
        fwrite(STDERR, "FATAL: could not prepare ledger insert\n");
        return 1;
    }

    foreach ($files as $file) {
        $stmt->bind_param('si', $file, $batch);
        $stmt->execute();
    }
    $stmt->close();

    echo "Baselined {$schema}: recorded " . count($files)
        . " migration(s) as applied (batch #{$batch}).\n";
    echo "No migration SQL was executed. Verify the schema matches before relying on this.\n";

    return 0;
}

/**
 * Report applied vs pending migrations without changing anything.
 *
 * @return int process exit code
 */
function showStatus(mysqli $conn, string $migrationsDir): int
{
    $files = migrationFiles($migrationsDir);
    $hasLedger = $conn->query("SHOW TABLES LIKE 'migrations'")->num_rows > 0;

    $applied = [];
    if ($hasLedger) {
        $res = $conn->query("SELECT migration FROM migrations WHERE status='completed'");
        $applied = $res ? array_column($res->fetch_all(MYSQLI_ASSOC), 'migration') : [];
    }

    $pending = array_values(array_diff($files, $applied));
    $orphan  = array_values(array_diff($applied, $files));

    echo "Migrations on disk : " . count($files) . "\n";
    echo "Recorded as applied: " . count($applied) . "\n";
    echo "Pending            : " . count($pending) . "\n";

    if ($orphan) {
        echo "\nRecorded but not on disk (removed/renamed files):\n";
        foreach ($orphan as $m) {
            echo "  - {$m}\n";
        }
    }

    if ($pending) {
        echo "\nPending:\n";
        foreach ($pending as $m) {
            echo "  - {$m}\n";
        }
    }

    return ($pending || $orphan) ? 1 : 0;
}

/**
 * @return list<string> migration filenames, sorted
 */
function migrationFiles(string $dir): array
{
    $all = array_diff(scandir($dir) ?: [], ['.', '..']);
    $sql = array_values(array_filter($all, static fn ($f) => substr($f, -4) === '.sql'));
    sort($sql);

    return $sql;
}

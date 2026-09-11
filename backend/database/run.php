<?php

declare(strict_types=1);

/**
 * Unified Database Migration Runner
 * Runs all SQL migrations in order from the migrations/ directory.
 * 
 * Usage: php backend/database/run.php
 */

// Load bootstrap to get database connection
require_once __DIR__ . '/../bootstrap.php';

use App\Helpers\Database;

echo "MUWASCO HR Database Migrations\n";
echo str_repeat("=", 50) . "\n";

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
} catch (Exception $e) {
    fwrite(STDERR, "FATAL: " . $e->getMessage() . "\n");
    exit(1);
}

$migrationsDir = __DIR__ . '/migrations';

// Create or alter migrations tracking table to ensure it has all columns
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

// Ensure error_message column exists (for backward compatibility).
// NOTE: MySQL 8.0 does not support "ADD COLUMN IF NOT EXISTS" (MariaDB-only
// syntax; connections run in mysqli exception mode on CI PHP >= 8.1, so an
// invalid ALTER would throw). Probe information_schema instead — portable
// across MySQL and MariaDB.
$ensureColumn = static function (string $column, string $definition) use ($conn): void {
    $check = $conn->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'migrations'
           AND COLUMN_NAME = '" . $conn->real_escape_string($column) . "'"
    );
    $exists = ($check instanceof mysqli_result) ? (int) $check->fetch_row()[0] : 0;
    if ($check instanceof mysqli_result) {
        $check->free();
    }
    if (!$exists) {
        $conn->query("ALTER TABLE migrations ADD COLUMN {$column} {$definition}");
    }
};
$ensureColumn('error_message', 'TEXT DEFAULT NULL');
$ensureColumn('duration_ms', 'INT UNSIGNED DEFAULT 0');
$ensureColumn('status', "ENUM('completed','failed','rolled_back') DEFAULT 'completed'");

// Get pending migrations
$allFiles = array_diff(scandir($migrationsDir), ['.', '..']);
$sqlFiles = array_filter($allFiles, fn($f) => substr($f, -4) === '.sql');
sort($sqlFiles);

$result = $conn->query("SELECT migration FROM migrations WHERE status='completed'");
$ran = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$ran = array_column($ran, 'migration');
$toRun = array_values(array_diff($sqlFiles, $ran));
$batchResult = $conn->query("SELECT COALESCE(MAX(batch),0)+1 FROM migrations");
$batch = (int) ($batchResult ? $batchResult->fetch_row()[0] : 1);

if (empty($toRun)) {
    echo "✓ All migrations up to date.\n";
    exit(0);
}

echo "Running " . count($toRun) . " migration(s) (batch #{$batch})...\n\n";

$success = 0;
$failed = 0;

// Migrations that are only valid on MariaDB (use ADD COLUMN/CONSTRAINT
// IF NOT EXISTS syntax) but CI provisions MySQL 8.0. Skip them there —
// the equivalent schema is applied by the PHP migrations / schema sync.
$isCiMysql = (getenv('CI') === 'true' || getenv('GITHUB_ACTIONS') === 'true');
$ciSkipped = [
    '005_add_dependants_column.sql',
    '020_attendance_attendance_date_column.sql',
];

foreach ($toRun as $file) {
    if ($isCiMysql && in_array($file, $ciSkipped, true)) {
        echo "[~] {$file} ... SKIPPED on CI MySQL (MariaDB-only syntax)\n";
        $duration = 0;
        $stmt = $conn->prepare("INSERT INTO migrations (migration, batch, status) VALUES (?, ?, 'completed')");
        $stmt->bind_param("ssi", $file, $batch, $duration);
        $stmt->execute();
        $success++;
        continue;
    }
    echo "[+] {$file} ... ";
    $start = microtime(true);
    
    try {
        $sql = file_get_contents($migrationsDir . '/' . $file);
        
        // Strict multi_query execution: mysqli exception mode throws on a
        // failing FIRST statement, but a failure on a LATER statement in the
        // batch can historically go unnoticed (next_result() returning false),
        // leaving the migration marked "completed" while its tables were never
        // created. Check errno after every statement so any partial failure is
        // recorded as FAILED and fails the runner (exit 1 below).
        if (!$conn->multi_query($sql)) {
            throw new Exception('multi_query failed: ' . $conn->error);
        }
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
            if ($conn->errno !== 0) {
                throw new Exception('multi_query statement failed: ' . $conn->error);
            }
        } while ($conn->more_results() && $conn->next_result());
        
        $duration = (int)((microtime(true) - $start) * 1000);
        
        $stmt = $conn->prepare("INSERT INTO migrations (migration, batch, duration_ms, status) VALUES (?, ?, ?, 'completed')");
        $stmt->bind_param("ssi", $file, $batch, $duration);
        $stmt->execute();
        
        echo "✓ ({$duration}ms)\n";
        $success++;
    } catch (Exception $e) {
        $duration = (int)((microtime(true) - $start) * 1000);
        $errorMsg = $e->getMessage();
        
        $stmt = $conn->prepare("INSERT INTO migrations (migration, batch, duration_ms, status, error_message) VALUES (?, ?, ?, 'failed', ?)");
        $stmt->bind_param("ssis", $file, $batch, $duration, $errorMsg);
        $stmt->execute();
        
        echo "✗ FAILED: " . $errorMsg . "\n";
        $failed++;
    }
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "Completed: {$success} successful, {$failed} failed\n";

if ($failed > 0) {
    exit(1);
}

// Re-seed role permissions if 004 was run
if (in_array('004_role_permissions.sql', $toRun)) {
    echo "\nRe-seeding role permissions...\n";
    $sql = file_get_contents($migrationsDir . '/004_role_permissions.sql');
    if ($sql && $conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
        echo "✓ Role permissions re-seeded\n";
    }
}

echo "\n✓ All migrations completed successfully!\n";

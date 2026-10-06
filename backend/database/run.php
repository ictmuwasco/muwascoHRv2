<?php

declare(strict_types=1);

/**
 * Unified Database Migration Runner
 * Runs all SQL migrations in order from the migrations/ directory.
 *
 * Usage:
 *   php backend/database/run.php migrate    Apply pending migrations (default)
 *   php backend/database/run.php baseline   Record existing migrations as
 *                                           applied, WITHOUT executing them
 *   php backend/database/run.php status     Show applied/pending counts
 *
 * `baseline` is an explicit, irreversible acknowledgement that a pre-existing
 * database already satisfies a set of migrations. It never runs SQL from the
 * migration files; it only writes the ledger. It exists so adopting an
 * existing database is a deliberate, auditable act rather than a side effect.
 */

// Load bootstrap to get database connection
require_once __DIR__ . '/../bootstrap.php';

use App\Helpers\Database;

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
} catch (Exception $e) {
    fwrite(STDERR, "FATAL: " . $e->getMessage() . "\n");
    exit(1);
}

$migrationsDir = __DIR__ . '/migrations';

// Support commands live in baseline.php and are always loaded: `status` is a
// read-only inspection command and `baseline` is the deliberate escape hatch
// offered by the safety guard below. Both are needed before any migration runs.
require __DIR__ . '/baseline.php';

/** @var list<string> $argv */
$command = $argv[1] ?? 'migrate';

if ($command === 'baseline') {
    exit(baselineCommand($conn, $migrationsDir));
}

if ($command === 'status') {
    exit(showStatus($conn, $migrationsDir));
}

echo "MUWASCO HR Database Migrations\n";
echo str_repeat("=", 50) . "\n";

// SAFETY: refuse to migrate a database that already holds data but has no
// migration ledger.
//
// This check exists because of how the runner decides what is "pending": it
// compares migration FILENAMES against the ledger and nothing else. Against a
// database created by an earlier or different application, that ledger is
// absent or lists unrelated names, so EVERY migration looks pending and the
// runner would replay the entire history - CREATE TABLE, ALTER TABLE and
// UPDATE - against live rows.
//
// That is not hypothetical: the production database admin_hrmuwasco has 55
// tables, ~194 employees and ~17k attendance rows, and no `migrations` table
// at all. Running this script against it unmodified would have been a
// destructive act, not a migration.
//
// An empty database is always safe (there is nothing to damage), so a fresh
// install proceeds normally. Only a populated, untracked database is blocked,
// and the operator is told exactly how to proceed deliberately.
function assertMigratableDatabase(mysqli $conn): void
{
    $schema = (string) $conn->query('SELECT DATABASE()')->fetch_row()[0];

    $hasLedger = $conn->query("SHOW TABLES LIKE 'migrations'")->num_rows > 0;

    // Count user tables excluding the ledger itself.
    $countSql = "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = '" . $conn->real_escape_string((string) $schema) . "'
                   AND TABLE_NAME <> 'migrations'";
    $existingTables = (int) ($conn->query($countSql)->fetch_row()[0] ?? 0);

    if ($hasLedger || $existingTables === 0) {
        return; // Fresh install, or already tracked by this runner.
    }

    fwrite(STDERR, <<<TXT

    ═══════════════════════════════════════════════════════════════════
     BLOCKED: refusing to migrate a populated database with no ledger
    ═══════════════════════════════════════════════════════════════════
     Database : {$schema}
     Tables   : {$existingTables} (excluding `migrations`)

     This database contains tables but has no `migrations` ledger, so this
     runner cannot tell which migrations have already been applied. It would
     treat every migration as pending and replay the full history against
     existing rows.

     DO NOT delete the tables to force this through - that destroys data.

     Choose one deliberately:
       A) Adopt this database (schema already correct)
          Record the applied migrations as completed, then re-run:
            php backend/database/run.php baseline
          Only valid if the schema genuinely matches.

       B) Migrate the data into a fresh database (recommended when the
          schema differs): create an empty database, run migrations there,
          then copy the rows across with an explicit column mapping.

    ═══════════════════════════════════════════════════════════════════

    TXT);
    exit(2);
}

// Guard BEFORE the ledger is created. Creating `migrations` first would make
// the database look tracked and the check would pass vacuously.
assertMigratableDatabase($conn);

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

// Ensure error_message column exists (for backward compatibility)
$conn->query("ALTER TABLE migrations ADD COLUMN IF NOT EXISTS error_message TEXT DEFAULT NULL");
$conn->query("ALTER TABLE migrations ADD COLUMN IF NOT EXISTS status ENUM('completed','failed','rolled_back') DEFAULT 'completed'");

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

// Migrations that are only valid on MariaDB (they use
// ADD COLUMN/CONSTRAINT IF NOT EXISTS syntax, which MySQL 8.0 rejects).
// Since CI was moved to mariadb:10.4 to match production
// (see .github/workflows/deploy.yml), this skip list is now EMPTY: CI and
// production run the identical engine, so every migration is applied
// everywhere. The mechanism is retained defensively so re-introducing a
// divergent CI engine can never silently skip DDL again.
$isCiMysql = (getenv('CI') === 'true' || getenv('GITHUB_ACTIONS') === 'true');
$ciSkipped = [];

foreach ($toRun as $file) {
    if ($isCiMysql && in_array($file, $ciSkipped, true)) {
        echo "[~] {$file} ... SKIPPED on CI MySQL (MariaDB-only syntax)\n";
        $duration = 0;
        $stmt = $conn->prepare(
            "INSERT INTO migrations (migration, batch, duration_ms, status, error_message)
             VALUES (?, ?, ?, 'completed', NULL)
             ON DUPLICATE KEY UPDATE
                 batch = VALUES(batch), status = 'completed', error_message = NULL"
        );
        $stmt->bind_param("ssi", $file, $batch, $duration);
        $stmt->execute();
        $success++;
        continue;
    }
    echo "[+] {$file} ... ";
    $start = microtime(true);
    
    try {
        $sql = file_get_contents($migrationsDir . '/' . $file);

        // Guard against a comment that silently breaks the whole file.
        // mysqli::multi_query() splits on ';' with no SQL awareness, so a
        // ';' OR an odd number of apostrophes inside a '--' comment splits a
        // statement in half or opens a string literal that swallows the rest
        // of the file. Both fail with a confusing "syntax error near 'SET @s'"
        // pointing at the wrong line. Detect it here with a clear message.
        $inLineComment = false;
        $lineNo = 0;
        foreach (explode("\n", $sql) as $lineText) {
            $lineNo++;
            $code = $inLineComment ? '' : $lineText;
            $pos = strpos($code, '--');
            if ($pos !== false) {
                $inLineComment = substr($code, $pos + 2, 1) === ' ';
                if ($inLineComment) { continue; }
            }
            if ($inLineComment) { continue; }
            $commentPart = ($pos !== false) ? substr($code, 0, $pos) : $code;
            $before = $commentPart;
            $commentPart = preg_replace("/'(?:''|[^'])*'/", "''", $commentPart);
            if (substr_count($before, "'") !== substr_count($commentPart, "'")) {
                throw new RuntimeException(
                    "Unbalanced quote in a comment on line {$lineNo}. "
                    . "A '--' comment in a .sql migration must not contain an odd "
                    . "number of apostrophes (e.g. contractions) or a ';' - multi_query "
                    . "splits on those and corrupts the statement."
                );
            }
        }
        
        // multi_query() stops at the FIRST failing statement and everything
        // after it in the same file is silently discarded. The previous
        // version never inspected the result chain, so a migration that died
        // half-way was still recorded as 'completed' — that is exactly how
        // 041_ai_assistant.sql came to be marked complete while its last two
        // tables were never created (repaired by 100_repair_ai_knowledge_base).
        // Walking next_result() forces each statement's error to surface.
        $queriesOk = $conn->multi_query($sql);
        $queryError = null;
        if ($queriesOk) {
            do {
                if ($result = $conn->store_result()) {
                    $result->free();
                }
                // Throw if the server reported an error for the statement that
                // was just executed.
                if ($conn->errno !== 0) {
                    $queryError = $conn->error . ' (errno ' . $conn->errno . ')';
                    break;
                }
            } while ($conn->more_results() && $conn->next_result());
        } else {
            $queryError = $conn->error . ' (errno ' . $conn->errno . ')';
        }
        if ($queryError !== null) {
            throw new RuntimeException($queryError);
        }
        
        $duration = (int)((microtime(true) - $start) * 1000);
        
        // Upsert, not INSERT. migrations.uk_migration is UNIQUE on the file
        // name, so a plain INSERT throws on a retry: a migration recorded as
        // 'failed' can never be re-attempted because the ledger row already
        // exists. Re-running a corrected migration is a normal part of the
        // workflow, so both the success and failure paths upsert.
        $stmt = $conn->prepare(
            "INSERT INTO migrations (migration, batch, duration_ms, status, error_message)
             VALUES (?, ?, ?, 'completed', NULL)
             ON DUPLICATE KEY UPDATE
                 batch = VALUES(batch),
                 duration_ms = VALUES(duration_ms),
                 status = 'completed',
                 error_message = NULL,
                 executed_at = CURRENT_TIMESTAMP"
        );
        $stmt->bind_param("ssi", $file, $batch, $duration);
        $stmt->execute();
        
        echo "✓ ({$duration}ms)\n";
        $success++;
    } catch (Throwable $e) {
        $duration = (int)((microtime(true) - $start) * 1000);
        $errorMsg = $e->getMessage();
        
        $stmt = $conn->prepare(
            "INSERT INTO migrations (migration, batch, duration_ms, status, error_message)
             VALUES (?, ?, ?, 'failed', ?)
             ON DUPLICATE KEY UPDATE
                 batch = VALUES(batch),
                 duration_ms = VALUES(duration_ms),
                 status = 'failed',
                 error_message = VALUES(error_message),
                 executed_at = CURRENT_TIMESTAMP"
        );
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

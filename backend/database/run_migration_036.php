<?php
/**
 * run_migration_036.php
 *
 * Phase 4 Wave 1 — migration runner for 036_users_employee_id_type_fix.sql
 *
 * Reads DB_* config from .env (same as the rest of the app), applies the
 * migration idempotently, and reports success/failure.
 *
 * Usage:
 *   php backend/database/run_migration_036.php
 *
 * The SQL file has internal guards (information_schema checks with
 * prepared-statement branching), so re-running it is safe.
 */

defined('BASE_PATH') or define('BASE_PATH', dirname(__DIR__, 2));

// --- Load .env (replicate the app's Dotenv setup for parity) ---
$envFile = BASE_PATH . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val);
            // Only first occurrence wins (immutable Dotenv semantics)
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $val;
                putenv("$key=$val");
            }
        }
    }
}

// --- Resolve config ---
$host     = $_ENV['DB_HOST']     ?? '127.0.0.1';
$port     = $_ENV['DB_PORT']     ?? '3306';
$database = $_ENV['DB_DATABASE'] ?? 'muwasco';
$username = $_ENV['DB_USERNAME'] ?? 'root';
$password = $_ENV['DB_PASSWORD'] ?? '';

$dsn = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";

echo "[Phase 4 Wave 1] Running migration 036...\n";
echo "  DB: {$database} @ {$host}:{$port}\n\n";

try {
    $pdo = new PDO(
        $dsn,
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE                => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true, // required: runner mixes SELECT diagnostics with DDL
        ]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "FATAL: Could not connect to MySQL: " . $e->getMessage() . "\n");
    exit(1);
}

$sqlFile = __DIR__ . '/Migrations/036_users_employee_id_type_fix.sql';
if (!file_exists($sqlFile)) {
    fwrite(STDERR, "FATAL: Migration SQL not found: {$sqlFile}\n");
    exit(1);
}

$raw = file_get_contents($sqlFile);
$parts = preg_split('/\r?\n/', $raw);
$buffer = '';

$executed = 0;
$errors   = 0;

foreach ($parts as $line) {
    $trim = ltrim($line);

    // Skip comment-only lines
    if (str_starts_with($trim, '--') || $trim === '') {
        continue;
    }

    $buffer .= $line . "\n";

    // If the buffer now ends with a complete statement, execute it
    if (rtrim($buffer) !== '' && substr(rtrim($buffer), -1) === ';') {
        $stmt = rtrim($buffer);

        try {
            // query() returns a statement handle even for non-row-returning
            // statements when buffered query mode is on; draining every
            // rowset (including the PREPARE..EXECUTE no-op SELECT 1) leaves
            // no unbuffered results behind for the next statement.
            $st = $pdo->query($stmt);
            do {
                $st->fetchAll(PDO::FETCH_ASSOC);
                $more = $st->nextRowset();
            } while ($more);
            echo "  [OK] " . substr($stmt, 0, min(80, strlen($stmt))) . "\n";
            $executed++;
        } catch (PDOException $e) {
            fwrite(STDERR, "  [ERROR] " . $e->getMessage() . "\n");
            fwrite(STDERR, "  Statement: " . substr($stmt, 0, 200) . "...\n");
            $errors++;
            // Don't exit on first error — continue to allow partial success
            // and let the user see all failures.
        }

        $buffer = '';
    }
}

echo "\n[Phase 4 Wave 1] Migration 036 complete.\n";
echo "  Statements executed: {$executed}\n";
echo "  Errors: {$errors}\n";

if ($errors > 0) {
    exit(1);
}
echo "  Status: SUCCESS\n";
exit(0);

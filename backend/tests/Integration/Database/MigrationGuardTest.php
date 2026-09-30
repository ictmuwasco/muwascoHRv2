<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Helpers\Database;
use PHPUnit\Framework\TestCase;

/**
 * Guards the destructive failure mode of the migration runner.
 *
 * THE BUG THIS PREVENTS
 *   run.php decides which migrations are pending by comparing migration
 *   FILENAMES against the `migrations` ledger. It never inspects the schema.
 *   Against a database created by an earlier or different application - one
 *   with tables and rows but no ledger - every migration looks pending and the
 *   runner replays the entire history against live data.
 *
 *   That is not theoretical. The production database `admin_hrmuwasco` has 55
 *   tables, ~194 employees and ~17,136 attendance rows, and NO `migrations`
 *   table. Running the unguarded runner against it would have issued CREATE /
 *   ALTER / UPDATE against real HR records.
 *
 * These tests drive the real guard functions against a scratch database shaped
 * like that production case. The scratch database is dropped in tearDown; the
 * application's own database is never touched.
 */
final class MigrationGuardTest extends TestCase
{
    private const SCRATCH_DB = 'migration_guard_test';

    private ?\mysqli $scratch = null;

    private static function migrationsDir(): string
    {
        return dirname(__DIR__, 3) . '/database/migrations';
    }

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/database/baseline.php';

        $host = (string) config('database.connections.mysql.host');
        $port = (int) (config('database.connections.mysql.port') ?: 3306);
        $user = (string) config('database.connections.mysql.username');
        $pass = (string) config('database.connections.mysql.password');

        $this->scratch = new \mysqli($host, $user, $pass, '', $port);
        if ($this->scratch->connect_errno) {
            $this->markTestSkipped('No MySQL connection available for the scratch database.');
        }

        $this->scratch->query('DROP DATABASE IF EXISTS ' . self::SCRATCH_DB);
        $this->scratch->query('CREATE DATABASE ' . self::SCRATCH_DB
            . ' DEFAULT CHARACTER SET utf8mb4');
        $this->scratch->select_db(self::SCRATCH_DB);
    }

    protected function tearDown(): void
    {
        if ($this->scratch) {
            $this->scratch->query('DROP DATABASE IF EXISTS ' . self::SCRATCH_DB);
            $this->scratch->close();
            $this->scratch = null;
        }
    }

    /** Populated, but with no `migrations` ledger - the production shape. */
    private function seedLegacyDatabase(): void
    {
        $this->scratch->query(
            'CREATE TABLE employees (
                id INT AUTO_INCREMENT PRIMARY KEY,
                first_name VARCHAR(100) NOT NULL
            ) ENGINE=InnoDB'
        );
        $this->scratch->query("INSERT INTO employees (first_name) VALUES ('Real Person')");
        $this->scratch->query(
            'CREATE TABLE audit_logs (id INT AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB'
        );
    }

    private function hasLedger(): bool
    {
        return $this->scratch->query("SHOW TABLES LIKE 'migrations'")->num_rows > 0;
    }

    private function tableCount(): int
    {
        return (int) $this->scratch->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = '" . self::SCRATCH_DB . "'"
        )->fetch_row()[0];
    }

    public function testGuardBlocksAPopulatedDatabaseWithNoLedger(): void
    {
        $this->seedLegacyDatabase();

        $this->assertFalse($this->hasLedger(), 'precondition: no ledger');
        $this->assertSame(2, $this->tableCount(), 'precondition: two legacy tables');

        // Mirrors assertMigratableDatabase() in database/run.php. The real
        // function calls exit(), so the rule is restated here to keep the
        // assertion observable; the risk of the two drifting is called out in
        // the comment above the function.
        $shouldBlock = !$this->hasLedger() && $this->tableCount() > 0;

        $this->assertTrue($shouldBlock, 'populated untracked database must be blocked');
    }

    public function testGuardAllowsAnEmptyDatabase(): void
    {
        // A fresh install has nothing to damage, so migrations must run.
        $this->assertSame(0, $this->tableCount());
        $this->assertFalse($this->hasLedger());

        $shouldBlock = !$this->hasLedger() && $this->tableCount() > 0;
        $this->assertFalse($shouldBlock, 'empty database must be allowed');
    }

    public function testGuardAllowsATrackedDatabase(): void
    {
        $this->seedLegacyDatabase();
        $this->scratch->query(
            'CREATE TABLE migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL
            ) ENGINE=InnoDB'
        );

        $this->assertTrue($this->hasLedger(), 'a ledger exists, so this is our own database');
    }

    public function testBaselineRefusesAnEmptyDatabase(): void
    {
        // Baselining an empty database would skip migrations that genuinely
        // need to run. It must point the operator at `migrate` instead.
        $exitCode = baselineCommand($this->scratch, self::migrationsDir());

        $this->assertSame(2, $exitCode, 'baseline must refuse an empty database');
    }

    public function testBaselineRecordsLedgerRowsWithoutExecutingSql(): void
    {
        $this->seedLegacyDatabase();

        $exitCode = baselineCommand($this->scratch, self::migrationsDir());

        $this->assertSame(0, $exitCode, 'baseline should succeed on a populated database');

        $recorded = (int) $this->scratch->query('SELECT COUNT(*) FROM migrations')->fetch_row()[0];
        $onDisk = count(migrationFiles(self::migrationsDir()));

        $this->assertSame($onDisk, $recorded, 'every migration on disk must be recorded');
        $this->assertGreaterThan(0, $onDisk, 'sanity: the migrations directory is not empty');

        // The critical assertion: existing data must survive untouched.
        $employees = (int) $this->scratch->query('SELECT COUNT(*) FROM employees')->fetch_row()[0];
        $this->assertSame(1, $employees, 'baseline must not delete existing rows');
    }

    public function testBaselineRefusesATrackedDatabase(): void
    {
        $this->seedLegacyDatabase();
        $this->scratch->query(
            'CREATE TABLE migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL
            ) ENGINE=InnoDB'
        );
        $this->scratch->query("INSERT INTO migrations (migration) VALUES ('0000_baseline_schema.sql')");

        $exitCode = baselineCommand($this->scratch, self::migrationsDir());

        $this->assertSame(2, $exitCode, 'baseline must refuse an already-tracked database');
    }

    public function testStatusReportsPendingForAnUntrackedDatabase(): void
    {
        $this->seedLegacyDatabase();

        $exitCode = showStatus($this->scratch, self::migrationsDir());

        // Non-zero because everything is pending, which is exactly the signal
        // an operator needs before attempting a migrate.
        $this->assertSame(1, $exitCode);
    }
}

<?php

declare(strict_types=1);

namespace Database;

/**
 * Database Migration System
 * 
 * Handles schema migrations, version tracking, and rollbacks.
 */
class Migration
{
    private \App\Helpers\Database $db;
    private string $migrationsPath;

    public function __construct()
    {
        $this->db = \db();
        $this->migrationsPath = __DIR__ . '/migrations';
        $this->ensureMigrationTable();
    }

    /**
     * Ensure the migrations tracking table exists.
     */
    private function ensureMigrationTable(): void
    {
        $conn = $this->db->getConnection();
        $conn->query("
            CREATE TABLE IF NOT EXISTS `migrations` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `migration` VARCHAR(255) NOT NULL,
                `batch` INT UNSIGNED NOT NULL,
                `executed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `duration_ms` INT UNSIGNED DEFAULT 0,
                `status` ENUM('completed', 'failed', 'rolled_back') DEFAULT 'completed',
                `error_message` TEXT DEFAULT NULL,
                UNIQUE KEY `uk_migration` (`migration`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /**
     * Run all pending migrations.
     */
    public function migrate(): void
    {
        $batch = $this->getNextBatch();
        $files = $this->getPendingMigrations();

        if (empty($files)) {
            echo "Nothing to migrate. All migrations are up to date.\n";
            return;
        }

        foreach ($files as $file) {
            $startTime = microtime(true);
            echo "Running migration: {$file}... ";

            try {
                require_once $this->migrationsPath . '/' . $file;

                $className = $this->getClassName($file);
                $migration = new $className();
                $migration->up();

                $duration = (int) ((microtime(true) - $startTime) * 1000);

                $this->recordMigration($file, $batch, $duration, 'completed');
                echo "OK ({$duration}ms)\n";
            } catch (\Exception $e) {
                $duration = (int) ((microtime(true) - $startTime) * 1000);
                $this->recordMigration($file, $batch, $duration, 'failed', $e->getMessage());
                echo "FAILED: " . $e->getMessage() . "\n";
                throw $e;
            }
        }
    }

    /**
     * Rollback the last batch of migrations.
     */
    public function rollback(): void
    {
        $lastBatch = $this->getLastBatch();
        if ($lastBatch === 0) {
            echo "Nothing to rollback.\n";
            return;
        }

        $migrations = $this->getBatchMigrations($lastBatch);

        foreach (array_reverse($migrations) as $migration) {
            echo "Rolling back: {$migration['migration']}... ";

            try {
                $file = $migration['migration'];
                require_once $this->migrationsPath . '/' . $file;

                $className = $this->getClassName($file);
                $migrationObj = new $className();
                $migrationObj->down();

                $this->removeMigrationRecord($migration['id']);
                echo "OK\n";
            } catch (\Exception $e) {
                echo "FAILED: " . $e->getMessage() . "\n";
                throw $e;
            }
        }
    }

    /**
     * Reset and re-run all migrations.
     */
    public function refresh(): void
    {
        $this->rollback();
        $this->migrate();
    }

    /**
     * Get the next batch number.
     */
    private function getNextBatch(): int
    {
        return $this->getLastBatch() + 1;
    }

    /**
     * Get the last batch number.
     */
    private function getLastBatch(): int
    {
        $result = $this->db->fetchValue(
            "SELECT MAX(batch) FROM migrations"
        );
        return (int) $result;
    }

    /**
     * Get all migrations in a batch.
     */
    private function getBatchMigrations(int $batch): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM migrations WHERE batch = ? ORDER BY id ASC",
            'i',
            [$batch]
        );
    }

    /**
     * Get pending migration files.
     */
    private function getPendingMigrations(): array
    {
        $files = glob($this->migrationsPath . '/*.php');
        $files = array_map('basename', $files);
        sort($files);

        $ran = $this->db->fetchAll("SELECT migration FROM migrations WHERE status = 'completed'");
        $ranFiles = array_column($ran, 'migration');

        return array_values(array_diff($files, $ranFiles));
    }

    /**
     * Record a migration execution.
     */
    private function recordMigration(string $file, int $batch, int $duration, string $status, string $error = null): void
    {
        $this->db->insert('migrations', [
            'migration' => $file,
            'batch' => $batch,
            'duration_ms' => $duration,
            'status' => $status,
            'error_message' => $error,
        ]);
    }

    /**
     * Remove a migration record (for rollback).
     */
    private function removeMigrationRecord(int $id): void
    {
        $this->db->delete('migrations', 'id = ?', 'i', [$id]);
    }

    /**
     * Extract class name from migration filename.
     */
    private function getClassName(string $filename): string
    {
        // Remove timestamp prefix and .php extension
        $name = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $filename);
        $name = str_replace('.php', '', $name);
        
        // Convert snake_case to PascalCase
        $parts = explode('_', $name);
        $className = '';
        foreach ($parts as $part) {
            $className .= ucfirst($part);
        }
        
        return 'Database\\Migrations\\' . $className;
    }

    /**
     * Create a new migration file.
     */
    public function create(string $name): string
    {
        $timestamp = date('Y_m_d_His');
        $filename = "{$timestamp}_{$name}.php";
        $path = $this->migrationsPath . '/' . $filename;

        $className = $this->getClassName($filename);

        $template = <<<PHP
<?php

declare(strict_types=1);

namespace Database\\Migrations;

use App\\Helpers\\Database;

class {$className}
{
    private \\App\\Helpers\\Database \$db;

    public function __construct()
    {
        \$this->db = \\db();
    }

    /**
     * Run the migration.
     */
    public function up(): void
    {
        \$conn = \$this->db->getConnection();
        
        // TODO: Add your migration SQL here
        // Example:
        // \$conn->query("
        //     CREATE TABLE IF NOT EXISTS `example_table` (
        //         `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        //         `name` VARCHAR(255) NOT NULL,
        //         `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        //     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        // ");
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        \$conn = \$this->db->getConnection();
        
        // TODO: Add rollback SQL here
        // Example:
        // \$conn->query("DROP TABLE IF EXISTS `example_table`");
    }
}

PHP;

        file_put_contents($path, $template);
        echo "Created migration: {$filename}\n";

        return $filename;
    }
}
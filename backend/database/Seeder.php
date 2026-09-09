<?php

declare(strict_types=1);

namespace Database;

/**
 * Base Seeder Class
 * 
 * All seeders should extend this class and implement the run() method.
 * 
 * Usage:
 *   class UserSeeder extends Seeder {
 *       public function run(): void {
 *           $this->seed('users', [
 *               ['name' => 'Admin', 'email' => 'admin@example.com'],
 *               ['name' => 'User', 'email' => 'user@example.com'],
 *           ]);
 *       }
 *   }
 */
abstract class Seeder
{
    /**
     * Run the seeder
     */
    abstract public function run(): void;

    /**
     * Seed a table with data
     * 
     * @param string $table Table name
     * @param array $rows Array of row data
     * @return int Number of rows inserted
     */
    protected function seed(string $table, array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $connection = DatabaseConnection::getInstance()->getConnection();
        $inserted = 0;

        foreach ($rows as $row) {
            $columns = implode(', ', array_keys($row));
            $placeholders = implode(', ', array_fill(0, count($row), '?'));
            $values = array_values($row);

            try {
                $connection->executeStatement(
                    "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})",
                    $values
                );
                $inserted++;
            } catch (\Exception $e) {
                error_log("Seeder::seed failed for table '{$table}': " . $e->getMessage());
            }
        }

        return $inserted;
    }

    /**
     * Seed from a callback (for generated data)
     * 
     * @param string $table Table name
     * @param int $count Number of rows to generate
     * @param callable $callback Callback that returns row data
     * @return int Number of rows inserted
     */
    protected function seedGenerated(string $table, int $count, callable $callback): int
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = $callback($i);
        }
        return $this->seed($table, $rows);
    }

    /**
     * Truncate a table before seeding
     * 
     * @param string $table Table name
     * @return bool True on success
     */
    protected function truncate(string $table): bool
    {
        return SchemaBuilder::truncate($table);
    }

    /**
     * Check if a table has data
     * 
     * @param string $table Table name
     * @return int Row count
     */
    protected function count(string $table): int
    {
        $connection = DatabaseConnection::getInstance()->getConnection();
        $result = $connection->fetchOne("SELECT COUNT(*) FROM {$table}");
        return (int) $result;
    }

    /**
     * Check if a table is empty
     * 
     * @param string $table Table name
     * @return bool True if empty
     */
    protected function isEmpty(string $table): bool
    {
        return $this->count($table) === 0;
    }

    /**
     * Run another seeder
     * 
     * @param Seeder $seeder Seeder instance to run
     */
    protected function call(Seeder $seeder): void
    {
        $seeder->run();
    }

    /**
     * Run multiple seeders
     * 
     * @param array $seeders Array of Seeder instances
     */
    protected function callMany(array $seeders): void
    {
        foreach ($seeders as $seeder) {
            $this->call($seeder);
        }
    }
}

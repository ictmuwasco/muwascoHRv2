<?php

declare(strict_types=1);

namespace Database;

use App\Database\DatabaseConnection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

/**
 * Schema Builder
 * 
 * Fluent interface for creating and modifying database tables.
 * Wraps Doctrine DBAL Schema API for easier use.
 */
class SchemaBuilder
{
    /**
     * Create a new table
     */
    public static function create(string $tableName, callable $callback): bool
    {
        $connection = DatabaseConnection::getInstance()->getConnection();
        $schema = $connection->createSchemaManager()->introspectSchema();
        
        $table = $schema->createTable($tableName);
        $callback($table);
        
        $queries = $schema->toSql($connection->getDatabasePlatform());
        
        foreach ($queries as $query) {
            try {
                $connection->executeStatement($query);
            } catch (\Exception $e) {
                error_log("SchemaBuilder::create failed for table '{$tableName}': " . $e->getMessage());
                return false;
            }
        }
        
        return true;
    }

    /**
     * Modify an existing table
     */
    public static function table(string $tableName, callable $callback): bool
    {
        $connection = DatabaseConnection::getInstance()->getConnection();
        $schemaManager = $connection->createSchemaManager();
        $fromSchema = $schemaManager->introspectSchema();
        
        $toSchema = clone $fromSchema;
        
        if (!$toSchema->hasTable($tableName)) {
            error_log("SchemaBuilder::table - Table '{$tableName}' does not exist");
            return false;
        }
        
        $table = $toSchema->getTable($tableName);
        $callback($table);
        
        $queries = $fromSchema->getMigrateToSql($toSchema, $connection->getDatabasePlatform());
        
        foreach ($queries as $query) {
            try {
                $connection->executeStatement($query);
            } catch (\Exception $e) {
                error_log("SchemaBuilder::table failed for table '{$tableName}': " . $e->getMessage());
                return false;
            }
        }
        
        return true;
    }

    /**
     * Drop a table
     */
    public static function drop(string $tableName): bool
    {
        $connection = DatabaseConnection::getInstance()->getConnection();
        
        try {
            $connection->executeStatement("DROP TABLE IF EXISTS {$tableName}");
            return true;
        } catch (\Exception $e) {
            error_log("SchemaBuilder::drop failed for table '{$tableName}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a table exists
     */
    public static function hasTable(string $tableName): bool
    {
        $connection = DatabaseConnection::getInstance()->getConnection();
        $schemaManager = $connection->createSchemaManager();
        
        return $schemaManager->tablesExist([$tableName]);
    }

    /**
     * Check if a column exists in a table
     */
    public static function hasColumn(string $tableName, string $columnName): bool
    {
        if (!self::hasTable($tableName)) {
            return false;
        }
        
        $connection = DatabaseConnection::getInstance()->getConnection();
        $schemaManager = $connection->createSchemaManager();
        $columns = $schemaManager->listTableColumns($tableName);
        
        return isset($columns[$columnName]);
    }

    /**
     * Add an index to a table
     */
    public static function addIndex(string $tableName, string $indexName, array $columns, bool $unique = false): bool
    {
        $connection = DatabaseConnection::getInstance()->getConnection();
        
        $indexType = $unique ? 'UNIQUE INDEX' : 'INDEX';
        $columnsList = implode(', ', $columns);
        
        try {
            $connection->executeStatement(
                "ALTER TABLE {$tableName} ADD {$indexType} {$indexName} ({$columnsList})"
            );
            return true;
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already exists') !== false) {
                return true;
            }
            error_log("SchemaBuilder::addIndex failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Drop an index from a table
     */
    public static function dropIndex(string $tableName, string $indexName): bool
    {
        $connection = DatabaseConnection::getInstance()->getConnection();
        
        try {
            $connection->executeStatement("ALTER TABLE {$tableName} DROP INDEX {$indexName}");
            return true;
        } catch (\Exception $e) {
            error_log("SchemaBuilder::dropIndex failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Add a foreign key constraint
     */
    public static function addForeignKey(
        string $tableName,
        string $constraintName,
        array $localColumns,
        string $foreignTable,
        array $foreignColumns,
        string $onDelete = 'RESTRICT',
        string $onUpdate = 'RESTRICT'
    ): bool {
        $connection = DatabaseConnection::getInstance()->getConnection();
        
        $localColumnsList = implode(', ', $localColumns);
        $foreignColumnsList = implode(', ', $foreignColumns);
        
        try {
            $connection->executeStatement(
                "ALTER TABLE {$tableName} ADD CONSTRAINT {$constraintName} 
                FOREIGN KEY ({$localColumnsList}) REFERENCES {$foreignTable} ({$foreignColumnsList})
                ON DELETE {$onDelete} ON UPDATE {$onUpdate}"
            );
            return true;
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already exists') !== false) {
                return true;
            }
            error_log("SchemaBuilder::addForeignKey failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Drop a foreign key constraint
     */
    public static function dropForeignKey(string $tableName, string $constraintName): bool
    {
        $connection = DatabaseConnection::getInstance()->getConnection();
        
        try {
            $connection->executeStatement("ALTER TABLE {$tableName} DROP FOREIGN KEY {$constraintName}");
            return true;
        } catch (\Exception $e) {
            error_log("SchemaBuilder::dropForeignKey failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Rename a table
     */
    public static function rename(string $oldName, string $newName): bool
    {
        $connection = DatabaseConnection::getInstance()->getConnection();
        
        try {
            $connection->executeStatement("RENAME TABLE {$oldName} TO {$newName}");
            return true;
        } catch (\Exception $e) {
            error_log("SchemaBuilder::rename failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Truncate a table
     */
    public static function truncate(string $tableName): bool
    {
        $connection = DatabaseConnection::getInstance()->getConnection();
        
        try {
            $connection->executeStatement("TRUNCATE TABLE {$tableName}");
            return true;
        } catch (\Exception $e) {
            error_log("SchemaBuilder::truncate failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all table names
     */
    public static function getAllTables(): array
    {
        $connection = DatabaseConnection::getInstance()->getConnection();
        $schemaManager = $connection->createSchemaManager();
        
        return $schemaManager->listTableNames();
    }
}

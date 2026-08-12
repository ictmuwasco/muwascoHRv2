<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Base Model - Abstract class providing common database operations.
 * 
 * All models should extend this class for consistent data access.
 */
abstract class BaseModel
{
    protected static string $table = '';
    protected static string $primaryKey = 'id';
    protected static array $fillable = [];
    protected static array $guarded = ['id', 'created_at', 'updated_at'];
    protected static bool $timestamps = true;

    /**
     * Get the database table name.
     */
    public static function getTable(): string
    {
        return static::$table;
    }

    /**
     * Get the primary key column name.
     */
    public static function getPrimaryKey(): string
    {
        return static::$primaryKey;
    }

    /**
     * Find a record by its primary key.
     */
    public static function find(int|string $id): ?array
    {
        $db = \db();
        return $db->fetchOne(
            "SELECT * FROM " . static::$table . " WHERE " . static::$primaryKey . " = ?",
            is_int($id) ? 'i' : 's',
            [$id]
        );
    }

    /**
     * Find a record by its primary key (alias for find).
     */
    public static function findById(int|string $id): ?array
    {
        return static::find($id);
    }

    /**
     * Find records matching conditions.
     */
    public static function where(array $conditions, string $operator = 'AND'): array
    {
        return self::findWhere($conditions, $operator);
    }

    /**
     * Find records matching conditions.
     */
    public static function findWhere(array $conditions, string $operator = 'AND'): array
    {
        $db = \db();
        $where = [];
        $types = '';
        $params = [];

        foreach ($conditions as $column => $value) {
            $where[] = "{$column} = ?";
            $types .= is_int($value) ? 'i' : (is_float($value) ? 'd' : 's');
            $params[] = $value;
        }

        $sql = "SELECT * FROM " . static::$table . " WHERE " . implode(" {$operator} ", $where);
        return $db->fetchAll($sql, $types, $params);
    }

    /**
     * Get all records.
     */
    public static function all(string $orderBy = '', string $direction = 'ASC'): array
    {
        $db = \db();
        $sql = "SELECT * FROM " . static::$table;
        
        if ($orderBy) {
            $sql .= " ORDER BY {$orderBy} {$direction}";
        }
        
        return $db->fetchAll($sql);
    }

    /**
     * Create a new record.
     */
    public static function create(array $data): int
    {
        $db = \db();
        
        // Filter to only fillable/guarded columns
        $filtered = static::filterData($data);
        
        if (static::$timestamps) {
            $now = date('Y-m-d H:i:s');
            $filtered['created_at'] = $now;
            $filtered['updated_at'] = $now;
        }

        return $db->insert(static::$table, $filtered);
    }

    /**
     * Update a record.
     */
    public static function update(int|string $id, array $data): int
    {
        $db = \db();
        
        $filtered = static::filterData($data);
        
        if (static::$timestamps) {
            $filtered['updated_at'] = date('Y-m-d H:i:s');
        }

        $idType = is_int($id) ? 'i' : 's';
        return $db->update(
            static::$table,
            $filtered,
            static::$primaryKey . ' = ?',
            $idType,
            [$id]
        );
    }

    /**
     * Delete a record.
     */
    public static function delete(int|string $id): int
    {
        $db = \db();
        $idType = is_int($id) ? 'i' : 's';
        return $db->delete(
            static::$table,
            static::$primaryKey . ' = ?',
            $idType,
            [$id]
        );
    }

    /**
     * Paginate results.
     */
    public static function paginate(int $page = 1, int $perPage = 20, array $conditions = []): array
    {
        $db = \db();
        $offset = ($page - 1) * $perPage;
        
        $where = '';
        $types = '';
        $params = [];

        if (!empty($conditions)) {
            $clauses = [];
            foreach ($conditions as $column => $value) {
                $clauses[] = "{$column} = ?";
                $types .= is_int($value) ? 'i' : (is_float($value) ? 'd' : 's');
                $params[] = $value;
            }
            $where = " WHERE " . implode(' AND ', $clauses);
        }

        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM " . static::$table . $where;
        $total = $db->fetchValue($countSql, $types, $params);

        // Get paginated data
        $dataSql = "SELECT * FROM " . static::$table . $where . " LIMIT ? OFFSET ?";
        $types .= 'ii';
        $params[] = $perPage;
        $params[] = $offset;
        
        $data = $db->fetchAll($dataSql, $types, $params);

        return [
            'data' => $data,
            'total' => (int) $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * Count records.
     */
    public static function count(array $conditions = []): int
    {
        $db = \db();
        
        if (empty($conditions)) {
            return (int) $db->fetchValue("SELECT COUNT(*) FROM " . static::$table);
        }

        $where = [];
        $types = '';
        $params = [];

        foreach ($conditions as $column => $value) {
            $where[] = "{$column} = ?";
            $types .= is_int($value) ? 'i' : (is_float($value) ? 'd' : 's');
            $params[] = $value;
        }

        $sql = "SELECT COUNT(*) FROM " . static::$table . " WHERE " . implode(' AND ', $where);
        return (int) $db->fetchValue($sql, $types, $params);
    }

    /**
     * Filter data to only include fillable/guarded columns.
     */
    protected static function filterData(array $data): array
    {
        if (!empty(static::$fillable)) {
            return array_intersect_key($data, array_flip(static::$fillable));
        }

        if (!empty(static::$guarded)) {
            return array_diff_key($data, array_flip(static::$guarded));
        }

        return $data;
    }

    /**
     * Begin a database transaction.
     */
    public static function beginTransaction(): void
    {
        \db()->beginTransaction();
    }

    /**
     * Commit the current transaction.
     */
    public static function commit(): void
    {
        \db()->commit();
    }

    /**
     * Rollback the current transaction.
     */
    public static function rollback(): void
    {
        \db()->rollback();
    }
}
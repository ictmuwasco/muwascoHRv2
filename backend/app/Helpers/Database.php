<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Database Helper - Singleton MySQLi wrapper
 * 
 * Provides a unified database interface using only MySQLi.
 */
class Database
{
    private static ?Database $instance = null;
    private \mysqli $mysqli;
    private array $config;

    /**
     * Private constructor to enforce singleton pattern.
     */
    private function __construct()
    {
        $this->config = \config('database.connections.mysql', []);
        $this->connect();
    }

    /**
     * Get the singleton instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Establish MySQLi connection.
     */
    private function connect(): void
    {
        \mysqli_report(\MYSQLI_REPORT_ERROR | \MYSQLI_REPORT_STRICT);
        $host     = $this->config['host'] ?? 'localhost';
        $username = $this->config['username'] ?? '';
        $password = $this->config['password'] ?? '';
        $database = $this->config['database'] ?? 'muwasco';
        $port     = (int) ($this->config['port'] ?? 3306);

        // Use mysqli_init + real_connect to properly handle empty passwords
        $this->mysqli = \mysqli_init();
        
        if ($password === '') {
            // Don't pass password at all for users with no password
            $this->mysqli->real_connect($host, $username, null, $database, $port);
        } else {
            $this->mysqli->real_connect($host, $username, $password, $database, $port);
        }
        
        if ($this->mysqli->connect_error) {
            throw new \RuntimeException("Database connection failed: " . $this->mysqli->connect_error);
        }
        
        $this->mysqli->set_charset($this->config['charset'] ?? 'utf8mb4');
        $this->mysqli->query("SET time_zone = '+03:00'");
    }

    /**
     * Get the MySQLi connection.
     */
    public function getConnection(): \mysqli
    {
        // Check if connection is still alive, reconnect if needed
        if (!$this->mysqli->ping()) {
            $this->connect();
        }
        return $this->mysqli;
    }

    /**
     * Begin a transaction.
     */
    public function beginTransaction(): bool
    {
        return $this->mysqli->begin_transaction();
    }

    /**
     * Commit the current transaction.
     */
    public function commit(): bool
    {
        return $this->mysqli->commit();
    }

    /**
     * Rollback the current transaction.
     */
    public function rollback(): bool
    {
        return $this->mysqli->rollback();
    }

    /**
     * Execute a prepared statement with parameters.
     * 
     * @param string $sql The SQL query with ? placeholders
     * @param string $types The types string for bind_param (e.g. 'iss')
     * @param array $params The parameters to bind
     * @return \mysqli_stmt
     */
    public function query(string $sql, string $types = '', array $params = []): \mysqli_stmt
    {
        $stmt = $this->mysqli->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException("Query preparation failed: " . $this->mysqli->error);
        }
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        return $stmt;
    }

    /**
     * Fetch all rows from a query.
     */
    public function fetchAll(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->query($sql, $types, $params);
        $result = $stmt->get_result();
        $rows = $result->fetch_all(\MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * Fetch a single row.
     */
    public function fetchOne(string $sql, string $types = '', array $params = []): ?array
    {
        $stmt = $this->query($sql, $types, $params);
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Fetch a single column value.
     */
    public function fetchValue(string $sql, string $types = '', array $params = []): mixed
    {
        $stmt = $this->query($sql, $types, $params);
        $result = $stmt->get_result();
        $row = $result->fetch_array(\MYSQLI_NUM);
        $stmt->close();
        return $row[0] ?? null;
    }

    /**
     * Insert a record and return the insert ID.
     */
    public function insert(string $table, array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $types = '';
        $values = [];
        
        foreach ($data as $value) {
            if (is_int($value)) {
                $types .= 'i';
            } elseif (is_float($value)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
            $values[] = $value;
        }
        
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $stmt = $this->query($sql, $types, $values);
        $insertId = $stmt->insert_id;
        $stmt->close();
        return $insertId;
    }

    /**
     * Update records.
     */
    public function update(string $table, array $data, string $where, string $whereTypes = '', array $whereParams = []): int
    {
        $setClauses = [];
        $types = '';
        $values = [];
        
        foreach ($data as $column => $value) {
            $setClauses[] = "{$column} = ?";
            if (is_int($value)) {
                $types .= 'i';
            } elseif (is_float($value)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
            $values[] = $value;
        }
        
        $types .= $whereTypes;
        $values = array_merge($values, $whereParams);
        
        $sql = "UPDATE {$table} SET " . implode(', ', $setClauses) . " WHERE {$where}";
        $stmt = $this->query($sql, $types, $values);
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected;
    }

    /**
     * Delete records.
     */
    public function delete(string $table, string $where, string $types = '', array $params = []): int
    {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        $stmt = $this->query($sql, $types, $params);
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected;
    }

    /**
     * Get the last inserted ID.
     */
    public function lastInsertId(): int
    {
        return $this->mysqli->insert_id;
    }

    /**
     * Escape a string for safe SQL usage.
     */
    public function escape(string $value): string
    {
        return $this->mysqli->real_escape_string($value);
    }

    /**
     * Prevent cloning of the singleton instance.
     */
    private function __clone(): void {}

    /**
     * Prevent unserialization of the singleton instance.
     */
    public function __wakeup(): void
    {
        throw new \RuntimeException('Cannot unserialize singleton');
    }
}
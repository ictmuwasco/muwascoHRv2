<?php

declare(strict_types=1);

namespace App\Database;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Exception;

/**
 * Doctrine Database Connection
 * 
 * Wrapper around Doctrine DBAL providing a modern database abstraction layer.
 * Replaces the legacy mysqli wrapper with parameterized query support,
 * query builder, and easier testing capabilities.
 */
class DatabaseConnection
{
    private static ?DatabaseConnection $instance = null;
    private Connection $connection;
    private array $config;

    private function __construct()
    {
        $this->config = \config("database.connections.mysql", []);
        $this->connect();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    private function connect(): void
    {
        $connectionParams = [
            "driver"   => "pdo_mysql",
            "host"     => $this->config["host"] ?? "127.0.0.1",
            "port"     => (int) ($this->config["port"] ?? 3306),
            "dbname"   => $this->config["database"] ?? "muwasco",
            "user"     => $this->config["username"] ?? "root",
            "password" => $this->config["password"] ?? "",
            "charset"  => $this->config["charset"] ?? "utf8mb4",
        ];

        try {
            $this->connection = DriverManager::getConnection($connectionParams);
            $this->connection->executeStatement("SET time_zone = \'+03:00\'");
        } catch (Exception $e) {
            throw new \RuntimeException(
                "Database connection failed: " . $e->getMessage()
            );
        }
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function createQueryBuilder(): QueryBuilder
    {
        return $this->connection->createQueryBuilder();
    }

    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        try {
            return $this->connection->fetchAllAssociative($query, $params, $types);
        } catch (Exception $e) {
            throw new \RuntimeException("Query failed: " . $e->getMessage());
        }
    }

    public function fetchAssociative(string $query, array $params = [], array $types = []): ?array
    {
        try {
            $result = $this->connection->fetchAssociative($query, $params, $types);
            return $result !== false ? $result : null;
        } catch (Exception $e) {
            throw new \RuntimeException("Query failed: " . $e->getMessage());
        }
    }

    public function fetchOne(string $query, array $params = [], array $types = []): mixed
    {
        try {
            return $this->connection->fetchOne($query, $params, $types);
        } catch (Exception $e) {
            throw new \RuntimeException("Query failed: " . $e->getMessage());
        }
    }

    public function executeStatement(string $query, array $params = [], array $types = []): int
    {
        try {
            return $this->connection->executeStatement($query, $params, $types);
        } catch (Exception $e) {
            throw new \RuntimeException("Query failed: " . $e->getMessage());
        }
    }

    public function lastInsertId(): int
    {
        return (int) $this->connection->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->connection->beginTransaction();
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollBack(): void
    {
        $this->connection->rollBack();
    }

    private function __clone() {}

    public function __wakeup()
    {
        throw new \RuntimeException("Cannot unserialize singleton");
    }
}

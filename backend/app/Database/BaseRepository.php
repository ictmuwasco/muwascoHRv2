<?php
declare(strict_types=1);
namespace App\Database;
use App\Database\DatabaseConnection;
use App\Repositories\Contracts\RepositoryInterface;

abstract class BaseRepository implements RepositoryInterface
{
    protected DatabaseConnection $db;
    protected string $table;
    protected string $primaryKey = "id";

    public function __construct()
    {
        $this->db = DatabaseConnection::getInstance();
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetchAssociative(
            "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ?",
            [$id]
        );
    }

    public function findAll(): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT * FROM {$this->table} ORDER BY created_at DESC"
        );
    }

    public function create(array $data): int
    {
        $columns = implode(", ", array_keys($data));
        $placeholders = implode(", ", array_fill(0, count($data), "?"));
        $this->db->executeStatement(
            "INSERT INTO {$this->table} ({$columns}) VALUES ({$placeholders})",
            array_values($data)
        );
        return $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $setClauses = [];
        $values = [];
        foreach ($data as $column => $value) {
            $setClauses[] = "{$column} = ?";
            $values[] = $value;
        }
        $values[] = $id;
        $affected = $this->db->executeStatement(
            "UPDATE {$this->table} SET " . implode(", ", $setClauses) . " WHERE {$this->primaryKey} = ?",
            $values
        );
        return $affected > 0;
    }

    public function delete(int $id): bool
    {
        $affected = $this->db->executeStatement(
            "DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?",
            [$id]
        );
        return $affected > 0;
    }

    public function exists(int $id): bool
    {
        $result = $this->db->fetchOne(
            "SELECT COUNT(*) FROM {$this->table} WHERE {$this->primaryKey} = ?",
            [$id]
        );
        return (int) $result > 0;
    }

    public function count(): int
    {
        $result = $this->db->fetchOne("SELECT COUNT(*) FROM {$this->table}");
        return (int) $result;
    }

    protected function fetchAll(string $query, array $params = []): array
    {
        return $this->db->fetchAllAssociative($query, $params);
    }

    protected function fetchOne(string $query, array $params = []): mixed
    {
        return $this->db->fetchOne($query, $params);
    }

    protected function fetchRow(string $query, array $params = []): ?array
    {
        return $this->db->fetchAssociative($query, $params);
    }

    protected function execute(string $query, array $params = []): int
    {
        return $this->db->executeStatement($query, $params);
    }
}

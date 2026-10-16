<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\Contracts\OfficeRepositoryInterface;
use App\Helpers\Database;
use \InvalidArgumentException;

/**
 * Office Repository
 *
 * Handles all database operations for offices.
 * Encapsulates all SQL queries related to office management.
 */
class OfficeRepository implements OfficeRepositoryInterface
{
    private \mysqli $conn;

    /** Columns allowed for INSERT/UPDATE on the offices table (interpolated into SQL). */
    private const OFFICE_COLUMNS = [
        'id', 'name', 'address', 'phone', 'email', 'created_at', 'updated_at',
    ];

    public function __construct()
    {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT * FROM offices
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $office = $result->fetch_assoc();
        $stmt->close();

        return $office ?: null;
    }

    public function findAll(): array
    {
        $result = $this->conn->query("
            SELECT * FROM offices
            ORDER BY name
        ");

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function create(array $data): int
    {
        $this->validateWriteColumns($data, 'offices');

        $fields = array_keys($data);
        $quotedFields = array_map(fn(string $f): string => "`$f`", $fields);
        $placeholders = array_fill(0, count($fields), '?');
        $types = '';
        $values = [];

        foreach ($data as $value) {
            if ($value === null) {
                $types .= 's';
                $values[] = null;
            } elseif (is_int($value)) {
                $types .= 'i';
                $values[] = $value;
            } elseif (is_float($value)) {
                $types .= 'd';
                $values[] = $value;
            } else {
                $types .= 's';
                $values[] = $value;
            }
        }

        $sql = "INSERT INTO `offices` (" . implode(', ', $quotedFields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $insertId = (int)$this->conn->insert_id;
        $stmt->close();

        return $insertId;
    }

    public function update(int $id, array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        $this->validateWriteColumns($data, 'offices');

        $fields = array_keys($data);
        $quotedFields = array_map(fn(string $f): string => "`$f`", $fields);
        $setClause = implode(' = ?, ', $quotedFields) . ' = ?';
        $types = '';
        $values = [];

        foreach ($data as $value) {
            if ($value === null) {
                $types .= 's';
                $values[] = null;
            } elseif (is_int($value)) {
                $types .= 'i';
                $values[] = $value;
            } elseif (is_float($value)) {
                $types .= 'd';
                $values[] = $value;
            } else {
                $types .= 's';
                $values[] = $value;
            }
        }

        $values[] = $id;
        $types .= 'i';

        $sql = "UPDATE `offices` SET {$setClause} WHERE `id` = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->conn->prepare("DELETE FROM offices WHERE id = ?");
        $stmt->bind_param('i', $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    public function exists(int $id): bool
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM offices WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)$result['count'] > 0;
    }

    public function count(): int
    {
        $result = $this->conn->query("SELECT COUNT(*) as count FROM offices");
        $row = $result->fetch_assoc();

        return (int)$row['count'];
    }

    public function getAllActive(): array
    {
        $result = $this->conn->query("
            SELECT * FROM offices 
            ORDER BY name
        ");

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Validate that every column name in $data is a whitelisted, safe identifier
     * for the given table. Throws \InvalidArgumentException on any unknown key.
     */
    private function validateWriteColumns(array $data, string $table): void
    {
        $allowed = match ($table) {
            'offices' => self::OFFICE_COLUMNS,
            default => throw new \InvalidArgumentException("Unknown table '$table' for write validation."),
        };

        foreach (array_keys($data) as $field) {
            if (!is_string($field) || !in_array($field, $allowed, true)) {
                throw new \InvalidArgumentException("Invalid column '$field' for table '$table'.");
            }
        }
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) as count FROM offices WHERE name = ?";
        $params = [$name];
        $types = 's';

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
            $types .= 'i';
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)$result['count'] > 0;
    }
}
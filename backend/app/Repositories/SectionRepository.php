<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\Contracts\SectionRepositoryInterface;
use App\Helpers\Database;
use \InvalidArgumentException;

/**
 * Section Repository
 *
 * Handles all database operations for sections and subsections.
 * Encapsulates all SQL queries related to organizational sections.
 */
class SectionRepository implements SectionRepositoryInterface
{
    private \mysqli $conn;

    /** Columns allowed for INSERT/UPDATE on the sections table (interpolated into SQL). */
    private const SECTION_COLUMNS = [
        'id', 'name', 'department_id', 'created_at', 'updated_at',
    ];

    /** Columns allowed for INSERT/UPDATE on the subsections table (interpolated into SQL). */
    private const SUBSECTION_COLUMNS = [
        'id', 'name', 'section_id', 'created_at', 'updated_at',
    ];

    public function __construct()
    {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT s.*, d.name as department_name
            FROM sections s
            LEFT JOIN departments d ON s.department_id = d.id
            WHERE s.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $section = $result->fetch_assoc();
        $stmt->close();

        return $section ?: null;
    }

    public function findAll(): array
    {
        $result = $this->conn->query("
            SELECT s.*, d.name as department_name
            FROM sections s
            LEFT JOIN departments d ON s.department_id = d.id
            ORDER BY d.name, s.name
        ");

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function create(array $data): int
    {
        $this->validateWriteColumns($data, 'sections');

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

        $sql = "INSERT INTO `sections` (" . implode(', ', $quotedFields) . ") VALUES (" . implode(', ', $placeholders) . ")";
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

        $this->validateWriteColumns($data, 'sections');

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

        $sql = "UPDATE `sections` SET {$setClause} WHERE `id` = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->conn->prepare("DELETE FROM sections WHERE id = ?");
        $stmt->bind_param('i', $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    public function exists(int $id): bool
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM sections WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)$result['count'] > 0;
    }

    public function count(): int
    {
        $result = $this->conn->query("SELECT COUNT(*) as count FROM sections");
        $row = $result->fetch_assoc();

        return (int)$row['count'];
    }

    public function findWithSubsections(int $id): ?array
    {
        $section = $this->findById($id);
        if (!$section) {
            return null;
        }

        $subsections = $this->getSubsections($id);
        $section['subsections'] = $subsections;

        return $section;
    }

    public function getByDepartment(int $departmentId): array
    {
        $stmt = $this->conn->prepare("
            SELECT s.*, d.name as department_name
            FROM sections s
            LEFT JOIN departments d ON s.department_id = d.id
            WHERE s.department_id = ?
            ORDER BY s.name
        ");
        $stmt->bind_param('i', $departmentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $sections = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $sections;
    }

    public function getSubsections(int $sectionId): array
    {
        $stmt = $this->conn->prepare("
            SELECT ss.* FROM subsections ss
            WHERE ss.section_id = ?
            ORDER BY ss.name
        ");
        $stmt->bind_param('i', $sectionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $subsections = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $subsections;
    }

    public function nameExists(string $name, int $departmentId, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) as count FROM sections WHERE name = ? AND department_id = ?";
        $params = [$name, $departmentId];
        $types = 'si';

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

    public function getAllSubsections(): array
    {
        $result = $this->conn->query("
            SELECT ss.*, s.name as section_name, d.name as department_name
            FROM subsections ss
            LEFT JOIN sections s ON ss.section_id = s.id
            LEFT JOIN departments d ON s.department_id = d.id
            ORDER BY d.name, s.name, ss.name
        ");

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function findSubsectionById(int $id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT ss.*, s.name as section_name, d.name as department_name
            FROM subsections ss
            LEFT JOIN sections s ON ss.section_id = s.id
            LEFT JOIN departments d ON s.department_id = d.id
            WHERE ss.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $subsection = $result->fetch_assoc();
        $stmt->close();

        return $subsection ?: null;
    }

    public function createSubsection(array $data): int
    {
        $this->validateWriteColumns($data, 'subsections');

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

        $sql = "INSERT INTO `subsections` (" . implode(', ', $quotedFields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $insertId = (int)$this->conn->insert_id;
        $stmt->close();

        return $insertId;
    }

    public function updateSubsection(int $id, array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        $this->validateWriteColumns($data, 'subsections');

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

        $sql = "UPDATE `subsections` SET {$setClause} WHERE `id` = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Validate that every column name in $data is a whitelisted, safe identifier
     * for the given table. Throws \InvalidArgumentException on any unknown key.
     */
    private function validateWriteColumns(array $data, string $table): void
    {
        $allowed = match ($table) {
            'sections' => self::SECTION_COLUMNS,
            'subsections' => self::SUBSECTION_COLUMNS,
            default => throw new \InvalidArgumentException("Unknown table '$table' for write validation."),
        };

        foreach (array_keys($data) as $field) {
            if (!is_string($field) || !in_array($field, $allowed, true)) {
                throw new \InvalidArgumentException("Invalid column '$field' for table '$table'.");
            }
        }
    }

    public function deleteSubsection(int $id): bool
    {
        $stmt = $this->conn->prepare("DELETE FROM subsections WHERE id = ?");
        $stmt->bind_param('i', $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }
}

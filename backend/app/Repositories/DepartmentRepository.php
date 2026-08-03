<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\Contracts\DepartmentRepositoryInterface;
use App\Helpers\Database;

/**
 * Department Repository
 *
 * Handles all database operations for departments, sections, and subsections.
 * Encapsulates all SQL queries related to organizational structure.
 */
class DepartmentRepository implements DepartmentRepositoryInterface
{
    private \mysqli $conn;

    public function __construct()
    {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT d.* FROM departments d
            WHERE d.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $department = $result->fetch_assoc();
        $stmt->close();

        return $department ?: null;
    }

    public function findAll(): array
    {
        $result = $this->conn->query("
            SELECT * FROM departments
            ORDER BY name
        ");

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function create(array $data): int
    {
        $fields = array_keys($data);
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

        $sql = "INSERT INTO departments (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $insertId = (int)$this->conn->insert_id;
        $stmt->close();

        return $insertId;
    }

    public function update(int $id, array $data): bool
    {
        $fields = array_keys($data);
        $setClause = implode(' = ?, ', $fields) . ' = ?';
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

        $sql = "UPDATE departments SET {$setClause} WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->conn->prepare("DELETE FROM departments WHERE id = ?");
        $stmt->bind_param('i', $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    public function exists(int $id): bool
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM departments WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)$result['count'] > 0;
    }

    public function count(): int
    {
        $result = $this->conn->query("SELECT COUNT(*) as count FROM departments");
        $row = $result->fetch_assoc();

        return (int)$row['count'];
    }

    public function findWithSections(int $id): ?array
    {
        $department = $this->findById($id);
        if (!$department) {
            return null;
        }

        $sections = $this->getSections($id);
        $department['sections'] = $sections;

        return $department;
    }

    public function getAllActive(): array
    {
        $result = $this->conn->query("
            SELECT * FROM departments 
            WHERE status = 'active' 
            ORDER BY name
        ");

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function getSections(int $departmentId): array
    {
        $stmt = $this->conn->prepare("
            SELECT s.* FROM sections s
            WHERE s.department_id = ? AND s.status = 'active'
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
            WHERE ss.section_id = ? AND ss.status = 'active'
            ORDER BY ss.name
        ");
        $stmt->bind_param('i', $sectionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $subsections = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $subsections;
    }

    public function nameExists(string $name, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) as count FROM departments WHERE name = ?";
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

    public function getHierarchy(): array
    {
        $departments = $this->getAllActive();
        $hierarchy = [];

        foreach ($departments as $department) {
            $deptId = (int)$department['id'];
            $sections = $this->getSections($deptId);
            
            foreach ($sections as &$section) {
                $sectionId = (int)$section['id'];
                $subsections = $this->getSubsections($sectionId);
                $section['subsections'] = $subsections;
            }

            $department['sections'] = $sections;
            $hierarchy[] = $department;
        }

        return $hierarchy;
    }
}
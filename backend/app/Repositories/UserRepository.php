<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\Contracts\UserRepositoryInterface;
use App\Helpers\Database;

/**
 * User Repository
 *
 * Handles all database operations for users.
 * Encapsulates all SQL queries related to user management and authentication.
 */
class UserRepository implements UserRepositoryInterface
{
    private \mysqli $conn;

    public function __construct()
    {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT u.*, e.employee_id, e.first_name, e.last_name
            FROM users u
            LEFT JOIN employees e ON u.email = e.email
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        return $user ?: null;
    }

    public function findAll(): array
    {
        $result = $this->conn->query("
            SELECT u.*, e.employee_id, e.first_name, e.last_name
            FROM users u
            LEFT JOIN employees e ON u.email = e.email
            ORDER BY u.created_at DESC
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

        $sql = "INSERT INTO users (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
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

        $sql = "UPDATE users SET {$setClause} WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param('i', $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    public function exists(int $id): bool
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM users WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)$result['count'] > 0;
    }

    public function count(): int
    {
        $result = $this->conn->query("SELECT COUNT(*) as count FROM users");
        $row = $result->fetch_assoc();

        return (int)$row['count'];
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT u.*, e.employee_id, e.first_name, e.last_name, e.employee_status
            FROM users u
            LEFT JOIN employees e ON u.email = e.email
            WHERE u.email = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        return $user ?: null;
    }

    public function findWithEmployee(int $id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT u.*, e.employee_id, e.first_name, e.last_name, 
                   e.employee_status, e.department_id, e.section_id
            FROM users u
            LEFT JOIN employees e ON u.email = e.email
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        return $user ?: null;
    }

    public function findByEmployeeId(string $employeeId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT u.*, e.employee_id, e.first_name, e.last_name
            FROM users u
            LEFT JOIN employees e ON u.employee_id = e.employee_id
            WHERE u.employee_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $employeeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        return $user ?: null;
    }

    public function search(array $filters, int $page = 1, int $limit = 30): array
    {
        $whereConditions = ["1=1"];
        $params = [];
        $types = '';

        if (!empty($filters['search'])) {
            $searchParam = "%{$filters['search']}%";
            $whereConditions[] = "(u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR e.employee_id LIKE ?)";
            $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
            $types .= 'ssss';
        }

        if (!empty($filters['role'])) {
            $whereConditions[] = "u.role = ?";
            $params[] = $filters['role'];
            $types .= 's';
        }

        if (!empty($filters['is_active'])) {
            $whereConditions[] = "u.is_active = ?";
            $params[] = $filters['is_active'];
            $types .= 'i';
        }

        $whereClause = implode(" AND ", $whereConditions);
        $offset = ($page - 1) * $limit;

        // Count total
        $countQuery = "
            SELECT COUNT(*) as total FROM users u
            LEFT JOIN employees e ON u.email = e.email
            WHERE {$whereClause}
        ";
        $countStmt = $this->conn->prepare($countQuery);
        if (!empty($params)) {
            $countStmt->bind_param($types, ...$params);
        }
        $countStmt->execute();
        $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        // Fetch users
        $query = "
            SELECT u.*, e.employee_id, e.first_name, e.last_name, e.employee_status
            FROM users u
            LEFT JOIN employees e ON u.email = e.email
            WHERE {$whereClause}
            ORDER BY u.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return [
            'data' => $users,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => (int)ceil($total / $limit),
        ];
    }

    public function updatePassword(int $id, string $passwordHash): bool
    {
        $stmt = $this->conn->prepare("
            UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?
        ");
        $stmt->bind_param('si', $passwordHash, $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    public function updateStatus(int $id, string $status): bool
    {
        $stmt = $this->conn->prepare("
            UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?
        ");
        $stmt->bind_param('si', $status, $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    public function updateRole(int $id, string $role): bool
    {
        $stmt = $this->conn->prepare("
            UPDATE users SET role = ?, updated_at = NOW() WHERE id = ?
        ");
        $stmt->bind_param('si', $role, $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) as count FROM users WHERE email = ?";
        $params = [$email];
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

    public function getByRole(string $role): array
    {
        $stmt = $this->conn->prepare("
            SELECT u.*, e.employee_id, e.first_name, e.last_name
            FROM users u
            LEFT JOIN employees e ON u.email = e.email
            WHERE u.role = ?
            ORDER BY u.first_name
        ");
        $stmt->bind_param('s', $role);
        $stmt->execute();
        $result = $stmt->get_result();
        $users = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $users;
    }

    public function createUser(array $data): int
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

        $sql = "INSERT INTO users (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $insertId = (int)$this->conn->insert_id;
        $stmt->close();

        return $insertId;
    }
}
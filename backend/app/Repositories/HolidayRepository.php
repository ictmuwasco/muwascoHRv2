<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\Contracts\RepositoryInterface;
use App\Helpers\Database;

/**
 * Holiday Repository
 */
class HolidayRepository implements RepositoryInterface
{
    private \mysqli $conn;

    public function __construct()
    {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM holidays WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $result ?: null;
    }

    public function findAll(): array
    {
        $result = $this->conn->query("SELECT * FROM holidays ORDER BY date ASC");
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function create(array $data): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO holidays (name, date, description, is_recurring)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param(
            'sssi',
            $data['name'],
            $data['date'],
            $data['description'],
            $data['is_recurring']
        );
        $stmt->execute();
        $id = (int)$this->conn->insert_id;
        $stmt->close();

        return $id;
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $types = '';
        $values = [];

        foreach (['name', 'date', 'description', 'is_recurring'] as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
                $types .= is_int($data[$field]) ? 'i' : (is_float($data[$field]) ? 'd' : 's');
            }
        }

        if (empty($fields)) {
            return false;
        }

        $values[] = $id;
        $types .= 'i';
        $sql = "UPDATE holidays SET " . implode(', ', $fields) . " WHERE id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->conn->prepare("DELETE FROM holidays WHERE id = ?");
        $stmt->bind_param('i', $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    public function exists(int $id): bool
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM holidays WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_assoc()['count'];
        $stmt->close();

        return $count > 0;
    }

    public function count(): int
    {
        $result = $this->conn->query("SELECT COUNT(*) as count FROM holidays");
        return (int)$result->fetch_assoc()['count'];
    }

    public function getUpcoming(int $limit = 10): array
    {
        $stmt = $this->conn->prepare("
            SELECT * FROM holidays 
            WHERE date >= CURDATE() 
            ORDER BY date ASC 
            LIMIT ?
        ");
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $result;
    }

    public function getByMonth(int $year, int $month): array
    {
        $like = sprintf('%04d-%02d-%%', $year, $month);
        $stmt = $this->conn->prepare("
            SELECT * FROM holidays 
            WHERE date LIKE ? OR is_recurring = 1
            ORDER BY date ASC
        ");
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $result;
    }
}
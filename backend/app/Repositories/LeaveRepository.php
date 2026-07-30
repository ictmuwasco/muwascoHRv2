<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\Contracts\LeaveRepositoryInterface;
use App\Helpers\Database;

/**
 * Leave Repository
 *
 * Handles all database operations for leave applications and leave types.
 * Encapsulates all SQL queries related to leave management.
 */
class LeaveRepository implements LeaveRepositoryInterface
{
    private \mysqli $conn;

    public function __construct()
    {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT la.*, 
                   e.first_name, e.last_name, e.employee_id,
                   lt.name as leave_type_name,
                   u.email as applied_by_email
            FROM leave_applications la
            LEFT JOIN employees e ON la.employee_id = e.id
            LEFT JOIN leave_types lt ON la.leave_type_id = lt.id
            LEFT JOIN users u ON la.applied_by_user_id = u.id
            WHERE la.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $leave = $result->fetch_assoc();
        $stmt->close();

        return $leave ?: null;
    }

    public function findAll(): array
    {
        $result = $this->conn->query("
            SELECT la.*, 
                   e.first_name, e.last_name, e.employee_id,
                   lt.name as leave_type_name
            FROM leave_applications la
            LEFT JOIN employees e ON la.employee_id = e.id
            LEFT JOIN leave_types lt ON la.leave_type_id = lt.id
            ORDER BY la.applied_at DESC
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

        $sql = "INSERT INTO leave_applications (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
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

        $sql = "UPDATE leave_applications SET {$setClause} WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->conn->prepare("DELETE FROM leave_applications WHERE id = ?");
        $stmt->bind_param('i', $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    public function exists(int $id): bool
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM leave_applications WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)$result['count'] > 0;
    }

    public function count(): int
    {
        $result = $this->conn->query("SELECT COUNT(*) as count FROM leave_applications");
        $row = $result->fetch_assoc();

        return (int)$row['count'];
    }

    public function getByEmployee(int $employeeId, int $year = 0): array
    {
        if ($year > 0) {
            $startDate = sprintf('%04d-01-01', $year);
            $endDate = sprintf('%04d-12-31', $year);
            
            $stmt = $this->conn->prepare("
                SELECT la.*, lt.name as leave_type_name
                FROM leave_applications la
                LEFT JOIN leave_types lt ON la.leave_type_id = lt.id
                WHERE la.employee_id = ? AND la.start_date BETWEEN ? AND ?
                ORDER BY la.applied_at DESC
            ");
            $stmt->bind_param('iss', $employeeId, $startDate, $endDate);
        } else {
            $stmt = $this->conn->prepare("
                SELECT la.*, lt.name as leave_type_name
                FROM leave_applications la
                LEFT JOIN leave_types lt ON la.leave_type_id = lt.id
                WHERE la.employee_id = ?
                ORDER BY la.applied_at DESC
            ");
            $stmt->bind_param('i', $employeeId);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $leaves = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $leaves;
    }

    public function search(array $filters, int $page = 1, int $limit = 30): array
    {
        $whereConditions = ["1=1"];
        $params = [];
        $types = '';

        if (!empty($filters['employee_id'])) {
            $whereConditions[] = "la.employee_id = ?";
            $params[] = $filters['employee_id'];
            $types .= 'i';
        }

        if (!empty($filters['status'])) {
            $whereConditions[] = "la.status = ?";
            $params[] = $filters['status'];
            $types .= 's';
        }

        if (!empty($filters['leave_type_id'])) {
            $whereConditions[] = "la.leave_type_id = ?";
            $params[] = $filters['leave_type_id'];
            $types .= 'i';
        }

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $whereConditions[] = "la.start_date BETWEEN ? AND ?";
            $params[] = $filters['start_date'];
            $params[] = $filters['end_date'];
            $types .= 'ss';
        }

        $whereClause = implode(" AND ", $whereConditions);
        $offset = ($page - 1) * $limit;

        // Count total
        $countQuery = "SELECT COUNT(*) as total FROM leave_applications la WHERE {$whereClause}";
        $countStmt = $this->conn->prepare($countQuery);
        if (!empty($params)) {
            $countStmt->bind_param($types, ...$params);
        }
        $countStmt->execute();
        $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        // Fetch leaves
        $query = "
            SELECT la.*, 
                   e.first_name, e.last_name, e.employee_id,
                   lt.name as leave_type_name
            FROM leave_applications la
            LEFT JOIN employees e ON la.employee_id = e.id
            LEFT JOIN leave_types lt ON la.leave_type_id = lt.id
            WHERE {$whereClause}
            ORDER BY la.applied_at DESC
            LIMIT ? OFFSET ?
        ";

        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $leaves = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return [
            'data' => $leaves,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => (int)ceil($total / $limit),
        ];
    }

    public function getPendingApprovals(int $managerId): array
    {
        // Get employees under this manager based on role hierarchy
        $stmt = $this->conn->prepare("
            SELECT la.*, 
                   e.first_name, e.last_name, e.employee_id,
                   lt.name as leave_type_name,
                   u.email as employee_email
            FROM leave_applications la
            LEFT JOIN employees e ON la.employee_id = e.id
            LEFT JOIN leave_types lt ON la.leave_type_id = lt.id
            LEFT JOIN users u ON u.employee_id = e.employee_id
            WHERE la.status = 'pending'
            AND la.employee_id IN (
                SELECT id FROM employees WHERE department_id IN (
                    SELECT department_id FROM employees WHERE id = ?
                )
            )
            ORDER BY la.applied_at ASC
        ");
        $stmt->bind_param('i', $managerId);
        $stmt->execute();
        $result = $stmt->get_result();
        $leaves = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $leaves;
    }

    public function getLeaveTypes(): array
    {
        $result = $this->conn->query("
            SELECT * FROM leave_types 
            WHERE status = 'active' 
            ORDER BY name
        ");

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function getLeaveBalance(int $employeeId, int $leaveTypeId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT lb.*, lt.name as leave_type_name
            FROM leave_balances lb
            LEFT JOIN leave_types lt ON lb.leave_type_id = lt.id
            WHERE lb.employee_id = ? AND lb.leave_type_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('ii', $employeeId, $leaveTypeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $balance = $result->fetch_assoc();
        $stmt->close();

        return $balance ?: null;
    }

    public function updateStatus(int $id, string $status, ?int $approvedBy = null): bool
    {
        $sql = "UPDATE leave_applications SET status = ?, updated_at = NOW()";
        $params = [$status];
        $types = 's';

        if ($approvedBy !== null) {
            $sql .= ", approved_by = ?";
            $params[] = $approvedBy;
            $types .= 'i';
        }

        if ($status === 'approved') {
            $sql .= ", approved_at = NOW()";
        }

        $sql .= " WHERE id = ?";
        $params[] = $id;
        $types .= 'i';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    public function getStatistics(int $year, int $month = 0): array
    {
        $startDate = $month > 0 ? sprintf('%04d-%02d-01', $year, $month) : sprintf('%04d-01-01', $year);
        $endDate = $month > 0 ? date('Y-m-t', strtotime($startDate)) : sprintf('%04d-12-31', $year);

        $stmt = $this->conn->prepare("
            SELECT 
                COUNT(*) as total_applications,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(days_requested) as total_days
            FROM leave_applications
            WHERE start_date BETWEEN ? AND ?
        ");
        $stmt->bind_param('ss', $startDate, $endDate);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $result ?: [
            'total_applications' => 0,
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0,
            'cancelled' => 0,
            'total_days' => 0,
        ];
    }

    public function getHistory(int $employeeId, int $year): array
    {
        $startDate = sprintf('%04d-01-01', $year);
        $endDate = sprintf('%04d-12-31', $year);

        $stmt = $this->conn->prepare("
            SELECT la.*, lt.name as leave_type_name
            FROM leave_applications la
            LEFT JOIN leave_types lt ON la.leave_type_id = lt.id
            WHERE la.employee_id = ? AND la.start_date BETWEEN ? AND ?
            ORDER BY la.start_date DESC
        ");
        $stmt->bind_param('iss', $employeeId, $startDate, $endDate);
        $stmt->execute();
        $result = $stmt->get_result();
        $history = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $history;
    }

    public function hasConflict(int $employeeId, string $startDate, string $endDate, ?int $excludeId = null): bool
    {
        $sql = "
            SELECT COUNT(*) as count FROM leave_applications
            WHERE employee_id = ?
            AND status IN ('pending', 'approved')
            AND (
                (start_date <= ? AND end_date >= ?)
                OR (start_date <= ? AND end_date >= ?)
                OR (start_date >= ? AND end_date <= ?)
            )
        ";
        
        $params = [$employeeId, $endDate, $startDate, $endDate, $startDate, $startDate, $endDate];
        $types = 'issssss';

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
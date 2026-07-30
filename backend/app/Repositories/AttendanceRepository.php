<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\Contracts\AttendanceRepositoryInterface;
use App\Helpers\Database;

/**
 * Attendance Repository
 *
 * Handles all database operations for attendance records.
 * Encapsulates all SQL queries related to attendance management.
 */
class AttendanceRepository implements AttendanceRepositoryInterface
{
    private \mysqli $conn;

    public function __construct()
    {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT a.*, e.first_name, e.last_name, e.employee_id
            FROM attendance a
            LEFT JOIN employees e ON a.employee_id = e.id
            WHERE a.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $attendance = $result->fetch_assoc();
        $stmt->close();

        return $attendance ?: null;
    }

    public function findAll(): array
    {
        $result = $this->conn->query("
            SELECT a.*, e.first_name, e.last_name, e.employee_id
            FROM attendance a
            LEFT JOIN employees e ON a.employee_id = e.id
            ORDER BY a.date DESC
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

        $sql = "INSERT INTO attendance (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
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

        $sql = "UPDATE attendance SET {$setClause} WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->conn->prepare("DELETE FROM attendance WHERE id = ?");
        $stmt->bind_param('i', $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    public function exists(int $id): bool
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM attendance WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)$result['count'] > 0;
    }

    public function count(): int
    {
        $result = $this->conn->query("SELECT COUNT(*) as count FROM attendance");
        $row = $result->fetch_assoc();

        return (int)$row['count'];
    }

    public function findByEmployeeAndDate(int $employeeId, string $date): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT * FROM attendance
            WHERE employee_id = ? AND date = ?
            LIMIT 1
        ");
        $stmt->bind_param('is', $employeeId, $date);
        $stmt->execute();
        $result = $stmt->get_result();
        $attendance = $result->fetch_assoc();
        $stmt->close();

        return $attendance ?: null;
    }

    public function getByEmployeeAndDateRange(int $employeeId, string $startDate, string $endDate): array
    {
        $stmt = $this->conn->prepare("
            SELECT * FROM attendance
            WHERE employee_id = ? AND date BETWEEN ? AND ?
            ORDER BY date DESC
        ");
        $stmt->bind_param('iss', $employeeId, $startDate, $endDate);
        $stmt->execute();
        $result = $stmt->get_result();
        $records = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $records;
    }

    public function getTodayAttendance(): array
    {
        $today = date('Y-m-d');
        $result = $this->conn->query("
            SELECT a.*, e.first_name, e.last_name, e.employee_id,
                   d.name as department_name, s.name as section_name
            FROM attendance a
            LEFT JOIN employees e ON a.employee_id = e.id
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN sections s ON e.section_id = s.id
            WHERE a.date = '{$today}'
            ORDER BY a.clock_in_time DESC
        ");

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function getReport(string $startDate, string $endDate, array $filters = []): array
    {
        $whereConditions = ["a.date BETWEEN ? AND ?"];
        $params = [$startDate, $endDate];
        $types = 'ss';

        if (!empty($filters['department_id'])) {
            $whereConditions[] = "e.department_id = ?";
            $params[] = $filters['department_id'];
            $types .= 'i';
        }

        if (!empty($filters['section_id'])) {
            $whereConditions[] = "e.section_id = ?";
            $params[] = $filters['section_id'];
            $types .= 'i';
        }

        if (!empty($filters['employee_id'])) {
            $whereConditions[] = "a.employee_id = ?";
            $params[] = $filters['employee_id'];
            $types .= 'i';
        }

        $whereClause = implode(" AND ", $whereConditions);

        $query = "
            SELECT a.*, e.first_name, e.last_name, e.employee_id,
                   d.name as department_name, s.name as section_name
            FROM attendance a
            LEFT JOIN employees e ON a.employee_id = e.id
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN sections s ON e.section_id = s.id
            WHERE {$whereClause}
            ORDER BY a.date DESC, a.clock_in_time DESC
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $records = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $records;
    }

    public function clockIn(int $employeeId, string $date, string $time, ?string $notes = null): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO attendance (employee_id, date, clock_in_time, status, notes, created_at, updated_at)
            VALUES (?, ?, ?, 'present', ?, NOW(), NOW())
        ");
        $notes = $notes ?? '';
        $stmt->bind_param('isss', $employeeId, $date, $time, $notes);
        $stmt->execute();
        $insertId = (int)$this->conn->insert_id;
        $stmt->close();

        return $insertId;
    }

    public function updateClockOut(int $id, string $time): bool
    {
        $stmt = $this->conn->prepare("
            UPDATE attendance 
            SET clock_out_time = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param('si', $time, $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    public function getStatistics(int $employeeId, int $year, int $month): array
    {
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));

        $stmt = $this->conn->prepare("
            SELECT 
                COUNT(*) as total_days,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days,
                SUM(CASE WHEN status = 'half_day' THEN 1 ELSE 0 END) as half_days,
                SUM(overtime_hours) as total_overtime
            FROM attendance
            WHERE employee_id = ? AND date BETWEEN ? AND ?
        ");
        $stmt->bind_param('iss', $employeeId, $startDate, $endDate);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $result ?: [
            'total_days' => 0,
            'present_days' => 0,
            'absent_days' => 0,
            'late_days' => 0,
            'half_days' => 0,
            'total_overtime' => 0,
        ];
    }

    public function hasClockedInToday(int $employeeId): bool
    {
        $today = date('Y-m-d');
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as count FROM attendance
            WHERE employee_id = ? AND date = ? AND clock_in_time IS NOT NULL
        ");
        $stmt->bind_param('is', $employeeId, $today);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)$result['count'] > 0;
    }

    public function getLateArrivals(string $startDate, string $endDate): array
    {
        $stmt = $this->conn->prepare("
            SELECT a.*, e.first_name, e.last_name, e.employee_id,
                   d.name as department_name
            FROM attendance a
            LEFT JOIN employees e ON a.employee_id = e.id
            LEFT JOIN departments d ON e.department_id = d.id
            WHERE a.date BETWEEN ? AND ?
            AND a.status = 'late'
            ORDER BY a.date DESC, a.clock_in_time DESC
        ");
        $stmt->bind_param('ss', $startDate, $endDate);
        $stmt->execute();
        $result = $stmt->get_result();
        $records = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $records;
    }

    public function getByDepartment(int $departmentId, string $date): array
    {
        $stmt = $this->conn->prepare("
            SELECT a.*, e.first_name, e.last_name, e.employee_id
            FROM attendance a
            LEFT JOIN employees e ON a.employee_id = e.id
            WHERE e.department_id = ? AND a.date = ?
            ORDER BY a.clock_in_time DESC
        ");
        $stmt->bind_param('is', $departmentId, $date);
        $stmt->execute();
        $result = $stmt->get_result();
        $records = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $records;
    }
}
<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Repositories\Contracts\RepositoryInterface;
use App\Helpers\Database;

/**
 * Meeting Repository
 *
 * Handles all database operations for meetings and meeting invitations.
 */
class MeetingRepository implements RepositoryInterface
{
    private \mysqli $conn;

    public function __construct()
    {
        $this->conn = Database::getInstance()->getConnection();
    }

    /**
     * Find a single meeting by ID, including organizer name.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT m.*,
                   u.first_name AS org_first_name,
                   u.last_name  AS org_last_name
            FROM meetings m
            LEFT JOIN users u ON m.created_by = u.id
            WHERE m.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $meeting = $result->fetch_assoc();
        $stmt->close();

        return $meeting ?: null;
    }

    /**
     * Find all meetings (basic list).
     */
    public function findAll(): array
    {
        $result = $this->conn->query("
            SELECT m.*,
                   u.first_name AS org_first_name,
                   u.last_name  AS org_last_name
            FROM meetings m
            LEFT JOIN users u ON m.created_by = u.id
            ORDER BY m.meeting_date DESC, m.start_time DESC
        ");
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Create a new meeting record.
     */
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

        $sql = "INSERT INTO meetings (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $id = (int)$this->conn->insert_id;
        $stmt->close();

        return $id;
    }

    /**
     * Update an existing meeting record.
     */
    public function update(int $id, array $data): bool
    {
        if (empty($data)) {
            return false;
        }

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

        $sql = "UPDATE meetings SET {$setClause} WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Delete a meeting by ID.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->conn->prepare("DELETE FROM meetings WHERE id = ?");
        $stmt->bind_param('i', $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Check if a meeting exists.
     */
    public function exists(int $id): bool
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM meetings WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $count = (int)$stmt->get_result()->fetch_assoc()['count'];
        $stmt->close();

        return $count > 0;
    }

    /**
     * Count total meetings.
     */
    public function count(): int
    {
        $result = $this->conn->query("SELECT COUNT(*) as count FROM meetings");
        return (int)$result->fetch_assoc()['count'];
    }

    /**
     * Get paginated meetings with optional filters.
     *
     * @param int    $page
     * @param int    $perPage
     * @param array  $filters  Supported keys: status, date_from, date_to, search
     * @return array  {data, total, per_page, current_page, last_page}
     */
    public function paginate(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $where = [];
        $types = '';
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "m.status = ?";
            $types .= 's';
            $params[] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = "m.meeting_date >= ?";
            $types .= 's';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = "m.meeting_date <= ?";
            $types .= 's';
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['search'])) {
            $searchParam = '%' . $filters['search'] . '%';
            $where[] = "(m.title LIKE ? OR m.location LIKE ?)";
            $types .= 'ss';
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Count total
        $countSql = "SELECT COUNT(*) as total FROM meetings m {$whereClause}";
        $countStmt = $this->conn->prepare($countSql);
        if (!empty($params)) {
            $countStmt->bind_param($types, ...$params);
        }
        $countStmt->execute();
        $total = (int)$countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        // Fetch paginated data
        $offset = ($page - 1) * $perPage;
        $dataSql = "
            SELECT m.*,
                   u.first_name AS org_first_name,
                   u.last_name  AS org_last_name
            FROM meetings m
            LEFT JOIN users u ON m.created_by = u.id
            {$whereClause}
            ORDER BY m.meeting_date DESC, m.start_time DESC
            LIMIT ? OFFSET ?
        ";
        $dataTypes = $types . 'ii';
        $dataParams = array_merge($params, [$perPage, $offset]);

        $stmt = $this->conn->prepare($dataSql);
        $stmt->bind_param($dataTypes, ...$dataParams);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return [
            'data' => $data,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => max(1, (int)ceil($total / $perPage)),
        ];
    }

    /**
     * Get all eligible employees for inviting to a meeting.
     * Returns active employees with their basic info and designation.
     */
    public function getEligibleEmployees(): array
    {
        $result = $this->conn->query("
            SELECT e.id, e.first_name, e.last_name, e.employee_id, e.designation, e.email
            FROM employees e
            WHERE e.employee_status = 'active'
            ORDER BY e.first_name, e.last_name
        ");
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get meetings where the given employee is invited.
     *
     * @param int $employeeId
     * @return array
     */
    public function getMeetingsByEmployee(int $employeeId): array
    {
        $stmt = $this->conn->prepare("
            SELECT m.*,
                   u.first_name AS org_first_name,
                   u.last_name  AS org_last_name,
                   mi.id AS invitation_id,
                   mi.response_status,
                   mi.attendance_status,
                   mi.responded_at
            FROM meetings m
            JOIN meeting_invitations mi ON m.id = mi.meeting_id
            LEFT JOIN users u ON m.created_by = u.id
            WHERE mi.employee_id = ?
            ORDER BY m.meeting_date DESC, m.start_time DESC
        ");
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $meetings = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $meetings;
    }

    /**
     * Get a meeting with its invitations and participant details.
     */
    public function findByIdWithInvitations(int $id): ?array
    {
        $meeting = $this->findById($id);
        if (!$meeting) {
            return null;
        }

        // Get invitations
        $stmt = $this->conn->prepare("
            SELECT mi.*,
                   e.first_name, e.last_name, e.employee_id, e.designation, e.email
            FROM meeting_invitations mi
            LEFT JOIN employees e ON mi.employee_id = e.id
            WHERE mi.meeting_id = ?
            ORDER BY mi.invited_at DESC
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $invitations = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $meeting['invitations'] = $invitations;

        return $meeting;
    }

    /**
     * Create meeting invitations for the given employee IDs.
     *
     * @param int   $meetingId
     * @param array $employeeIds
     * @param int   $invitedBy
     * @return int  Number of invitations created
     */
    public function createInvitations(int $meetingId, array $employeeIds, int $invitedBy): int
    {
        $now = date('Y-m-d H:i:s');
        $count = 0;

        foreach ($employeeIds as $empId) {
            $empId = (int)$empId;
            if ($empId <= 0) {
                continue;
            }

            $stmt = $this->conn->prepare("
                INSERT INTO meeting_invitations
                    (meeting_id, employee_id, invited_by, invited_at, invitation_type, response_status, attendance_status)
                VALUES (?, ?, ?, ?, 'hr_invited', 'pending', 'not_marked')
            ");
            $stmt->bind_param('iiiss', $meetingId, $empId, $invitedBy, $now);
            $stmt->execute();
            $stmt->close();
            $count++;
        }

        return $count;
    }

    /**
     * Update an employee's response to a meeting invitation.
     *
     * @param int    $meetingId
     * @param int    $employeeId
     * @param string $responseStatus  'accepted' | 'declined' | 'tentative'
     * @return bool
     */
    public function updateInvitationResponse(int $meetingId, int $employeeId, string $responseStatus): bool
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->conn->prepare("
            UPDATE meeting_invitations
            SET response_status = ?, responded_at = ?
            WHERE meeting_id = ? AND employee_id = ?
        ");
        $stmt->bind_param('ssii', $responseStatus, $now, $meetingId, $employeeId);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Update the status of a meeting.
     */
    public function updateStatus(int $id, string $status): bool
    {
        $stmt = $this->conn->prepare("UPDATE meetings SET status = ? WHERE id = ?");
        $stmt->bind_param('si', $status, $id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Get the employee ID associated with a user ID.
     */
    public function getEmployeeIdByUserId(int $userId): ?int
    {
        $stmt = $this->conn->prepare("
            SELECT e.id
            FROM employees e
            JOIN users u ON u.employee_id = e.employee_id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row ? (int)$row['id'] : null;
    }

    /**
     * Get the organizer (user) name for a meeting.
     */
    public function getOrganizerName(int $userId): ?string
    {
        $stmt = $this->conn->prepare("
            SELECT first_name, last_name
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        return trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Leave Request Model
 * 
 * Table: leave_requests
 */
class LeaveRequest extends BaseModel
{
    protected static string $table = 'leave_requests';
    protected static array $fillable = [
        'employee_id', 'leave_type_id', 'start_date', 'end_date',
        'reason', 'status', 'approved_by', 'approved_at',
        'rejection_reason', 'created_at', 'updated_at',
    ];

    public const STATUSES = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
    ];

    /**
     * Get leave requests with employee and type details.
     */
    public static function getAllWithDetails(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $db = \db();
        $where = [];
        $types = '';
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "l.status = ?";
            $types .= 's';
            $params[] = $filters['status'];
        }
        if (!empty($filters['employee_id'])) {
            $where[] = "l.employee_id = ?";
            $types .= 'i';
            $params[] = (int) $filters['employee_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = "l.start_date >= ?";
            $types .= 's';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "l.end_date <= ?";
            $types .= 's';
            $params[] = $filters['date_to'];
        }

        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        $offset = ($page - 1) * $perPage;

        $total = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM leave_requests l {$whereClause}",
            $types, $params
        );

        $data = $db->fetchAll(
            "SELECT l.*, e.first_name, e.last_name, e.department_id,
                    lt.name as leave_type_name, lt.days_allowed,
                    d.name as department_name
             FROM leave_requests l
             JOIN employees e ON l.employee_id = e.id
             JOIN leave_types lt ON l.leave_type_id = lt.id
             LEFT JOIN departments d ON e.department_id = d.id
             {$whereClause}
             ORDER BY l.created_at DESC
             LIMIT ? OFFSET ?",
            $types . 'ii',
            array_merge($params, [$perPage, $offset])
        );

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }
}
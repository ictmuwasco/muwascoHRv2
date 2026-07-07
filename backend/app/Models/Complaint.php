<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Complaint Model
 * 
 * Table: complaints
 */
class Complaint extends BaseModel
{
    protected static string $table = 'complaints';
    protected static array $fillable = [
        'employee_id', 'category_id', 'subject', 'description',
        'priority', 'status', 'assigned_to', 'resolution',
        'resolved_at', 'created_at', 'updated_at',
    ];

    public const STATUSES = [
        'open' => 'Open',
        'in_progress' => 'In Progress',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];

    public const PRIORITIES = [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];

    /**
     * Get complaints with employee and category details.
     */
    public static function getAllWithDetails(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $db = \db();
        $where = [];
        $types = '';
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "c.status = ?";
            $types .= 's';
            $params[] = $filters['status'];
        }
        if (!empty($filters['employee_id'])) {
            $where[] = "c.employee_id = ?";
            $types .= 'i';
            $params[] = (int) $filters['employee_id'];
        }
        if (!empty($filters['priority'])) {
            $where[] = "c.priority = ?";
            $types .= 's';
            $params[] = $filters['priority'];
        }

        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        $offset = ($page - 1) * $perPage;

        $total = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM complaints c {$whereClause}",
            $types, $params
        );

        $data = $db->fetchAll(
            "SELECT c.*, e.first_name, e.last_name, e.department,
                    cc.name as category_name,
                    assigned.first_name as assigned_first_name,
                    assigned.last_name as assigned_last_name
             FROM complaints c
             JOIN employees e ON c.employee_id = e.id
             LEFT JOIN complaint_categories cc ON c.category_id = cc.id
             LEFT JOIN employees assigned ON c.assigned_to = assigned.id
             {$whereClause}
             ORDER BY 
                CASE c.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END,
                c.created_at DESC
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
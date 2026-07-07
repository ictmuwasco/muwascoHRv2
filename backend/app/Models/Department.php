<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Department Model
 * 
 * Represents organizational departments.
 * Table: departments
 */
class Department extends BaseModel
{
    protected static string $table = 'departments';
    protected static array $fillable = ['name', 'code', 'description', 'head_employee_id', 'created_at', 'updated_at'];

    /**
     * Get all departments with their section counts.
     */
    public static function getAllWithCounts(): array
    {
        $db = \db();
        return $db->fetchAll("
            SELECT d.*, 
                   COUNT(DISTINCT s.id) as section_count,
                   COUNT(DISTINCT e.id) as employee_count
            FROM departments d
            LEFT JOIN sections s ON s.department_id = d.id
            LEFT JOIN employees e ON e.department_id = d.id
            GROUP BY d.id
            ORDER BY d.name
        ");
    }

    /**
     * Get sections for a department.
     */
    public static function getSections(int $departmentId): array
    {
        $db = \db();
        return $db->fetchAll(
            "SELECT * FROM sections WHERE department_id = ? ORDER BY name",
            'i',
            [$departmentId]
        );
    }
}
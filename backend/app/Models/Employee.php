<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Employee Model
 * 
 * Represents an employee record with all related fields including
 * personal info, employment details, organizational hierarchy, and next of kin.
 * 
 * Table: employees
 * Primary Key: id
 */
class Employee extends BaseModel
{
    protected static string $table = 'employees';
    protected static string $primaryKey = 'id';
    protected static array $fillable = [
        'employee_id', 'first_name', 'last_name', 'surname', 'gender',
        'national_id', 'phone', 'email', 'date_of_birth', 'address',
        'designation', 'department_id', 'section_id', 'subsection_id',
        'office_id', 'employee_type', 'employment_type', 'employee_status',
        'hire_date', 'scale_id', 'next_of_kin', 'profile_token',
        'created_at', 'updated_at',
    ];

    /**
     * Allowed employee types with their display names.
     */
    public const EMPLOYEE_TYPES = [
        'officer' => 'Officer',
        'sub_section_head' => 'Sub Section Head',
        'section_head' => 'Section Head',
        'manager' => 'Manager',
        'hr_manager' => 'Human Resource Manager',
        'dept_head' => 'Department Head',
        'managing_director' => 'Managing Director',
        'bod_chairman' => 'BOD Chairman',
        'super_admin' => 'Super Admin',
    ];

    /**
     * Allowed employment types.
     */
    public const EMPLOYMENT_TYPES = [
        'permanent' => 'Permanent',
        'contract' => 'Contract',
        'temporary' => 'Temporary',
        'intern' => 'Intern',
    ];

    /**
     * Allowed employee statuses.
     */
    public const EMPLOYEE_STATUSES = [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'resigned' => 'Resigned',
        'fired' => 'Fired',
        'retired' => 'Retired',
        'suspended' => 'Suspended',
        'on_leave' => 'On Leave',
    ];

    /**
     * Job groups (scale IDs).
     */
    public const JOB_GROUPS = ['1', '2', '3', '3A', '3B', '3C', '4', '5', '6', '7', '8', '9', '10'];

    /**
     * Leadership scopes for uniqueness validation.
     */
    public const LEADERSHIP_SCOPES = [
        'managing_director' => 'organization',
        'dept_head' => 'department',
        'section_head' => 'section',
        'sub_section_head' => 'subsection',
    ];

    /**
     * Employee type to user role mapping.
     */
    public const TYPE_TO_ROLE_MAP = [
        'managing_director' => 'managing_director',
        'bod_chairman' => 'bod_chairman',
        'super_admin' => 'super_admin',
        'dept_head' => 'dept_head',
        'hr_manager' => 'hr_manager',
        'manager' => 'manager',
        'section_head' => 'section_head',
        'sub_section_head' => 'sub_section_head',
        'officer' => 'officer',
        'full_time' => 'officer',
        'part_time' => 'officer',
        'contract' => 'officer',
        'temporary' => 'officer',
    ];

    /**
     * Get the display name for an employee type.
     */
    public static function getTypeDisplayName(string $type): string
    {
        return self::EMPLOYEE_TYPES[$type] ?? ucwords(str_replace('_', ' ', $type));
    }

    /**
     * Get the badge class for an employee type.
     */
    public static function getTypeBadgeClass(string $type): string
    {
        $badges = [
            'full_time' => 'badge-primary',
            'part_time' => 'badge-info',
            'contract' => 'badge-warning',
            'temporary' => 'badge-secondary',
            'officer' => 'badge-primary',
            'section_head' => 'badge-info',
            'manager' => 'badge-success',
            'hr_manager' => 'badge-success',
            'dept_head' => 'badge-info',
            'managing_director' => 'badge-primary',
            'bod_chairman' => 'badge-primary',
        ];
        return $badges[$type] ?? 'badge-gray';
    }

    /**
     * Get the badge class for an employee status.
     */
    public static function getStatusBadgeClass(string $status): string
    {
        $badges = [
            'active' => 'badge-success',
            'on_leave' => 'badge-warning',
            'terminated' => 'badge-danger',
            'resigned' => 'badge-secondary',
            'inactive' => 'badge-secondary',
            'fired' => 'badge-danger',
            'retired' => 'badge-secondary',
            'suspended' => 'badge-danger',
        ];
        return $badges[$status] ?? 'badge-gray';
    }

    /**
     * Get user role from employee type.
     */
    public static function getUserRoleFromType(string $employeeType): string
    {
        return self::TYPE_TO_ROLE_MAP[$employeeType] ?? 'officer';
    }

    /**
     * Get the leadership scope for an employee type.
     */
    public static function getLeadershipScope(string $employeeType): ?string
    {
        return self::LEADERSHIP_SCOPES[$employeeType] ?? null;
    }

    /**
     * Check if an employee type is a leadership role.
     */
    public static function isLeadershipRole(string $employeeType): bool
    {
        return isset(self::LEADERSHIP_SCOPES[$employeeType]);
    }

    /**
     * Find an employee by their employee_id (the alphanumeric ID, not database ID).
     */
    public static function findByEmployeeId(string $employeeId): ?array
    {
        return self::findWhere(['employee_id' => $employeeId])[0] ?? null;
    }

    /**
     * Find an employee by email.
     */
    public static function findByEmail(string $email): ?array
    {
        return self::findWhere(['email' => $email])[0] ?? null;
    }

    /**
     * Search employees by name, ID, or email.
     */
    public static function search(string $query, array $filters = [], int $page = 1, int $perPage = 30): array
    {
        $db = \db();
        $where = [];
        $types = '';
        $params = [];

        // Text search
        if (!empty($query)) {
            $where[] = "(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ? OR e.email LIKE ?)";
            $searchParam = "%{$query}%";
            $types .= 'ssss';
            $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
        }

        // Department filter
        if (!empty($filters['department_id'])) {
            $where[] = "e.department_id = ?";
            $types .= 'i';
            $params[] = (int) $filters['department_id'];
        }

        // Section filter
        if (!empty($filters['section_id'])) {
            $where[] = "e.section_id = ?";
            $types .= 'i';
            $params[] = (int) $filters['section_id'];
        }

        // Employee type filter
        if (!empty($filters['employee_type'])) {
            $where[] = "e.employee_type = ?";
            $types .= 's';
            $params[] = $filters['employee_type'];
        }

        // Status filter
        if (!empty($filters['employee_status'])) {
            $where[] = "e.employee_status = ?";
            $types .= 's';
            $params[] = $filters['employee_status'];
        }

        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

        // Count total
        $countSql = "SELECT COUNT(*) as total FROM employees e {$whereClause}";
        $total = (int) $db->fetchValue($countSql, $types, $params);

        // Calculate pagination
        $offset = ($page - 1) * $perPage;
        $lastPage = max(1, (int) ceil($total / $perPage));

        // Fetch with joins
        $dataSql = "
            SELECT e.*,
                   d.name as department_name,
                   s.name as section_name,
                   ss.name as subsection_name,
                   o.name as office_name
            FROM employees e
            LEFT JOIN departments d ON e.department_id = d.id
            LEFT JOIN sections s ON e.section_id = s.id
            LEFT JOIN subsections ss ON e.subsection_id = ss.id
            LEFT JOIN offices o ON e.office_id = o.id
            {$whereClause}
            ORDER BY e.created_at DESC
            LIMIT ? OFFSET ?
        ";
        $dataTypes = $types . 'ii';
        $dataParams = array_merge($params, [$perPage, $offset]);
        $data = $db->fetchAll($dataSql, $dataTypes, $dataParams);

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
        ];
    }

    /**
     * Prepare next of kin data as JSON string.
     */
    public static function prepareNextOfKin(array $names, array $relationships, array $contacts): string
    {
        $nextOfKin = [];
        $count = count($names);

        for ($i = 0; $i < $count; $i++) {
            $name = trim($names[$i] ?? '');
            $relationship = trim($relationships[$i] ?? '');
            $contact = trim($contacts[$i] ?? '');

            if (!empty($name)) {
                $nextOfKin[] = [
                    'name' => $name,
                    'relationship' => $relationship,
                    'contact' => $contact,
                ];
            }
        }

        return json_encode($nextOfKin, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Parse next of kin JSON data.
     */
    public static function parseNextOfKin(?string $json): array
    {
        if (empty($json)) {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }
}
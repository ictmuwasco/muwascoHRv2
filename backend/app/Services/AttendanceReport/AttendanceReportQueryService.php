<?php

declare(strict_types=1);

namespace App\Services\AttendanceReport;

/**
 * AttendanceReportQueryService
 *
 * Normalises filter input from the request and builds the reusable SQL
 * fragments (WHERE clause, bind types, params) used by every analytics slice.
 * Mirrors LeaveReportQueryService so the two report modules share the same
 * contract; scope (department) restrictions come from OrgScope via the
 * controller, exactly as LeaveReportController does.
 */
final class AttendanceReportQueryService
{
    /**
     * Normalise + validate the incoming filter set.
     *
     * Accepts query-string keys the frontend sends (from, to, department_id,
     * office_id, employee_id, employee_type, status, search) and returns a
     * clean associative array. Date range is always normalised to YYYY-MM-DD
     * (defaulting to the current month) or null when absent.
     */
    public function normalizeFilters(array $raw): array
    {
        $filters = [
            'from'           => $this->normalizeDate($raw['from'] ?? null),
            'to'             => $this->normalizeDate($raw['to'] ?? null),
            'department_id'  => $this->toInt($raw['department_id'] ?? null),
            'office_id'      => $this->toInt($raw['office_id'] ?? null),
            'employee_id'    => $this->toInt($raw['employee_id'] ?? null),
            'employee_type'  => $raw['employee_type'] ?? null,
            'status'         => $raw['status'] ?? null,
            'statuses'       => $this->parseEnumList($raw['status'] ?? null),
            'search'         => isset($raw['search']) && $raw['search'] !== '' ? (string) $raw['search'] : null,
        ];

        // Default range = current month (matches the legacy report default).
        if (!$filters['from']) {
            $filters['from'] = date('Y-m-01');
        }
        if (!$filters['to']) {
            $filters['to'] = date('Y-m-d');
        }

        return $filters;
    }

    /**
     * Build the base WHERE clause + bound params for the attendance range
     * query, applying both the date range and the OrgScope restrictions.
     *
     * @param array $filters normalised filters from normalizeFilters()
     * @param array $scope   OrgScope::current() result
     *
     * @return array{0:string, 1:string, 2:array}  [whereClause, bindTypes, bindParams]
     */
    public function buildWhere(array $filters, array $scope): array
    {
        $where = [];
        $types = '';
        $params = [];

        // Date range is always present after normalisation.
        $where[] = 'a.attendance_date BETWEEN ? AND ?';
        $types .= 'ss';
        $params[] = $filters['from'];
        $params[] = $filters['to'];

        // Department restriction: explicit filter wins, otherwise scope pinning.
        $departmentId = $filters['department_id'] ?? null;
        if ($departmentId === null) {
            $departmentId = $this->scopeDepartmentId($scope);
        }
        if ($departmentId !== null) {
            $where[] = 'e.department_id = ?';
            $types .= 'i';
            $params[] = (int) $departmentId;
        }

        // Office restriction.
        if (!empty($filters['office_id'])) {
            $where[] = 'e.office_id = ?';
            $types .= 'i';
            $params[] = (int) $filters['office_id'];
        }

        // Single-employee drill-down.
        if (!empty($filters['employee_id'])) {
            $where[] = 'e.id = ?';
            $types .= 'i';
            $params[] = (int) $filters['employee_id'];
        }

        // Employee type filter.
        if ($filters['employee_type'] !== null) {
            if (is_array($filters['employee_type'])) {
                $where[] = 'e.employee_type IN (' . str_repeat('?,', count($filters['employee_type']) - 1) . '?)';
                $types .= str_repeat('s', count($filters['employee_type']));
                $params = array_merge($params, $filters['employee_type']);
            } else {
                $where[] = 'e.employee_type = ?';
                $types .= 's';
                $params[] = (string) $filters['employee_type'];
            }
        }

        $clause = implode(' AND ', $where);

        return [$clause, $types, $params];
    }

    /**
     * Resolve the scope-pinning department for scoped (non-HR) users.
     * Returns null for full-visibility users (HR / super admin).
     */
    public function scopeDepartmentId(array $scope): ?int
    {
        if (!empty($scope['is_hr']) || !empty($scope['is_super_admin'])) {
            return null;
        }
        return isset($scope['department_id']) && $scope['department_id'] !== null
            ? (int) $scope['department_id']
            : null;
    }

    // ---- normalizers --------------------------------------------------
    private function normalizeDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        return $value;
    }

    private function toInt($value): ?int
    {
        if ($value === null || $value === '' || $value === '0') {
            return null;
        }
        return (int) $value;
    }
    /**
     * SQL condition matching attendance records against record-level status
     * filters (present | late | missing | auto). Calendar-derived statuses
     * (on_leave, absent) are intentionally ignored here: they cannot be
     * expressed against raw attendance rows and are handled by the
     * analytics/records services instead.
     *
     * Long aliases (auto_clocked_out, missing_clock_out) are accepted so the
     * canonical option keys exposed by AttendanceReportService::options()
     * work identically. Statuses are OR-combined so combinations like
     * "late OR missing" work.
     *
     * @return array{0:string,1:string,2:array} [sqlFragment, bindTypes, bindParams]
     */
    public function recordStatusCondition(array $statuses): array
    {
        $parts = [];
        foreach ($statuses as $status) {
            switch (strtolower((string) $status)) {
                case 'present':
                    $parts[] = "(a.is_late = 0 AND a.auto_clocked_out = 0 AND a.clock_out IS NOT NULL AND a.clock_out <> '')";
                    break;
                case 'late':
                    $parts[] = 'a.is_late = 1';
                    break;
                case 'missing':
                case 'missing_clock_out':
                case 'missing_clockout':
                    $parts[] = "(a.auto_clocked_out = 0 AND (a.clock_out IS NULL OR a.clock_out = ''))";
                    break;
                case 'auto':
                case 'auto_clocked_out':
                case 'auto_clockout':
                    $parts[] = 'a.auto_clocked_out = 1';
                    break;
            }
        }

        if ($parts === []) {
            return ['', '', []];
        }
        // No bind params needed: the fragments are fully literal.
        return ['(' . implode(' OR ', $parts) . ')', '', []];
    }

    // ---- normalizers --------------------------------------------------

    private function parseEnumList($value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return array_values(array_filter($value, fn ($v) => $v !== '' && $v !== null));
        }
        return [(string) $value];
    }
}

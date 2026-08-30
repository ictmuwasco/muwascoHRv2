<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;

/**
 * LeaveReportQueryService
 *
 * Single source of truth for building the filtered, role-scoped SQL that
 * powers every Leave Report endpoint (summary, analytics, records, export).
 *
 * All consumers share the SAME filter pipeline so the on-screen dashboard and
 * the CSV export always mirror each other.
 */
final class LeaveReportQueryService
{
    /**
     * Statuses that represent "same step as pending" in the multi-stage leave
     * workflow. The stored enum uses one value per approval stage, but for a
     * reporting "Pending" bucket we treat the whole family as pending.
     */
    private const PENDING_STATUSES = [
        'pending',
        'pending_subsection_head',
        'pending_section_head',
        'pending_dept_head',
        'pending_managing_director',
        'pending_bod_chair',
        'pending_manager',
    ];

    /**
     * The column on which the report's date-range is applied.
     * 'applied_at' = application date, 'start_date' = leave start, 'end_date' = leave end.
     */
    private const DATE_BASIS_WHITELIST = ['applied_at', 'start_date', 'end_date'];

    private const STATUS_WHITELIST = [
        'pending', 'approved', 'rejected', 'cancelled', 'invalidated',
    ];

    private const SEARCH_WHITELIST_COLUMNS = ['la.id', 'e.first_name', 'e.last_name',
        'e.surname', 'e.employee_id', 'd.name'];

    /**
     * Parse and sanitize the incoming HTTP filter params.
     */
    public function normalizeFilters(array $raw): array
    {
        $filters = [];

        $dateBasis = isset($raw['date_basis']) ? strtolower((string) $raw['date_basis']) : 'start_date';
        $filters['date_basis'] = in_array($dateBasis, self::DATE_BASIS_WHITELIST, true)
            ? $dateBasis
            : 'start_date';

        $filters['from'] = $this->cleanDate($raw['from'] ?? null);
        $filters['to']   = $this->cleanDate($raw['to'] ?? null);

        $filters['department_id'] = (int) ($raw['department_id'] ?? 0) ?: null;
        $filters['leave_type_id'] = (int) ($raw['leave_type_id'] ?? 0) ?: null;
        $filters['financial_year_id'] = (int) ($raw['financial_year_id'] ?? 0) ?: null;
        $filters['employee_id'] = (int) ($raw['employee_id'] ?? 0) ?: null;

        // Status may arrive as a single value or comma-separated list.
        $status = $raw['status'] ?? '';
        $statusList = [];
        if (is_string($status) && $status !== '') {
            foreach (explode(',', $status) as $part) {
                $part = strtolower(trim($part));
                if ($part === '') {
                    continue;
                }
                if (in_array($part, self::STATUS_WHITELIST, true)) {
                    $statusList[] = $part;
                }
            }
        }
        $filters['statuses'] = array_values(array_unique($statusList));

        $filters['search'] = trim((string) ($raw['search'] ?? ''));

        return $filters;
    }

    /**
     * Build the shared WHERE fragment (with bound params) from normalized
     * filters plus organisation scope. Does NOT include SELECT/ORDER/LIMIT.
     *
     * @return array{string, string, array} [whereSql, types, params]
     */
    public function buildWhere(array $filters, array $scope): array
    {
        $where  = [];
        $params = [];
        $types  = '';

        $dateColumn = 'la.' . $filters['date_basis'];

        // Date range on the chosen basis.
        if ($filters['from'] !== null) {
            $where[] = "$dateColumn >= ?";
            $types  .= 's';
            $params[] = $filters['from'];
        }
        if ($filters['to'] !== null) {
            $where[] = "$dateColumn <= ?";
            $types  .= 's';
            $params[] = $filters['to'] . ' 23:59:59';
        }

        // Financial year resolves to a date window on the same basis.
        if ($filters['financial_year_id'] !== null) {
            $fyWindow = $this->financialYearWindow((int) $filters['financial_year_id']);
            if ($fyWindow !== null) {
                [$fyFrom, $fyTo] = $fyWindow;
                $where[] = "$dateColumn >= ?";
                $types  .= 's';
                $params[] = $fyFrom;
                $where[] = "$dateColumn <= ?";
                $types  .= 's';
                $params[] = $fyTo . ' 23:59:59';
            }
        }

        // Department / leave-type / status / employee filters.
        if ($filters['department_id'] !== null) {
            $where[] = 'e.department_id = ?';
            $types  .= 'i';
            $params[] = $filters['department_id'];
        }
        if ($filters['leave_type_id'] !== null) {
            $where[] = 'la.leave_type_id = ?';
            $types  .= 'i';
            $params[] = $filters['leave_type_id'];
        }
        if ($filters['employee_id'] !== null) {
            $where[] = 'la.employee_id = ?';
            $types  .= 'i';
            $params[] = $filters['employee_id'];
        }
        if (!empty($filters['statuses'])) {
            $statusWhere = [];
            foreach ($filters['statuses'] as $status) {
                if ($status === 'pending') {
                    $statusWhere[] = 'la.status IN (' . implode(',', array_fill(0, count(self::PENDING_STATUSES), '?')) . ')';
                    foreach (self::PENDING_STATUSES as $ps) {
                        $types  .= 's';
                        $params[] = $ps;
                    }
                } else {
                    $statusWhere[] = 'la.status = ?';
                    $types  .= 's';
                    $params[] = $status;
                }
            }
            $where[] = '(' . implode(' OR ', $statusWhere) . ')';
        }

        // Free-text employee search (used by the detail table).
        if ($filters['search'] !== '') {
            $searchLike = '%' . $filters['search'] . '%';
            $searchClauses = [];
            foreach (self::SEARCH_WHITELIST_COLUMNS as $col) {
                $searchClauses[] = "$col LIKE ?";
                $types  .= 's';
                $params[] = $searchLike;
            }
            $where[] = '(' . implode(' OR ', $searchClauses) . ')';
        }

        // Organisation scope: broad-access roles see everything; everyone
        // else is pinned to their own department/section/subsection.
        [$scopeSql, $scopeParams, $scopeTypes] = self::scopeClause($scope);
        if ($scopeParams !== []) {
            $where[] = $scopeSql;
            foreach ($scopeParams as $i => $p) {
                $types  .= ($scopeTypes[$i] ?? 'i');
                $params[] = $p;
            }
        } elseif ($scopeSql === '1=0') {
            $where[] = '1=0';
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        return [$whereClause, $types, $params];
    }

    /**
     * Build the role scope clause for the leave report. `employees` carries the
     * department/section/subsection, so broad roles see everything and all
     * other users are pinned to their own unit.
     *
     * @return array{string, array, array} [sql, params, types]
     */
    public static function scopeClause(array $scope): array
    {
        if (($scope['is_hr'] ?? false) || ($scope['is_super_admin'] ?? false) || ($scope['is_pme_or_audit'] ?? false)) {
            return ['1=1', [], []];
        }

        $dept = $scope['department_id'] ?? null;
        $sec  = $scope['section_id'] ?? null;
        $sub  = $scope['subsection_id'] ?? null;

        $clauses = [];
        $params  = [];
        $types   = '';
        $add = function (string $col, int $val) use (&$clauses, &$params, &$types) {
            $clauses[] = "$col = ?";
            $params[]  = $val;
            $types    .= 'i';
        };

        if (($scope['is_sub_section_head'] ?? false) || ($scope['is_section_head'] ?? false)) {
            if ($sub !== null && $sec !== null && $dept !== null) {
                $add('e.department_id', $dept);
                $add('e.section_id', $sec);
                $add('e.subsection_id', $sub);
            } elseif ($sec !== null && $dept !== null) {
                $add('e.department_id', $dept);
                $add('e.section_id', $sec);
            } elseif ($dept !== null) {
                $add('e.department_id', $dept);
            }
        } elseif ($dept !== null) {
            $add('e.department_id', $dept);
        }

        if ($clauses === []) {
            // Unresolved unit -> deny rather than expose organisation-wide data.
            return ['1=0', [], []];
        }

        return [implode(' AND ', $clauses), $params, $types];
    }

    private function financialYearWindow(int $fyId): ?array
    {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT start_date, end_date FROM financial_years WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $fyId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || empty($row['start_date']) || empty($row['end_date'])) {
            return null;
        }

        return [$row['start_date'], $row['end_date']];
    }

    private function cleanDate($value): ?string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            return trim($value);
        }
        return null;
    }
}
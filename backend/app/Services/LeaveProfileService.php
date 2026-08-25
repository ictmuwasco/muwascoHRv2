<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use App\Helpers\Auth;

/**
 * LeaveProfileService
 *
 * Provides the Employee Leave Profile / Leave Account / Leave Ledger
 * functionality.  This service centralises the leave balance timeline
 * calculation (buildBalanceTimeline) so that the entire HR Leave
 * Management module uses one consistent source of truth.
 *
 * The database remains the source of truth — no balances are stored
 * in JavaScript or browser storage.  All calculations are performed
 * server-side from authoritative database records.
 */
class LeaveProfileService
{
    private \mysqli $db;

    /** Leave type IDs that have special behaviour. */
    public const LEAVE_TYPE_ANNUAL       = 1;
    public const LEAVE_TYPE_SICK         = 2;
    public const LEAVE_TYPE_STUDY        = 5;
    public const LEAVE_TYPE_SHORT        = 6;
    public const LEAVE_TYPE_ABSENCE      = 8;
    public const LEAVE_TYPE_CLAIM_A_DAY  = 9;

    /** Roles that may view any employee's leave profile. */
    private const HR_ROLES = ['hr_manager', 'super_admin', 'managing_director'];

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // ───────────────────────────────────────────────────────────────────
    //  Employee lookup & authorisation
    // ───────────────────────────────────────────────────────────────────

    /**
     * Get the list of employees that the current user is allowed to
     * view in the leave-profile selector.
     *
     * Officers see only themselves.  HR managers and super admins see
     * all active employees.  Section/sub-section/department heads see
     * employees within their organisational scope.
     *
     * @return array<int, array>
     */
    public function getEligibleEmployees(): array
    {
        $auth = Auth::getInstance();
        $userId = $auth->id();
        if (!$userId) {
            return [];
        }

        $currentUser = $auth->user();
        $role = strtolower($currentUser['role'] ?? '');

        // Resolve the current user's employee record ID from the session.
        // The session stores employee_id as the employee's string ID (e.g. "EMP00125"),
        // but we need the employees.id integer primary key for lookups.
        $currentEmployeeRecordId = $this->resolveEmployeeRecordId($currentUser['employee_id'] ?? null);

        // Super-admin / HR / MD — all active employees
        if (in_array($role, self::HR_ROLES, true)) {
            $sql = "
                SELECT e.id, e.employee_id, e.first_name, e.last_name, e.surname,
                       e.employment_type, e.designation,
                       d.name AS department_name,
                       s.name AS section_name,
                       ss.name AS subsection_name
                FROM employees e
                LEFT JOIN departments d  ON d.id = e.department_id
                LEFT JOIN sections s     ON s.id = e.section_id
                LEFT JOIN subsections ss ON ss.id = e.subsection_id
                WHERE e.employee_status = 'active'
                ORDER BY e.first_name, e.last_name
            ";
            $result = $this->db->query($sql);
            return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        }

        // Sub-section head — employees in their subsection (incl. self)
        if ($role === 'sub_section_head') {
            $stmt = $this->db->prepare("
                SELECT e.id, e.employee_id, e.first_name, e.last_name, e.surname,
                       e.employment_type, e.designation,
                       d.name AS department_name,
                       s.name AS section_name,
                       ss.name AS subsection_name
                FROM employees e
                LEFT JOIN departments d  ON d.id = e.department_id
                LEFT JOIN sections s     ON s.id = e.section_id
                LEFT JOIN subsections ss ON ss.id = e.subsection_id
                WHERE e.subsection_id = (
                    SELECT subsection_id FROM employees WHERE id = ? LIMIT 1
                ) AND e.employee_status = 'active'
                ORDER BY e.first_name, e.last_name
            ");
            $stmt->bind_param('i', $currentEmployeeRecordId);
            $stmt->execute();
            $result = $stmt->get_result();
            $employees = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();
            return $employees;
        }

        // Section head — employees in their section
        if ($role === 'section_head') {
            $stmt = $this->db->prepare("
                SELECT e.id, e.employee_id, e.first_name, e.last_name, e.surname,
                       e.employment_type, e.designation,
                       d.name AS department_name,
                       s.name AS section_name,
                       ss.name AS subsection_name
                FROM employees e
                LEFT JOIN departments d  ON d.id = e.department_id
                LEFT JOIN sections s     ON s.id = e.section_id
                LEFT JOIN subsections ss ON ss.id = e.subsection_id
                WHERE e.section_id = (
                    SELECT section_id FROM employees WHERE id = ? LIMIT 1
                ) AND e.employee_status = 'active'
                ORDER BY e.first_name, e.last_name
            ");
            $stmt->bind_param('i', $currentEmployeeRecordId);
            $stmt->execute();
            $result = $stmt->get_result();
            $employees = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();
            return $employees;
        }

        // Department head — employees in their department
        if ($role === 'dept_head') {
            $stmt = $this->db->prepare("
                SELECT e.id, e.employee_id, e.first_name, e.last_name, e.surname,
                       e.employment_type, e.designation,
                       d.name AS department_name,
                       s.name AS section_name,
                       ss.name AS subsection_name
                FROM employees e
                LEFT JOIN departments d  ON d.id = e.department_id
                LEFT JOIN sections s     ON s.id = e.section_id
                LEFT JOIN subsections ss ON ss.id = e.subsection_id
                WHERE e.department_id = (
                    SELECT department_id FROM employees WHERE id = ? LIMIT 1
                ) AND e.employee_status = 'active'
                ORDER BY e.first_name, e.last_name
            ");
            $stmt->bind_param('i', $currentEmployeeRecordId);
            $stmt->execute();
            $result = $stmt->get_result();
            $employees = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();
            return $employees;
        }

        // Default — officer: only self
        $stmt = $this->db->prepare("
            SELECT e.id, e.employee_id, e.first_name, e.last_name, e.surname,
                   e.employment_type, e.designation,
                   d.name AS department_name,
                   s.name AS section_name,
                   ss.name AS subsection_name
            FROM employees e
            LEFT JOIN departments d  ON d.id = e.department_id
            LEFT JOIN sections s     ON s.id = e.section_id
            LEFT JOIN subsections ss ON ss.id = e.subsection_id
            WHERE e.id = ? AND e.employee_status = 'active'
        ");
        $stmt->bind_param('i', $currentEmployeeRecordId);
        $stmt->execute();
        $result = $stmt->get_result();
        $employees = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $employees;
    }

    /**
     * Verify that the current user is authorised to view the leave
     * profile of the given employee.
     */
    public function canViewProfile(int $targetEmployeeId): bool
    {
        $auth = Auth::getInstance();
        $userId = $auth->id();
        if (!$userId) {
            return false;
        }

        $currentUser = $auth->user();
        $role = strtolower($currentUser['role'] ?? '');

        // HR / Super Admin / MD can view anyone
        if (in_array($role, self::HR_ROLES, true)) {
            return true;
        }

        // Resolve the current user's employee record ID
        $currentEmployeeRecordId = $this->resolveEmployeeRecordId($currentUser['employee_id'] ?? null);

        // Officers can only view their own profile
        if ($role === 'officer') {
            return $currentEmployeeRecordId == $targetEmployeeId;
        }

        // Section/sub-section/department heads — scope check
        $stmt = $this->db->prepare("
            SELECT e.department_id, e.section_id, e.subsection_id
            FROM employees e
            WHERE e.id = ? AND e.employee_status = 'active'
        ");
        $stmt->bind_param('i', $targetEmployeeId);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$target) {
            return false;
        }

        $stmt = $this->db->prepare("
            SELECT e.department_id, e.section_id, e.subsection_id
            FROM employees e
            WHERE e.id = ? AND e.employee_status = 'active'
        ");
        $stmt->bind_param('i', $currentEmployeeRecordId);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$current) {
            return false;
        }

        if ($role === 'sub_section_head') {
            return ($current['subsection_id'] ?? null) && ($current['subsection_id'] == $target['subsection_id']);
        }
        if ($role === 'section_head') {
            return ($current['section_id'] ?? null) && ($current['section_id'] == $target['section_id']);
        }
        if ($role === 'dept_head') {
            return ($current['department_id'] ?? null) && ($current['department_id'] == $target['department_id']);
        }

        return false;
    }

    /**
     * Resolve the employee record ID (employees.id) from the session's
     * employee_id value, which may be either the integer primary key or
     * the employee's string ID (e.g. "EMP00125").
     */
    private function resolveEmployeeRecordId($employeeIdValue): int
    {
        if (empty($employeeIdValue)) {
            return 0;
        }

        // If it's already an integer, use it directly
        if (is_numeric($employeeIdValue)) {
            return (int) $employeeIdValue;
        }

        // Otherwise, look up the employee by their string employee_id
        $stmt = $this->db->prepare("SELECT id FROM employees WHERE employee_id = ? LIMIT 1");
        $stmt->bind_param('s', $employeeIdValue);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result ? (int) $result['id'] : 0;
    }

    // ───────────────────────────────────────────────────────────────────
    //  Employee profile
    // ───────────────────────────────────────────────────────────────────

    /**
     * Get full employee profile information including department,
     * section, subsection and designation.
     */
    public function getEmployeeProfile(int $employeeId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT e.*,
                   d.name AS department_name,
                   s.name AS section_name,
                   ss.name AS subsection_name,
                   o.name AS office_name
            FROM employees e
            LEFT JOIN departments d  ON d.id = e.department_id
            LEFT JOIN sections s     ON s.id = e.section_id
            LEFT JOIN subsections ss ON ss.id = e.subsection_id
            LEFT JOIN offices o      ON o.id = e.office_id
            WHERE e.id = ?
        ");
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $employee = $result->fetch_assoc();
        $stmt->close();
        return $employee ?: null;
    }

    // ───────────────────────────────────────────────────────────────────
    //  Financial years
    // ───────────────────────────────────────────────────────────────────

    /**
     * Get all financial years, ordered by start date descending.
     * The first row is the current / most recent financial year.
     *
     * @return array<int, array>
     */
    public function getFinancialYears(): array
    {
        $result = $this->db->query("
            SELECT id, year_name, start_date, end_date, is_active
            FROM financial_years
            ORDER BY start_date DESC
        ");
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    /**
     * Get the current (active) financial year ID.
     */
    public function getCurrentFinancialYearId(): int
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM financial_years WHERE end_date >= CURDATE() ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        if ($result) {
            return (int) $result['id'];
        }
        $stmt = $this->db->prepare("SELECT id FROM financial_years ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ? (int) $result['id'] : 0;
    }

    // ───────────────────────────────────────────────────────────────────
    //  Leave types
    // ───────────────────────────────────────────────────────────────────

    /**
     * Get all active leave types.
     *
     * @return array<int, array>
     */
    public function getLeaveTypes(): array
    {
        $result = $this->db->query("
            SELECT id, name, description, is_active, counts_weekends,
                   count_holidays, deducted_from_annual, created_at, updated_at
            FROM leave_types
            WHERE is_active = 1
            ORDER BY name
        ");
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    /**
     * Get a single leave type by ID.
     */
    public function getLeaveType(int $leaveTypeId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM leave_types WHERE id = ? AND is_active = 1");
        $stmt->bind_param('i', $leaveTypeId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result ?: null;
    }

    // ───────────────────────────────────────────────────────────────────
    //  Leave balances
    // ───────────────────────────────────────────────────────────────────

    /**
     * Get leave balances for an employee in a specific financial year,
     * joined with leave type information.
     *
     * @return array<int, array>
     */
    public function getLeaveBalances(int $employeeId, int $financialYearId): array
    {
        $stmt = $this->db->prepare("
            SELECT elb.*,
                   lt.name               AS leave_type_name,
                   lt.counts_weekends,
                   lt.count_holidays,
                   lt.deducted_from_annual
            FROM employee_leave_balances elb
            JOIN leave_types lt ON elb.leave_type_id = lt.id
            WHERE elb.employee_id = ?
              AND elb.financial_year_id = ?
              AND lt.is_active = 1
            ORDER BY lt.name
        ");
        $stmt->bind_param('ii', $employeeId, $financialYearId);
        $stmt->execute();
        $result = $stmt->get_result();
        $balances = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        // Cast numeric fields
        foreach ($balances as &$b) {
            $b['allocated_days']       = (float) ($b['allocated_days'] ?? 0);
            $b['brought_forward_days'] = (float) ($b['brought_forward_days'] ?? 0);
            $b['accumulated_days']     = (float) ($b['accumulated_days'] ?? 0);
            $b['used_days']            = (float) ($b['used_days'] ?? 0);
            $b['remaining_days']       = (float) ($b['remaining_days'] ?? 0);
        }
        unset($b);

        return $balances;
    }

    // ───────────────────────────────────────────────────────────────────
    //  Leave applications
    // ───────────────────────────────────────────────────────────────────

    /**
     * Get leave applications for an employee in a specific financial year,
     * with optional filters.
     *
     * Applications are scoped to the FY by their start_date falling inside
     * the financial_years window, NOT by la.financial_year_id: the live data
     * contains applications tagged with a financial_year_id that no longer
     * exists in financial_years (e.g. id 39), which made them invisible for
     * every FY the UI can select.  Falling back to the stored column only if
     * the requested FY id itself cannot be resolved.
     *
     * @param array $filters Keys: status, leave_type_id, date_from, date_to
     * @return array<int, array>
     */
    public function getLeaveApplications(int $employeeId, int $financialYearId, array $filters = []): array
    {
        // Resolve the FY window once; fall back to the legacy stored column
        // only when the requested FY id does not exist in financial_years.
        $fyStmt = $this->db->prepare("SELECT start_date, end_date FROM financial_years WHERE id = ?");
        $fyStmt->bind_param('i', $financialYearId);
        $fyStmt->execute();
        $fyWindow = $fyStmt->get_result()->fetch_assoc();
        $fyStmt->close();

        $where   = 'la.employee_id = ?';
        $params  = [$employeeId];
        $types   = 'i';

        if ($fyWindow) {
            $where  .= ' AND la.start_date >= ? AND la.start_date <= ?';
            $params[] = $fyWindow['start_date'];
            $params[] = $fyWindow['end_date'];
            $types   .= 'ss';
        } else {
            $where  .= ' AND la.financial_year_id = ?';
            $params[] = $financialYearId;
            $types   .= 'i';
        }

        if (!empty($filters['status'])) {
            $where .= " AND la.status = ?";
            $params[] = $filters['status'];
            $types .= 's';
        }

        if (!empty($filters['leave_type_id'])) {
            $where .= " AND la.leave_type_id = ?";
            $params[] = (int) $filters['leave_type_id'];
            $types .= 'i';
        }

        if (!empty($filters['date_from'])) {
            $where .= " AND la.start_date >= ?";
            $params[] = $filters['date_from'];
            $types .= 's';
        }

        if (!empty($filters['date_to'])) {
            $where .= " AND la.end_date <= ?";
            $params[] = $filters['date_to'];
            $types .= 's';
        }

        $sql = "
            SELECT la.*,
                   lt.name               AS leave_type_name,
                   lt.counts_weekends,
                   lt.count_holidays,
                   lt.deducted_from_annual,
                   e.first_name          AS emp_first_name,
                   e.last_name           AS emp_last_name,
                   e.employee_id         AS emp_employee_id,
                   u.first_name          AS user_first_name,
                   u.last_name           AS user_last_name
            FROM leave_applications la
            LEFT JOIN leave_types lt ON la.leave_type_id = lt.id
            LEFT JOIN employees e    ON la.employee_id = e.id
            LEFT JOIN users u        ON la.applied_by_user_id = u.id
            WHERE {$where}
            ORDER BY la.start_date ASC, la.applied_at ASC
        ";

        $stmt = $this->db->prepare($sql);
        // Use call_user_func_array with references to avoid PHP bind_param
        // "expected to be a reference" errors when using the spread operator.
        $bindParams = [$types];
        foreach ($params as $key => $value) {
            $bindParams[] = &$params[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $bindParams);
        $stmt->execute();
        $result = $stmt->get_result();
        $applications = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        return $applications;
    }

    // ───────────────────────────────────────────────────────────────────
    //  Balance timeline (buildBalanceTimeline)
    // ───────────────────────────────────────────────────────────────────

    /**
     * Build a chronological balance timeline for an employee in a
     * specific financial year.
     *
     * This is the core leave-accounting logic.  It reconstructs the
     * running balance by processing applications chronologically,
     * considering:
     *
     *   - Allocated days
     *   - Brought-forward days
     *   - Accumulated days
     *   - Approved applications (primary / annual / unpaid deductions)
     *   - Claim a Day credits (adds to Annual Leave)
     *   - Leave of Absence (informational, no deduction)
     *   - Legacy records (where primary_days / annual_days / unpaid_days
     *     may be NULL — reconstructed from available data)
     *
     * Only APPROVED applications affect the balance.  Pending and
     * rejected applications are included in the timeline but flagged
     * as non-deducting.
     *
     * @return array{
     *   opening_balances: array,
     *   movements: array<int, array>,
     *   closing_balances: array,
     *   pending_count: int,
     *   approved_count: int,
     *   rejected_count: int
     * }
     */
    public function buildBalanceTimeline(int $employeeId, int $financialYearId): array
    {
        $balances = $this->getLeaveBalances($employeeId, $financialYearId);
        $applications = $this->getLeaveApplications($employeeId, $financialYearId);
        $leaveTypes = $this->getLeaveTypes();

        // Index balances by leave_type_id for quick lookup
        $balanceMap = [];
        foreach ($balances as $b) {
            $balanceMap[$b['leave_type_id']] = $b;
        }

        // Index leave types by ID
        $typeMap = [];
        foreach ($leaveTypes as $t) {
            $typeMap[$t['id']] = $t;
        }

        // Determine the annual leave type ID
        $annualTypeId = $this->getAnnualLeaveTypeId();

        // ── Opening balances ──────────────────────────────────────────
        $openingBalances = [];
        foreach ($balances as $b) {
            $openingBalances[$b['leave_type_id']] = [
                'leave_type_id'   => $b['leave_type_id'],
                'leave_type_name' => $b['leave_type_name'],
                'allocated'       => $b['allocated_days'],
                'brought_forward' => $b['brought_forward_days'],
                'accumulated'     => $b['accumulated_days'],
                'used'            => $b['used_days'],
                'remaining'       => $b['remaining_days'],
                'total_available' => $b['allocated_days'] + $b['brought_forward_days'] + $b['accumulated_days'],
            ];
        }

        // ── Process applications chronologically ──────────────────────
        // Sort by start_date, then applied_at
        usort($applications, function ($a, $b) {
            $cmp = strcmp($a['start_date'] ?? '', $b['start_date'] ?? '');
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp($a['applied_at'] ?? '', $b['applied_at'] ?? '');
        });

        $movements = [];
        $pendingCount = 0;
        $approvedCount = 0;
        $rejectedCount = 0;

        // Track running balances per leave type (start from opening)
        $runningBalances = [];
        foreach ($openingBalances as $ltId => $ob) {
            $runningBalances[$ltId] = $ob['remaining'];
        }

        foreach ($applications as $app) {
            $appId = (int) $app['id'];
            $leaveTypeId = (int) $app['leave_type_id'];
            $leaveTypeName = $app['leave_type_name'] ?? 'Unknown';
            $status = $app['status'] ?? '';
            $startDate = $app['start_date'] ?? '';
            $endDate = $app['end_date'] ?? '';
            $daysRequested = (int) ($app['days_requested'] ?? 0);
            $appliedAt = $app['applied_at'] ?? '';
            $approvedAt = $app['approved_at'] ?? null;
            $approvedBy = $app['approved_by'] ?? null;
            $appliedBy = $app['applied_by_user_id'] ?? null;
            $reason = $app['reason'] ?? '';

            // Count statuses
            if (str_starts_with($status, 'pending') || $status === 'pending') {
                $pendingCount++;
            } elseif ($status === 'approved') {
                $approvedCount++;
            } elseif ($status === 'rejected') {
                $rejectedCount++;
            }

            $isApproved = ($status === 'approved');
            $isLegacy = false;

            // ── Determine deduction breakdown ────────────────────────
            $primaryDays = $app['primary_days'] ?? null;
            $annualDays = $app['annual_days'] ?? null;
            $unpaidDays = $app['unpaid_days'] ?? null;

            // Check if this is a legacy record (primary_days / annual_days / unpaid_days are NULL)
            if ($primaryDays === null && $annualDays === null && $unpaidDays === null) {
                $isLegacy = true;
                // Reconstruct the breakdown based on leave type and available data
                $reconstruction = $this->reconstructLegacyDeduction(
                    $app, $leaveTypeId, $daysRequested, $annualTypeId, $balanceMap
                );
                $primaryDays = $reconstruction['primary_days'];
                $annualDays = $reconstruction['annual_days'];
                $unpaidDays = $reconstruction['unpaid_days'];
            }

            // Ensure numeric values
            $primaryDays = (float) ($primaryDays ?? 0);
            $annualDays = (float) ($annualDays ?? 0);
            $unpaidDays = (float) ($unpaidDays ?? 0);

            // ── Build movement entries ───────────────────────────────
            $movementDate = $startDate;

            // Special handling for Claim a Day (id 9) — credits Annual Leave
            if ($leaveTypeId === self::LEAVE_TYPE_CLAIM_A_DAY) {
                if ($isApproved) {
                    $beforeAnnual = $runningBalances[$annualTypeId] ?? 0;
                    $runningBalances[$annualTypeId] = $beforeAnnual + $daysRequested;
                    $movements[] = [
                        'application_id'   => $appId,
                        'leave_type_id'    => $annualTypeId,
                        'leave_type_name'  => $typeMap[$annualTypeId]['name'] ?? 'Annual Leave',
                        'movement_type'    => 'CREDIT',
                        'days'             => $daysRequested,
                        'balance_before'   => $beforeAnnual,
                        'balance_after'    => $runningBalances[$annualTypeId],
                        'explanation'      => "Claim a Day — {$daysRequested} day(s) credited to Annual Leave",
                        'date'             => $movementDate,
                        'status'           => $status,
                        'is_approved'      => true,
                        'is_legacy'        => false,
                        'application'      => $app,
                    ];
                } else {
                    // Non-approved Claim a Day — informational only
                    $movements[] = [
                        'application_id'   => $appId,
                        'leave_type_id'    => $annualTypeId,
                        'leave_type_name'  => $typeMap[$annualTypeId]['name'] ?? 'Annual Leave',
                        'movement_type'    => 'CREDIT',
                        'days'             => $daysRequested,
                        'balance_before'   => $runningBalances[$annualTypeId] ?? 0,
                        'balance_after'    => $runningBalances[$annualTypeId] ?? 0,
                        'explanation'      => "Claim a Day — pending approval, no credit applied yet",
                        'date'             => $movementDate,
                        'status'           => $status,
                        'is_approved'      => false,
                        'is_legacy'        => false,
                        'application'      => $app,
                    ];
                }
                continue;
            }

            // Special handling for Leave of Absence (id 8) — no balance deduction
            if ($leaveTypeId === self::LEAVE_TYPE_ABSENCE) {
                $movements[] = [
                    'application_id'   => $appId,
                    'leave_type_id'    => $leaveTypeId,
                    'leave_type_name'  => $leaveTypeName,
                    'movement_type'    => 'INFO',
                    'days'             => $daysRequested,
                    'balance_before'   => $runningBalances[$leaveTypeId] ?? 0,
                    'balance_after'    => $runningBalances[$leaveTypeId] ?? 0,
                    'explanation'      => "Leave of Absence — {$daysRequested} day(s), no balance deduction",
                    'date'             => $movementDate,
                    'status'           => $status,
                    'is_approved'      => $isApproved,
                    'is_legacy'        => $isLegacy,
                    'application'      => $app,
                ];
                continue;
            }

            // ── Normal leave types: process deductions ───────────────
            if (!$isApproved) {
                // Pending / rejected — show in timeline but no deduction
                $movements[] = [
                    'application_id'   => $appId,
                    'leave_type_id'    => $leaveTypeId,
                    'leave_type_name'  => $leaveTypeName,
                    'movement_type'    => 'PENDING',
                    'days'             => $daysRequested,
                    'balance_before'   => $runningBalances[$leaveTypeId] ?? 0,
                    'balance_after'    => $runningBalances[$leaveTypeId] ?? 0,
                    'explanation'      => $this->getStatusExplanation($status, $daysRequested, $leaveTypeName),
                    'date'             => $movementDate,
                    'status'           => $status,
                    'is_approved'      => false,
                    'is_legacy'        => $isLegacy,
                    'application'      => $app,
                ];
                continue;
            }

            // Approved application — apply deductions
            $balanceBefore = $runningBalances[$leaveTypeId] ?? 0;

            // Primary deduction (from this leave type's balance)
            if ($primaryDays > 0) {
                $runningBalances[$leaveTypeId] = $balanceBefore - $primaryDays;
                $movements[] = [
                    'application_id'   => $appId,
                    'leave_type_id'    => $leaveTypeId,
                    'leave_type_name'  => $leaveTypeName,
                    'movement_type'    => 'DEDUCT',
                    'days'             => $primaryDays,
                    'balance_before'   => $balanceBefore,
                    'balance_after'    => $runningBalances[$leaveTypeId],
                    'explanation'      => "{$leaveTypeName} used — {$primaryDays} day(s) deducted",
                    'date'             => $movementDate,
                    'status'           => $status,
                    'is_approved'      => true,
                    'is_legacy'        => $isLegacy,
                    'application'      => $app,
                ];
                $balanceBefore = $runningBalances[$leaveTypeId];
            }

            // Annual leave deduction (if this leave type deducts from annual)
            if ($annualDays > 0 && isset($runningBalances[$annualTypeId])) {
                $annualBefore = $runningBalances[$annualTypeId];
                $runningBalances[$annualTypeId] = $annualBefore - $annualDays;
                $movements[] = [
                    'application_id'   => $appId,
                    'leave_type_id'    => $annualTypeId,
                    'leave_type_name'  => $typeMap[$annualTypeId]['name'] ?? 'Annual Leave',
                    'movement_type'    => 'DEDUCT',
                    'days'             => $annualDays,
                    'balance_before'   => $annualBefore,
                    'balance_after'    => $runningBalances[$annualTypeId],
                    'explanation'      => "Annual Leave used — {$annualDays} day(s) deducted (from {$leaveTypeName})",
                    'date'             => $movementDate,
                    'status'           => $status,
                    'is_approved'      => true,
                    'is_legacy'        => $isLegacy,
                    'application'      => $app,
                ];
            }

            // Unpaid days (informational — no balance change)
            if ($unpaidDays > 0) {
                $movements[] = [
                    'application_id'   => $appId,
                    'leave_type_id'    => $leaveTypeId,
                    'leave_type_name'  => $leaveTypeName,
                    'movement_type'    => 'UNPAID',
                    'days'             => $unpaidDays,
                    'balance_before'   => $runningBalances[$leaveTypeId] ?? 0,
                    'balance_after'    => $runningBalances[$leaveTypeId] ?? 0,
                    'explanation'      => "{$unpaidDays} day(s) unpaid due to insufficient balance",
                    'date'             => $movementDate,
                    'status'           => $status,
                    'is_approved'      => true,
                    'is_legacy'        => $isLegacy,
                    'application'      => $app,
                ];
            }
        }

        // ── Closing balances ──────────────────────────────────────────
        $closingBalances = [];
        foreach ($openingBalances as $ltId => $ob) {
            $closingBalances[$ltId] = [
                'leave_type_id'   => $ltId,
                'leave_type_name' => $ob['leave_type_name'],
                'allocated'       => $ob['allocated'],
                'brought_forward' => $ob['brought_forward'],
                'accumulated'     => $ob['accumulated'],
                'used'            => $ob['used'],
                'remaining'       => $runningBalances[$ltId] ?? $ob['remaining'],
                'total_available' => $ob['total_available'],
            ];
        }

        return [
            'opening_balances'  => $openingBalances,
            'movements'         => $movements,
            'closing_balances'  => $closingBalances,
            'pending_count'     => $pendingCount,
            'approved_count'    => $approvedCount,
            'rejected_count'    => $rejectedCount,
        ];
    }

    /**
     * Reconstruct the deduction breakdown for legacy records where
     * primary_days, annual_days, and unpaid_days are NULL.
     *
     * The reconstruction follows the same rules as
     * LeaveCalculationService::calculateDeductionFromBalances():
     *   1. Deduct from the primary leave type's balance first.
     *   2. If the leave type has deducted_from_annual=1 and there are
     *      remaining days, deduct from Annual Leave.
     *   3. Any remaining days are unpaid.
     */
    private function reconstructLegacyDeduction(
        array $app,
        int $leaveTypeId,
        int $daysRequested,
        int $annualTypeId,
        array $balanceMap
    ): array {
        $primaryDays = 0;
        $annualDays = 0;
        $unpaidDays = 0;

        $balance = $balanceMap[$leaveTypeId] ?? null;
        $remainingPrimary = (float) ($balance['remaining_days'] ?? 0);

        if ($remainingPrimary > 0) {
            $primaryDays = min($daysRequested, $remainingPrimary);
        }

        $remaining = $daysRequested - $primaryDays;

        if ($remaining > 0) {
            $leaveType = $this->getLeaveType($leaveTypeId);
            $deductedFromAnnual = (int) ($leaveType['deducted_from_annual'] ?? 0);

            if ($deductedFromAnnual) {
                $annualBalance = $balanceMap[$annualTypeId] ?? null;
                $availableAnnual = (float) ($annualBalance['remaining_days'] ?? 0);
                $annualDays = min($availableAnnual, $remaining);
                $remaining -= $annualDays;
            }

            if ($remaining > 0) {
                $unpaidDays = $remaining;
            }
        }

        return [
            'primary_days' => $primaryDays,
            'annual_days'  => $annualDays,
            'unpaid_days'  => $unpaidDays,
        ];
    }

    /**
     * Get a human-readable explanation for a non-approved application.
     */
    private function getStatusExplanation(string $status, int $days, string $leaveTypeName): string
    {
        if (str_starts_with($status, 'pending')) {
            return "Pending approval — balances will be deducted once fully approved.";
        }
        if ($status === 'rejected') {
            return "Rejected — no balance deduction applied.";
        }
        if ($status === 'cancelled') {
            return "Cancelled — no balance deduction applied.";
        }
        return "{$leaveTypeName} — {$days} day(s) — status: {$status}";
    }

    /**
     * Get the annual leave type ID.
     */
    private function getAnnualLeaveTypeId(): int
    {
        $stmt = $this->db->prepare("SELECT id FROM leave_types WHERE name LIKE '%annual%' LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ? (int) $result['id'] : 1;
    }

    // ───────────────────────────────────────────────────────────────────
    //  Summary statistics
    // ───────────────────────────────────────────────────────────────────

    /**
     * Get summary statistics for an employee's leave account.
     */
    public function getSummaryStatistics(int $employeeId, int $financialYearId): array
    {
        $balances = $this->getLeaveBalances($employeeId, $financialYearId);
        $timeline = $this->buildBalanceTimeline($employeeId, $financialYearId);

        $totalLeaveTypes = count($balances);
        $totalAllocated = 0;
        $totalBroughtForward = 0;
        $totalUsed = 0;
        $totalRemaining = 0;

        foreach ($balances as $b) {
            // Only count leave types that have allocated days (i.e., balance-tracked types)
            if ((float) $b['allocated_days'] > 0 || (float) $b['remaining_days'] > 0) {
                $totalAllocated += (float) $b['allocated_days'];
                $totalBroughtForward += (float) $b['brought_forward_days'];
                $totalUsed += (float) $b['used_days'];
                $totalRemaining += (float) $b['remaining_days'];
            }
        }

        return [
            'total_leave_types'      => $totalLeaveTypes,
            'total_allocated_days'   => round($totalAllocated, 2),
            'total_brought_forward'  => round($totalBroughtForward, 2),
            'total_used_days'        => round($totalUsed, 2),
            'total_remaining_days'   => round($totalRemaining, 2),
            'pending_applications'   => $timeline['pending_count'],
            'approved_applications'  => $timeline['approved_count'],
            'rejected_applications'  => $timeline['rejected_count'],
        ];
    }

    // ───────────────────────────────────────────────────────────────────
    //  Full profile (composite)
    // ───────────────────────────────────────────────────────────────────

    /**
     * Get the complete leave profile for an employee in a financial year.
     * This is the main endpoint data — everything the frontend needs.
     */
    public function getFullProfile(int $employeeId, int $financialYearId, array $filters = []): array
    {
        $employee = $this->getEmployeeProfile($employeeId);
        if (!$employee) {
            return ['success' => false, 'message' => 'Employee not found'];
        }

        $financialYears = $this->getFinancialYears();
        $currentFy = $this->getCurrentFinancialYearId();
        $selectedFy = $financialYears[0] ?? null;
        foreach ($financialYears as $fy) {
            if ((int) $fy['id'] === $financialYearId) {
                $selectedFy = $fy;
                break;
            }
        }

        $balances = $this->getLeaveBalances($employeeId, $financialYearId);
        $leaveTypes = $this->getLeaveTypes();
        $applications = $this->getLeaveApplications($employeeId, $financialYearId, $filters);
        $timeline = $this->buildBalanceTimeline($employeeId, $financialYearId);
        $summary = $this->getSummaryStatistics($employeeId, $financialYearId);

        return [
            'success'           => true,
            'employee'          => $employee,
            'financial_years'   => $financialYears,
            'current_fy_id'     => $currentFy,
            'selected_fy'     => $selectedFy,
            'leave_types'       => $leaveTypes,
            'balances'          => $balances,
            'applications'      => $applications,
            'timeline'          => $timeline,
            'summary'           => $summary,
            'filters'           => $filters,
        ];
    }

    // ───────────────────────────────────────────────────────────────────
    //  Export
    // ───────────────────────────────────────────────────────────────────

    /**
     * Get export data for the employee leave account.
     * Returns a structured array suitable for CSV / Excel / PDF generation.
     */
    public function getExportData(int $employeeId, int $financialYearId, array $filters = []): array
    {
        $profile = $this->getFullProfile($employeeId, $financialYearId, $filters);
        if (!$profile['success']) {
            return $profile;
        }

        $employee = $profile['employee'];
        $selectedFy = $profile['selected_fy'];
        $balances = $profile['balances'];
        $applications = $profile['applications'];
        $timeline = $profile['timeline'];

        $exportRows = [];

        // Balance summary rows
        foreach ($balances as $b) {
            $exportRows[] = [
                'section'         => 'Balance',
                'leave_type'      => $b['leave_type_name'],
                'allocated'       => $b['allocated_days'],
                'brought_forward' => $b['brought_forward_days'],
                'used'            => $b['used_days'],
                'remaining'       => $b['remaining_days'],
                'start_date'      => '',
                'end_date'        => '',
                'days_requested'  => '',
                'status'          => '',
                'balance_impact'  => '',
            ];
        }

        // Application rows
        foreach ($applications as $app) {
            $exportRows[] = [
                'section'         => 'Application',
                'leave_type'      => $app['leave_type_name'] ?? '',
                'allocated'       => '',
                'brought_forward' => '',
                'used'            => '',
                'remaining'       => '',
                'start_date'      => $app['start_date'] ?? '',
                'end_date'        => $app['end_date'] ?? '',
                'days_requested'  => $app['days_requested'] ?? 0,
                'status'          => $app['status'] ?? '',
                'balance_impact'  => $this->formatBalanceImpact($app),
            ];
        }

        // Timeline movement rows
        foreach ($timeline['movements'] as $m) {
            $exportRows[] = [
                'section'         => 'Movement',
                'leave_type'      => $m['leave_type_name'],
                'allocated'       => '',
                'brought_forward' => '',
                'used'            => $m['movement_type'] === 'DEDUCT' ? $m['days'] : '',
                'remaining'       => $m['balance_after'],
                'start_date'      => $m['date'],
                'end_date'        => '',
                'days_requested'  => $m['days'],
                'status'          => $m['status'],
                'balance_impact'  => $m['explanation'],
            ];
        }

        return [
            'success'       => true,
            'employee'      => [
                'name'            => trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? '')),
                'employee_id'     => $employee['employee_id'] ?? '',
                'employment_type' => $employee['employment_type'] ?? '',
                'department'      => $employee['department_name'] ?? '',
                'section'         => $employee['section_name'] ?? '',
                'subsection'      => $employee['subsection_name'] ?? '',
                'designation'     => $employee['designation'] ?? '',
            ],
            'financial_year' => $selectedFy ? $selectedFy['year_name'] : '',
            'export_rows'    => $exportRows,
            'summary'        => $profile['summary'],
        ];
    }

    /**
     * Format the balance impact for an application for export.
     */
    private function formatBalanceImpact(array $app): string
    {
        $parts = [];
        $primaryDays = (float) ($app['primary_days'] ?? 0);
        $annualDays = (float) ($app['annual_days'] ?? 0);
        $unpaidDays = (float) ($app['unpaid_days'] ?? 0);

        if ($primaryDays > 0) {
            $leaveTypeName = isset($app['leave_type_name']) ? $app['leave_type_name'] : 'primary';
            $parts[] = "-{$primaryDays} {$leaveTypeName}";
        }
        if ($annualDays > 0) {
            $parts[] = "-{$annualDays} Annual";
        }
        if ($unpaidDays > 0) {
            $parts[] = "{$unpaidDays} unpaid";
        }

        return $parts ? implode(', ', $parts) : 'No impact';
    }
}

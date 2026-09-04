<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * OrgScope - Resolves the logged-in user's organisational scope (department,
 * section, subsection) from the session / employee record, and exposes the
 * management rules used across the Strategy & Performance module.
 *
 * This mirrors the scope-resolving logic the legacy strategic plan page
 * implemented (employees -> department/section/subsection, plus the PME/Audit
 * department-head special case) as a single, reusable helper so every
 * controller reads the SAME hierarchy and the SAME permission rules.
 */
final class OrgScope
{
    private const PME_DEPARTMENT_ID  = 7; // PME leadership manages contracts/strategy
    private const AUDIT_DEPARTMENT_ID = 6; // Audit leadership has organisation-wide visibility
    private const PME_DEPARTMENT_IDS = [6, 7];

    /**
     * Current user's resolved organisational scope.
     */
    public static function current(): array
    {
        // Ensure the session actually reflects the caller's JWT (Authorization
        // header or access-token cookie). Direct API calls can arrive with a
        // cold PHP session; without this the scope would resolve empty even
        // for perfectly valid department / section / subsection heads.
        try {
            \App\Helpers\Auth::getInstance()->check();
        } catch (\Throwable $e) {
            // Leave the scope unauthenticated - every consumer enforces its
            // own permission gate right after resolving the scope.
        }

        $role         = (string)($_SESSION['user_role'] ?? '');
        $userId       = (int) ($_SESSION['user_id'] ?? 0);
        $employeeCode = isset($_SESSION['employee_id']) ? (string) $_SESSION['employee_id'] : null;

        $scope = [
            'user_id'             => $userId,
            'role'                => $role,
            'employee_id'         => $employeeCode,
            'department_id'       => null,
            'department_name'     => null,
            'section_id'          => null,
            'subsection_id'       => null,
            'is_hr'               => $role === 'hr_manager',
            'is_super_admin'      => $role === 'super_admin',
            'is_dept_head'        => $role === 'dept_head',
            'is_section_head'     => $role === 'section_head',
            'is_sub_section_head' => $role === 'sub_section_head',
            'is_manager'          => in_array($role, ['manager', 'managing_director'], true),
            'is_pme_or_audit'     => false,
            // Legacy department-specific leadership flags (PME=7 manages,
            // Audit=6 has organisation-wide visibility only).
            'is_pme_head'         => false,
            'is_audit_head'       => false,
        ];

        $conn = Database::getInstance()->getConnection();
        $empCode = null;
        if ($scope['employee_id'] !== null && $scope['employee_id'] !== '') {
            $empCode = $scope['employee_id'];
        } else {
            $email = isset($_SESSION['user_email']) ? (string) $_SESSION['user_email'] : '';
            if ($email !== '') {
                $q = $conn->prepare("SELECT employee_id FROM users WHERE id = ? LIMIT 1");
                if ($q) {
                    $q->bind_param('i', $userId);
                    $q->execute();
                    $r = $q->get_result()->fetch_assoc();
                    $q->close();
                    if ($r && !empty($r['employee_id'])) {
                        $empCode = (string) $r['employee_id'];
                        $scope['employee_id'] = $empCode;
                    }
                }
            }
        }

        // Resolve the organisational unit from the employee record. First try
        // the employee CODE (users.employee_id / JWT claim); if the account has
        // no code linked, fall back to matching the employee EMAIL so accounts
        // created without an explicit employee reference still resolve.
        $empQuery = "
            SELECT e.department_id, e.section_id, e.subsection_id, d.name AS department_name
            FROM employees e
            LEFT JOIN departments d ON e.department_id = d.id
            WHERE e.employee_status = 'active' AND e.employee_id = ?
            LIMIT 1
        ";
        $stmt = $conn->prepare($empQuery);
        $row = null;
        if ($stmt && $empCode !== null) {
            $stmt->bind_param('s', $empCode);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        if (!$row) {
            $email = isset($_SESSION['user_email']) ? (string) $_SESSION['user_email'] : '';
            if ($email !== '') {
                $emailQuery = "
                    SELECT e.department_id, e.section_id, e.subsection_id, d.name AS department_name
                    FROM employees e
                    LEFT JOIN departments d ON e.department_id = d.id
                    WHERE e.employee_status = 'active' AND e.email = ?
                    ORDER BY e.id ASC
                    LIMIT 1
                ";
                $eStmt = $conn->prepare($emailQuery);
                if ($eStmt) {
                    $eStmt->bind_param('s', $email);
                    $eStmt->execute();
                    $row = $eStmt->get_result()->fetch_assoc();
                    $eStmt->close();
                }
            }
        }

        if ($row) {
            $scope['department_id']   = $row['department_id'] !== null ? (int) $row['department_id'] : null;
            $scope['section_id']      = $row['section_id'] !== null ? (int) $row['section_id'] : null;
            $scope['subsection_id']   = $row['subsection_id'] !== null ? (int) $row['subsection_id'] : null;
            $scope['department_name'] = $row['department_name'];

            if ($scope['department_id'] !== null && in_array($scope['department_id'], self::PME_DEPARTMENT_IDS, true)) {
                $scope['is_pme_or_audit'] = true;
                if ($scope['department_id'] === self::PME_DEPARTMENT_ID) {
                    $scope['is_pme_head'] = true;
                } elseif ($scope['department_id'] === self::AUDIT_DEPARTMENT_ID) {
                    $scope['is_audit_head'] = true;
                }
            }
        }

        return $scope;
    }

    /**
     * True when the user may create/edit/delete PERFORMANCE CONTRACTS.
     * Mirrors the legacy rule exactly: HR managers, super admins and the PME
     * department head manage contracts; the Audit department head has
     * organisation-wide visibility but does NOT manage them.
     */
    public static function canManageContracts(array $scope): bool
    {
        return $scope['is_hr'] || $scope['is_super_admin'] || $scope['is_pme_head'];
    }

    /**
     * True when the user may see ALL performance contracts regardless of
     * department (HR, super admin, PME head and Audit head).
     */
    public static function canViewAllContracts(array $scope): bool
    {
        return self::canManageContracts($scope) || $scope['is_audit_head'];
    }

    /**
     * True when the user may manage organisation-level strategic plans.
     */
    public static function canManageStrategicPlan(array $scope): bool
    {
        return $scope['is_hr'] || $scope['is_super_admin'] || $scope['is_pme_or_audit'];
    }

    /**
     * True when the user may manage department/section-level performance data
     * (contracts, workplans, KPIs, sectional objectives) within their scope.
     */
    public static function canManagePerformance(array $scope): bool
    {
        return $scope['is_hr'] || $scope['is_super_admin']
            || $scope['is_pme_or_audit']
            || $scope['is_dept_head']
            || $scope['is_section_head']
            || $scope['is_sub_section_head']
            || $scope['is_manager'];
    }

    /**
     * True when the user may see any part of the Strategy & Performance module.
     */
    public static function canViewAny(array $scope): bool
    {
        $role = $scope['role'];
        $viewerRoles = ['super_admin', 'hr_manager', 'dept_head', 'section_head',
            'sub_section_head', 'manager', 'managing_director', 'officer'];
        return in_array($role, $viewerRoles, true);
    }

    /**
     * Builds an SQL scope-clause (with bound params) restricting reads to the
     * user's own organisational unit. Broad-access roles see everything;
     * every other authenticated user is pinned to their own department /
     * section / subsection, and sees NOTHING if their unit cannot be resolved.
     *
     * @return array{string, array}
     */
    public static function scopeWhere(array $scope, array $columnMap = []): array
    {
        $dept = $scope['department_id'] ?? null;
        $sec  = $scope['section_id'] ?? null;
        $sub  = $scope['subsection_id'] ?? null;

        // Broad organisational access. (!empty() keeps this safe for callers
        // that construct partial scope arrays — e.g. tests / future callers.)
        if (!empty($scope['is_hr']) || !empty($scope['is_super_admin']) || !empty($scope['is_pme_or_audit'])) {
            return ['1=1', []];
        }

        $deptCol = $columnMap['department_id'] ?? 'department_id';
        $secCol  = $columnMap['section_id'] ?? 'section_id';
        $subCol  = $columnMap['subsection_id'] ?? 'subsection_id';

        $clauses = [];
        $params  = [];

        if (!empty($scope['is_sub_section_head']) || !empty($scope['is_section_head'])) {
            // Heads of smaller units are narrowed as far as the data allows,
            // but never wider than their own department.
            if ($sub !== null && $sec !== null && $dept !== null) {
                $clauses[] = "$deptCol = ?"; $params[] = (int) $dept;
                $clauses[] = "$secCol = ?";  $params[] = (int) $sec;
                $clauses[] = "$subCol = ?";  $params[] = (int) $sub;
            } elseif ($sec !== null && $dept !== null) {
                $clauses[] = "$deptCol = ?"; $params[] = (int) $dept;
                $clauses[] = "$secCol = ?";  $params[] = (int) $sec;
            } elseif ($dept !== null) {
                $clauses[] = "$deptCol = ?"; $params[] = (int) $dept;
            }
        } elseif ($dept !== null) {
            // Department heads, managers, officers and other staff: own
            // department only.
            $clauses[] = "$deptCol = ?";
            $params[]  = (int) $dept;
        }

        if (empty($clauses)) {
            // Unit could not be resolved - deny rather than expose organisation-wide data.
            return ['1=0', []];
        }

        return [implode(' AND ', $clauses), $params];
    }

    // =========================================================================
    // Attendance data scope (Phase: Role/Page/Permission restriction system)
    //
    // Permission (CAN open the attendance module) and DATA SCOPE (WHICH
    // attendance records) are separate axes. These helpers answer the scope
    // question for attendance reads and are consumed by AttendanceController
    // and AttendanceDashboardService. The authenticated session/employee
    // record is the ONLY input — request parameters are never trusted.
    // =========================================================================

    public const ATTENDANCE_SCOPE_ALL  = 'all';
    public const ATTENDANCE_SCOPE_UNIT = 'unit';
    public const ATTENDANCE_SCOPE_OWN  = 'own';

    /**
     * Attendance read mode for the given organisational scope.
     *
     *   'all'  — org-wide attendance: super admin, HR, Managing Director
     *            (oversight) and the PME/Audit leadership special case that
     *            OrgScope already treats as organisation-wide.
     *   'unit' — own organisational unit only: department / section /
     *            sub-section heads (narrowed as far as the data allows).
     *   'own'  — own attendance records ONLY: every other authenticated role
     *            (officer, employee, ...). The default.
     */
    public static function attendanceReadMode(array $scope): string
    {
        $role = (string)($scope['role'] ?? '');
        if (
            !empty($scope['is_super_admin']) || !empty($scope['is_hr'])
            || $role === 'managing_director' || !empty($scope['is_pme_or_audit'])
        ) {
            return self::ATTENDANCE_SCOPE_ALL;
        }
        if (
            !empty($scope['is_dept_head']) || !empty($scope['is_section_head'])
            || !empty($scope['is_sub_section_head'])
        ) {
            return self::ATTENDANCE_SCOPE_UNIT;
        }
        return self::ATTENDANCE_SCOPE_OWN;
    }

    /**
     * Describe the caller's attendance scope as a plain array (also used by
     * the permission management UI and tests).
     */
    public static function attendanceScope(array $scope, ?int $ownEmployeeDbId): array
    {
        $mode = self::attendanceReadMode($scope);
        return [
            'mode'           => $mode,
            'employee_db_id' => $mode === self::ATTENDANCE_SCOPE_OWN ? $ownEmployeeDbId : null,
            'department_id'  => $scope['department_id'] ?? null,
            'section_id'     => $scope['section_id'] ?? null,
            'subsection_id'  => $scope['subsection_id'] ?? null,
        ];
    }

    /**
     * SQL WHERE clause (with bound params) restricting an attendance query to
     * the caller's data scope. Queries must alias the attendance table as
     * `a` and the employees table as `e` (or pass a column map).
     *
     *  - all  → '1=1' (no restriction)
     *  - own  → a.employee_id = <own employee id>; unresolvable identity → deny
     *  - unit → department/section/subsection narrowing via scopeWhere();
     *           unresolvable unit → deny ('1=0')
     *
     * @return array{0:string, 1:array} [whereClause, params] (all params int)
     */
    public static function attendanceWhere(array $scope, ?int $ownEmployeeDbId, array $columnMap = []): array
    {
        $mode = self::attendanceReadMode($scope);

        if ($mode === self::ATTENDANCE_SCOPE_ALL) {
            return ['1=1', []];
        }

        $empCol = $columnMap['employee_id'] ?? 'a.employee_id';

        if ($mode === self::ATTENDANCE_SCOPE_OWN) {
            if ($ownEmployeeDbId === null || $ownEmployeeDbId <= 0) {
                // Identity could not be resolved - deny rather than expose data.
                return ['1=0', []];
            }
            return ["{$empCol} = ?", [(int) $ownEmployeeDbId]];
        }

        // Unit mode — heads. scopeWhere() narrows sub-section/section heads to
        // their own unit and every other unit-resolvable caller to their own
        // department, returning '1=0' when the unit cannot be resolved.
        return self::scopeWhere($scope, $columnMap);
    }

    /**
     * True when the caller may read a SPECIFIC employee's attendance record
     * (IDOR guard for /attendance/employee/{id} and /attendance/hr-employee-
     * history). Mirrors attendanceWhere() exactly so an employee excluded
     * from list reads can never be read through the by-employee endpoints.
     *
     * @param array $scope               OrgScope::current() result for the caller
     * @param int|null $ownEmployeeDbId  Caller's own employees.id (may be null)
     * @param array $targetEmployee      Target row: id, department_id, section_id, subsection_id
     */
    public static function attendanceEmployeeAllowed(
        array $scope,
        ?int $ownEmployeeDbId,
        array $targetEmployee
    ): bool {
        $mode = self::attendanceReadMode($scope);

        if ($mode === self::ATTENDANCE_SCOPE_ALL) {
            return true;
        }

        if ($mode === self::ATTENDANCE_SCOPE_OWN) {
            return $ownEmployeeDbId !== null
                && $ownEmployeeDbId > 0
                && (int)($targetEmployee['id'] ?? 0) === $ownEmployeeDbId;
        }

        // Unit mode — same narrowing ladder as scopeWhere().
        $dept = $scope['department_id'] ?? null;
        $sec  = $scope['section_id'] ?? null;
        $sub  = $scope['subsection_id'] ?? null;

        $tDept = isset($targetEmployee['department_id']) && $targetEmployee['department_id'] !== null
            ? (int) $targetEmployee['department_id'] : null;
        $tSec  = isset($targetEmployee['section_id']) && $targetEmployee['section_id'] !== null
            ? (int) $targetEmployee['section_id'] : null;
        $tSub  = isset($targetEmployee['subsection_id']) && $targetEmployee['subsection_id'] !== null
            ? (int) $targetEmployee['subsection_id'] : null;

        if ($sub !== null && $sec !== null && $dept !== null) {
            return $tDept === (int) $dept && $tSec === (int) $sec && $tSub === (int) $sub;
        }
        if ($sec !== null && $dept !== null) {
            return $tDept === (int) $dept && $tSec === (int) $sec;
        }
        if ($dept !== null) {
            return $tDept === (int) $dept;
        }

        // Unit could not be resolved - deny.
        return false;
    }

    /**
     * Resolve the organisational scope of an ARBITRARY user (not the session
     * user). Used by the permission management UI to display the target
     * user's organisational scope next to their effective permissions.
     * Read-only and defensive: never throws.
     */
    public static function forUser(int $userId): array
    {
        $scope = [
            'user_id'         => $userId,
            'role'            => null,
            'employee_id'     => null,
            'department_id'   => null,
            'department_name' => null,
            'section_id'      => null,
            'subsection_id'   => null,
            'resolved'        => false,
        ];

        if ($userId <= 0) {
            return $scope;
        }

        try {
            $conn = Database::getInstance()->getConnection();

            $q = $conn->prepare('SELECT role, employee_id FROM users WHERE id = ? LIMIT 1');
            if (!$q) {
                return $scope;
            }
            $q->bind_param('i', $userId);
            $q->execute();
            $user = $q->get_result()->fetch_assoc();
            $q->close();

            if (!$user) {
                return $scope;
            }

            $scope['role']        = $user['role'] ?? null;
            $scope['employee_id'] = $user['employee_id'] ?? null;

            $empCode = (string)($user['employee_id'] ?? '');
            if ($empCode === '') {
                return $scope;
            }

            $stmt = $conn->prepare(
                'SELECT e.department_id, e.section_id, e.subsection_id, d.name AS department_name
                 FROM employees e
                 LEFT JOIN departments d ON e.department_id = d.id
                 WHERE e.employee_id = ?
                 LIMIT 1'
            );
            if (!$stmt) {
                return $scope;
            }
            $stmt->bind_param('s', $empCode);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $scope['department_id']   = $row['department_id'] !== null ? (int) $row['department_id'] : null;
                $scope['section_id']      = $row['section_id'] !== null ? (int) $row['section_id'] : null;
                $scope['subsection_id']   = $row['subsection_id'] !== null ? (int) $row['subsection_id'] : null;
                $scope['department_name'] = $row['department_name'] ?? null;
                $scope['resolved']        = true;
            }

            return $scope;
        } catch (\Throwable $e) {
            error_log('[OrgScope::forUser] ' . $e->getMessage());
            return $scope;
        }
    }
}
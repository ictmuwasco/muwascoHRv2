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

        // Broad organisational access.
        if ($scope['is_hr'] || $scope['is_super_admin'] || $scope['is_pme_or_audit']) {
            return ['1=1', []];
        }

        $deptCol = $columnMap['department_id'] ?? 'department_id';
        $secCol  = $columnMap['section_id'] ?? 'section_id';
        $subCol  = $columnMap['subsection_id'] ?? 'subsection_id';

        $clauses = [];
        $params  = [];

        if ($scope['is_sub_section_head'] || $scope['is_section_head']) {
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
}
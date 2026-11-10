<?php
declare(strict_types=1);

/**
 * SectionalObjectiveController - Sectional objectives / KPIs stored in
 * `performance_indicators`.
 *
 * Scoping model (role-based only, NO department-id special cases):
 *  - hr_manager is the APEX of this system: sees and manages every employee,
 *    every workplan activity and every KPI across the organisation.
 *  - super_admin shares the apex view.
 *  - managing_director views organisation-wide data but assigns KPIs only to
 *    department heads and HR managers.
 *  - dept_head / section_head / sub_section_head are scoped to their own unit
 *    (generic role-string filters - never hardcoded department ids).
 */
namespace App\Controllers\HR;

use App\Controllers\BaseController;
use App\Helpers\Database;
use App\Helpers\OrgScope;

class SectionalObjectiveController extends BaseController
{
    private \mysqli $db;

    /**
     * Apex role: organisation-wide visibility and management only. The
     * hr_manager role is the department head of the HR and Admin department
     * (role-based, never a hardcoded department id) and follows the same
     * unit-scoped workflow as every other department head.
     */
    private const APEX_ROLES = ['super_admin'];

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    private function isApex(array $scope): bool
    {
        return in_array($scope['role'], self::APEX_ROLES, true);
    }

    /**
     * KPI list scoping (role-based only): apex / managing director see every
     * KPI; department heads see KPIs assigned to direct section reports; section
     * and sub-section heads see KPIs for their own unit.
     */
    private function kpiScopeWhere(array $scope): array
    {
        if ($this->isApex($scope) || $scope['role'] === 'managing_director') {
            return ['1=1', []];
        }
        $dept = $scope['department_id'];
        if ($dept === null) {
            return ['1=0', []];
        }
        $clauses = ['pi.department_id = ?'];
        $params  = [(int) $dept];
        if ($scope['is_dept_head'] || $scope['role'] === 'hr_manager') {
            $clauses[] = "EXISTS (
                SELECT 1
                FROM employees assigned_employee
                WHERE FIND_IN_SET(assigned_employee.id, pi.assigned_to_employee_ids) > 0
                  AND assigned_employee.department_id = ?
                  AND assigned_employee.employee_type IN ('section_head', 'section_manager')
            )";
            $params[] = (int) $dept;
        } elseif ($scope['is_sub_section_head'] && $scope['subsection_id'] !== null) {
            $clauses[] = 'pi.subsection_id = ?';
            $params[]  = (int) $scope['subsection_id'];
        } elseif ($scope['is_section_head'] && $scope['section_id'] !== null) {
            $clauses[] = 'pi.section_id = ?';
            $params[]  = (int) $scope['section_id'];
            $clauses[] = "EXISTS (
                SELECT 1
                FROM employees assigned_employee
                WHERE FIND_IN_SET(assigned_employee.id, pi.assigned_to_employee_ids) > 0
                  AND assigned_employee.section_id = ?
                  AND COALESCE(assigned_employee.subsection_id, 0) = 0
                  AND LOWER(COALESCE(assigned_employee.employee_type, '')) NOT IN (
                      'dept_head', 'head_of_department', 'department_head', 'manager',
                      'section_head', 'section_manager', 'sub_section_head', 'subsection_head'
                  )
            )";
            $params[] = (int) $scope['section_id'];
        }
        return [implode(' AND ', $clauses), $params];
    }

    // =====================================================================
    // Employee scoping (role-based only)
    // =====================================================================

    private function fetchEmployees(?int $departmentId, ?int $sectionId, ?int $subsectionId): array
    {
        $sql = "SELECT e.id, e.employee_id, e.first_name, e.last_name, e.email,
                       e.employee_type, e.position, e.designation,
                       e.department_id, e.section_id, e.subsection_id,
                       d.name AS department_name, s.name AS section_name, ss.name AS subsection_name
                FROM employees e
                LEFT JOIN departments d  ON e.department_id = d.id
                LEFT JOIN sections s     ON e.section_id   = s.id
                LEFT JOIN subsections ss ON e.subsection_id = ss.id
                WHERE e.employee_status = 'active'";
        $types  = '';
        $params = [];
        if ($departmentId) { $sql .= ' AND e.department_id = ?'; $params[] = $departmentId; $types .= 'i'; }
        if ($sectionId)    { $sql .= ' AND e.section_id = ?';    $params[] = $sectionId;    $types .= 'i'; }
        if ($subsectionId) { $sql .= ' AND e.subsection_id = ?'; $params[] = $subsectionId; $types .= 'i'; }
        $sql .= ' ORDER BY e.first_name, e.last_name';

        $stmt = $this->db->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
    /**
     * Resolve the stored employee references for display without limiting names
     * to the caller's currently assignable employee list. An existing KPI may
     * legitimately refer to an inactive employee or a role that is no longer
     * eligible for new assignments; its name must still remain readable.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $scope
     * @return array<int, array<string, mixed>>
     */
    private function withAssignedEmployeeNames(array $rows, array $scope): array
    {
        $employeeIds = [];
        foreach ($rows as $row) {
            foreach ($this->parseEmployeeIds($row['assigned_to_employee_ids'] ?? null) as $employeeId) {
                $employeeIds[$employeeId] = $employeeId;
            }
        }

        $namesById = [];
        $knownEmployeeIds = [];
        if ($employeeIds !== []) {
            $ids = array_values($employeeIds);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare(
                "SELECT id, first_name, last_name, employee_type,
                        department_id, section_id, subsection_id
                 FROM employees
                 WHERE id IN ($placeholders)"
            );
            $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($employee = $result->fetch_assoc()) {
                $employeeId = (int) $employee['id'];
                $knownEmployeeIds[$employeeId] = true;
                if (!$this->isKpiAssigneeVisible($employee, $scope)) {
                    continue;
                }
                $name = trim(
                    implode(' ', array_filter([
                        $employee['first_name'] ?? '',
                        $employee['last_name'] ?? '',
                    ], static fn (string $part): bool => trim($part) !== ''))
                );
                $namesById[$employeeId] = $name !== '' ? $name : 'Unnamed employee';
            }
            $stmt->close();
        }

        foreach ($rows as &$row) {
            $names = [];
            foreach ($this->parseEmployeeIds($row['assigned_to_employee_ids'] ?? null) as $employeeId) {
                if (isset($namesById[$employeeId])) {
                    $names[] = $namesById[$employeeId];
                } elseif (!isset($knownEmployeeIds[$employeeId])) {
                    $names[] = 'Employee unavailable';
                }
            }
            $row['assigned_employee_names'] = $names;
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<string, mixed> $employee
     * @param array<string, mixed> $scope
     */
    private function isKpiAssigneeVisible(array $employee, array $scope): bool
    {
        if ($scope['is_dept_head'] || $scope['role'] === 'hr_manager') {
            return $scope['department_id'] !== null
                && (int) ($employee['department_id'] ?? 0) === (int) $scope['department_id']
                && in_array(
                    self::roleOf($employee),
                    ['section_head', 'section_manager'],
                    true
                );
        }
        if ($scope['is_sub_section_head']) {
            return $scope['subsection_id'] !== null
                && (int) ($employee['subsection_id'] ?? 0) === (int) $scope['subsection_id']
                && !in_array(self::roleOf($employee), ['sub_section_head', 'subsection_head'], true);
        }
        if ($scope['is_section_head']) {
            return $scope['section_id'] !== null
                && (int) ($employee['section_id'] ?? 0) === (int) $scope['section_id']
                && empty($employee['subsection_id'])
                && !in_array(
                    self::roleOf($employee),
                    [
                        'dept_head', 'head_of_department', 'department_head', 'manager',
                        'section_head', 'section_manager', 'sub_section_head', 'subsection_head',
                    ],
                    true
                );
        }
        return true;
    }

    /**
     * @return array<int, int>
     */
    private function parseEmployeeIds(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $raw) as $value) {
            $value = trim($value);
            if ($value === '' || !ctype_digit($value)) {
                continue;
            }
            $id = (int) $value;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The form submits marks for each linked objective, but only their sum is
     * persisted in performance_indicators.max_score.
     *
     * @param array<int|string,mixed> $objectiveMarks
     * @param array<int,int> $activityIds
     */
    private function aggregateObjectiveMarks(array $objectiveMarks, array $activityIds): int
    {
        $allowedIds = array_fill_keys(array_map('intval', $activityIds), true);
        $total = 0;
        foreach ($objectiveMarks as $activityId => $marks) {
            $id = (int) $activityId;
            if (!isset($allowedIds[$id])) {
                $this->error('Marks were provided for an objective that is not linked to this KPI.', 422);
            }
            if (!is_int($marks) && !(is_string($marks) && ctype_digit($marks))) {
                $this->error('Objective marks must be whole numbers greater than or equal to zero.', 422);
            }
            $value = (int) $marks;
            if ($value < 0) {
                $this->error('Objective marks must be whole numbers greater than or equal to zero.', 422);
            }
            if ($value > 2147483647 - $total) {
                $this->error('The aggregate KPI max score is too large.', 422);
            }
            $total += $value;
        }

        foreach (array_keys($allowedIds) as $id) {
            if (!array_key_exists($id, $objectiveMarks) && !array_key_exists((string) $id, $objectiveMarks)) {
                $this->error('Assign marks to every linked objective.', 422);
            }
        }
        if ($total <= 0) {
            $this->error('The aggregate KPI max score must be greater than zero.', 422);
        }
        return $total;
    }


    private static function roleOf(array $e): string
    {
        return strtolower((string) ($e['employee_type'] ?? ''));
    }

    private static function withoutSelf(array $rows, ?int $selfId): array
    {
        if ($selfId === null) return array_values($rows);
        return array_values(array_filter($rows, fn ($e) => (int) $e['id'] !== (int) $selfId));
    }

    private function currentEmployeeId(array $scope): ?int
    {
        $empCode = $scope['employee_id'] ?? null;
        if ($empCode !== null && $empCode !== '') {
            $stmt = $this->db->prepare('SELECT id FROM employees WHERE employee_id = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('s', $empCode);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) return (int) $row['id'];
            }
        }
        return null;
    }

    /**
     * Employees the caller may assign KPIs to.
     * @return array{employees: array, employee_record: ?array}
     */
    private function scopedEmployees(array $scope): array
    {
        $deptId = $scope['department_id'];
        $secId  = $scope['section_id'];
        $subId  = $scope['subsection_id'];

        // The caller's own employee record (context / self-appraisal).
        $own = null;
        $selfId = $this->currentEmployeeId($scope);
        if ($selfId !== null) {
            $stmt = $this->db->prepare(
                "SELECT e.*, d.name AS department_name, s.name AS section_name, ss.name AS subsection_name
                 FROM employees e
                 LEFT JOIN departments d ON e.department_id = d.id
                 LEFT JOIN sections s ON e.section_id = s.id
                 LEFT JOIN subsections ss ON e.subsection_id = ss.id
                 WHERE e.id = ? AND e.employee_status = 'active' LIMIT 1"
            );
            $stmt->bind_param('i', $selfId);
            $stmt->execute();
            $own = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        // APEX: hr_manager / super_admin manage everyone.
        if ($this->isApex($scope)) {
            return ['employees' => $this->fetchEmployees(null, null, null), 'employee_record' => $own];
        }

        // Managing director: department heads + HR managers (role strings only).
        if ($scope['role'] === 'managing_director') {
            $rows = array_values(array_filter(
                $this->fetchEmployees(null, null, null),
                fn ($e) => in_array(self::roleOf($e), ['dept_head', 'head_of_department', 'department_head', 'hr_manager'], true)
            ));
            return ['employees' => self::withoutSelf($rows, $selfId), 'employee_record' => $own];
        }

        // Department head (incl. hr_manager as head of the HR and Admin
        // department): direct section-head reports only. Section heads own
        // KPI assignment for subsection heads and staff below them.
        if (($scope['is_dept_head'] || $scope['role'] === 'hr_manager') && $deptId !== null) {
            $rows = array_values(array_filter(
                $this->fetchEmployees($deptId, null, null),
                fn ($e) => in_array(self::roleOf($e), ['section_head', 'section_manager'], true)
            ));
            return ['employees' => self::withoutSelf($rows, $selfId), 'employee_record' => $own];
        }

        // Section head: direct section employees only. Subsection employees and
        // their head are assigned by the subsection head.
        if ($scope['is_section_head'] && $secId !== null) {
            $rows = array_values(array_filter(
                $this->fetchEmployees(null, $secId, null),
                fn ($e) => empty($e['subsection_id'])
                    && !in_array(self::roleOf($e), [
                        'dept_head', 'head_of_department', 'department_head', 'manager',
                        'section_head', 'section_manager', 'sub_section_head', 'subsection_head',
                    ], true)
            ));
            return ['employees' => self::withoutSelf($rows, $selfId), 'employee_record' => $own];
        }

        // Sub-section head: junior staff only in own subsection.
        if ($scope['is_sub_section_head'] && $subId !== null) {
            $rows = array_values(array_filter(
                $this->fetchEmployees(null, null, $subId),
                fn ($e) => !in_array(self::roleOf($e), ['dept_head', 'head_of_department', 'department_head', 'manager', 'section_head', 'section_manager', 'sub_section_head', 'subsection_head'], true)
            ));
            return ['employees' => self::withoutSelf($rows, $selfId), 'employee_record' => $own];
        }

        return ['employees' => [], 'employee_record' => $own];
    }

    // =====================================================================
    // Linked workplan activities (role-based scoping only)
    // =====================================================================

    private const ACTIVITY_SELECT = '
        SELECT wo.id, wo.objective, wo.section_id, wo.subsection_id, wo.cycle_ids, wo.parent_objective_id,
               pc.name AS contract_name, d.name AS department_name, pc.department_id,
               s.name AS section_name, ss.name AS subsection_name,
               po.objective AS parent_objective';

    private const ACTIVITY_JOINS = '
        FROM workplan_objectives wo
        JOIN performance_contracts pc ON wo.performance_contract_id = pc.id
        JOIN departments d            ON pc.department_id = d.id
        LEFT JOIN sections s          ON wo.section_id = s.id
        LEFT JOIN subsections ss      ON wo.subsection_id = ss.id
        LEFT JOIN workplan_objectives po ON wo.parent_objective_id = po.id';

    private function scopedActivities(array $scope): array
    {
        $activities = [];

        if ($this->isApex($scope) || $scope['role'] === 'managing_director') {
            $res = $this->db->query(self::ACTIVITY_SELECT . self::ACTIVITY_JOINS . '
                ORDER BY d.name, pc.name, wo.objective');
            if ($res) {
                while ($r = $res->fetch_assoc()) $activities[] = $r;
            }
            return $activities;
        }

        if ($scope['is_sub_section_head'] && $scope['subsection_id'] !== null) {
            $sql = self::ACTIVITY_SELECT . self::ACTIVITY_JOINS . '
                WHERE wo.subsection_id = ? AND wo.parent_objective_id IS NOT NULL
                ORDER BY pc.name, wo.objective';
            $stmt = $this->db->prepare($sql);
            $sub = (int) $scope['subsection_id'];
            $stmt->bind_param('i', $sub);
            $stmt->execute();
            $activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            return $activities;
        }

        if ($scope['is_section_head'] && $scope['section_id'] !== null) {
            $sql = self::ACTIVITY_SELECT . self::ACTIVITY_JOINS . '
                WHERE wo.section_id = ? AND COALESCE(wo.subsection_id, 0) = 0
                  AND wo.parent_objective_id IS NOT NULL
                ORDER BY pc.name, wo.objective';
            $stmt = $this->db->prepare($sql);
            $sec = (int) $scope['section_id'];
            $stmt->bind_param('i', $sec);
            $stmt->execute();
            $activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            return $activities;
        }

        if (($scope['is_dept_head'] || $scope['role'] === 'hr_manager') && $scope['department_id'] !== null) {
            $sql = self::ACTIVITY_SELECT . self::ACTIVITY_JOINS . '
                WHERE pc.department_id = ?
                  AND COALESCE(
                      NULLIF(wo.level, \'\'),
                      CASE
                          WHEN wo.subsection_id IS NOT NULL THEN \'subsection\'
                          WHEN wo.section_id IS NOT NULL THEN \'section\'
                          ELSE \'department\'
                      END
                  ) IN (\'department\', \'section\')
                  AND wo.soft_deleted = 0
                ORDER BY pc.name, wo.objective';
            $stmt = $this->db->prepare($sql);
            $dept = (int) $scope['department_id'];
            $stmt->bind_param('i', $dept);
            $stmt->execute();
            $activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            return $activities;
        }

        return $activities;
    }

    /**
     * Resolve activity details for KPI links independently of assignment scope.
     * A caller may view a KPI whose existing links are no longer selectable.
     */
    private function linkedActivities(array $rows): array
    {
        $activityIds = [];
        foreach ($rows as $row) {
            foreach ($this->parseEmployeeIds($row['activity_ids'] ?? null) as $activityId) {
                $activityIds[$activityId] = $activityId;
            }
        }

        if ($activityIds === []) {
            return [];
        }

        $ids = array_values($activityIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            self::ACTIVITY_SELECT . self::ACTIVITY_JOINS . "
                WHERE wo.id IN ($placeholders)
                ORDER BY d.name, pc.name, wo.objective"
        );
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $activities;
    }

    // =====================================================================
    // Endpoints
    // =====================================================================

    /**
     * GET /api/sectional-objectives - List KPIs scoped to the caller, plus the
     * assignable employees, linked activities and appraisal cycles needed by
     * the create/edit modal.
     */
    public function indexAction(): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canViewAny($scope)) {
            $this->forbidden('You do not have permission to view sectional objectives.');
        }

        try {
            [$where, $params] = $this->kpiScopeWhere($scope);
            $types = str_repeat('i', count($params));

            if (isset($_GET['department_id']) && $_GET['department_id'] !== '') {
                $where .= ' AND pi.department_id = ?';
                $params[] = (int) $_GET['department_id'];
                $types   .= 'i';
            }

            $sql = "
                SELECT pi.*, d.name AS department_name, s.name AS section_name,
                       ss.name AS subsection_name,
                       CONCAT_WS(' ', u.first_name, u.last_name, u.surname) AS created_by_name
                FROM performance_indicators pi
                LEFT JOIN departments d ON pi.department_id = d.id
                LEFT JOIN sections s ON pi.section_id = s.id
                LEFT JOIN subsections ss ON pi.subsection_id = ss.id
                LEFT JOIN users u ON pi.created_by = u.id
                WHERE $where
                ORDER BY pi.created_at DESC, pi.id DESC
            ";

            $stmt = $this->db->prepare($sql);
            if ($params) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $rows = $this->withAssignedEmployeeNames($rows, $scope);

            $emp = $this->scopedEmployees($scope);

            $roles = [];
            $res = $this->db->query("SELECT DISTINCT employee_type FROM employees WHERE employee_status='active' AND employee_type IS NOT NULL AND employee_type <> '' ORDER BY employee_type");
            if ($res) $roles = array_column($res->fetch_all(MYSQLI_ASSOC), 'employee_type');

            $cycles = [];
            $res = $this->db->query("SELECT id, name, start_date, end_date FROM appraisal_cycles WHERE status='active' ORDER BY start_date");
            if ($res) $cycles = $res->fetch_all(MYSQLI_ASSOC);

            $this->success([
                'objectives' => $rows,
                'can_manage' => OrgScope::canManagePerformance($scope),
                'employees'  => $emp['employees'],
                'activities' => $this->scopedActivities($scope),
                'linked_activities' => $this->linkedActivities($rows),
                'cycles'     => $cycles,
                'roles'      => $roles,
                'scope'      => [
                    'role'       => $scope['role'],
                    'department' => $scope['department_id'],
                    'section'    => $scope['section_id'],
                    'subsection' => $scope['subsection_id'],
                ],
            ]);
        } catch (\Throwable $e) {
            \logger()->error('Sectional objective list error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve sectional objectives.', 500);
        }
    }

    /**
     * GET /api/sectional-objectives/{id} - One objective.
     */
    public function showAction(int $id): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canViewAny($scope)) {
            $this->forbidden('You do not have permission to view sectional objectives.');
        }

        [$where, $params] = $this->kpiScopeWhere($scope);
        $stmt = $this->db->prepare(
            "SELECT pi.*, d.name AS department_name, s.name AS section_name,
                    ss.name AS subsection_name
             FROM performance_indicators pi
             LEFT JOIN departments d ON pi.department_id = d.id
             LEFT JOIN sections s ON pi.section_id = s.id
             LEFT JOIN subsections ss ON pi.subsection_id = ss.id
             WHERE pi.id = ? AND $where"
        );
        $bindParams = array_merge([$id], $params);
        $stmt->bind_param('i' . str_repeat('i', count($params)), ...$bindParams);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $this->notFound('Sectional objective not found.');
        }
        $this->success($row);
    }

    /**
     * Validate that the given activity ids and employee ids are within the
     * caller's scope and that each activity matches at least one selected
     * employee. Also returns invalid IDs for explicit request errors.
     */
    private function validateAssignments(array $scope, array $activityIds, array $employeeIds): array
    {
        $validActivities = [];
        $requestedActivityIds = array_map('intval', $activityIds);
        $activitiesById = [];
        if ($requestedActivityIds !== []) {
            foreach ($this->scopedActivities($scope) as $activity) {
                $activitiesById[(int) $activity['id']] = $activity;
            }
            foreach ($requestedActivityIds as $aid) {
                if (isset($activitiesById[$aid])) $validActivities[] = $aid;
            }
        }

        $emp = $this->scopedEmployees($scope);
        $allowedEmp = array_map('intval', array_column($emp['employees'], 'id'));
        $requestedEmployeeIds = array_map('intval', $employeeIds);
        $validEmployees = [];
        $employeesById = [];
        foreach ($emp['employees'] as $employee) {
            $employeesById[(int) $employee['id']] = $employee;
        }
        foreach ($requestedEmployeeIds as $eid) {
            if (in_array($eid, $allowedEmp, true)) $validEmployees[] = $eid;
        }

        // Keep the API's assignment validation consistent with the activity
        // picker: each linked activity must match at least one selected
        // employee's department, section and subsection.
        $validActivities = array_values(array_filter(
            $validActivities,
            function (int $activityId) use ($activitiesById, $validEmployees, $employeesById): bool {
                foreach ($validEmployees as $employeeId) {
                    if ($this->activityMatchesEmployee($activitiesById[$activityId], $employeesById[$employeeId])) {
                        return true;
                    }
                }
                return false;
            }
        ));

        $firstEmp = null;
        if (!empty($validEmployees)) {
            $stmt = $this->db->prepare('SELECT department_id, section_id, subsection_id FROM employees WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $validEmployees[0]);
            $stmt->execute();
            $firstEmp = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        return [
            $validActivities,
            $validEmployees,
            $firstEmp,
            array_values(array_diff($requestedActivityIds, $validActivities)),
            array_values(array_diff($requestedEmployeeIds, $validEmployees)),
        ];
    }

    /**
     * Whether an activity's assigned unit contains the employee.
     */
    private function activityMatchesEmployee(array $activity, array $employee): bool
    {
        if (
            !empty($activity['department_id'])
            && !empty($employee['department_id'])
            && (int) $activity['department_id'] !== (int) $employee['department_id']
        ) {
            return false;
        }
        $employeeType = strtolower(trim((string) ($employee['employee_type'] ?? '')));
        if (in_array($employeeType, ['section_head', 'section_manager'], true)) {
            if (
                empty($activity['section_id'])
                || (int) $activity['section_id'] !== (int) ($employee['section_id'] ?? 0)
                || !empty($activity['subsection_id'])
            ) {
                return false;
            }
        }
        if (
            !empty($activity['section_id'])
            && (int) $activity['section_id'] !== (int) ($employee['section_id'] ?? 0)
        ) {
            return false;
        }
        if (
            !empty($activity['subsection_id'])
            && (int) $activity['subsection_id'] !== (int) ($employee['subsection_id'] ?? 0)
        ) {
            return false;
        }
        return true;
    }

    /**
     * POST /api/sectional-objectives - Create an objective.
     */
    public function storeAction(): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canManagePerformance($scope)) {
            $this->forbidden('You do not have permission to manage sectional objectives.');
        }
        $data = $this->getJsonBody();

        $name        = trim((string) ($data['name'] ?? ''));
        $desc        = trim((string) ($data['description'] ?? ''));
        $measureUnit = isset($data['measure_unit']) && trim((string) $data['measure_unit']) !== ''
            ? trim((string) $data['measure_unit'])
            : null;
        $maxScore    = (int) ($data['max_score'] ?? 5);
        $role        = isset($data['role']) && trim((string) $data['role']) !== '' ? trim((string) $data['role']) : null;
        $isActive    = !empty($data['is_active']) ? 1 : 0;
        $isRecurrent = !empty($data['is_recurrent']) ? 1 : 0;
        $activityIds = isset($data['activity_ids']) && is_array($data['activity_ids']) ? $data['activity_ids'] : [];
        $employeeIds = isset($data['assigned_to_employee_ids']) && is_array($data['assigned_to_employee_ids'])
            ? $data['assigned_to_employee_ids']
            : (isset($data['employee_ids']) && is_array($data['employee_ids']) ? $data['employee_ids'] : []);

        if ($name === '') {
            $this->error('Objective name is required.', 422);
        }
        if ($maxScore <= 0) {
            $this->error('Max score must be a positive number.', 422);
        }

        [$validActivities, $validEmployees, $firstEmp, $invalidActivities, $invalidEmployees] =
            $this->validateAssignments($scope, $activityIds, $employeeIds);

        if ($invalidEmployees !== []) {
            $this->error('One or more selected employees are outside your assignment scope.', 422);
        }
        if (empty($validEmployees)) {
            $this->error('Select at least one valid employee.', 422);
        }
        if ($invalidActivities !== []) {
            $this->error('Every linked workplan activity must belong to a selected employee unit.', 422);
        }
        if (empty($validActivities)) {
            $this->error('Select at least one valid workplan activity.', 422);
        }
        if (array_key_exists('objective_marks', $data)) {
            if (!is_array($data['objective_marks'])) {
                $this->error('Objective marks must be supplied as a collection.', 422);
            }
            $maxScore = $this->aggregateObjectiveMarks($data['objective_marks'], $validActivities);
        }
        if ($maxScore <= 0) {
            $this->error('Max score must be a positive number.', 422);
        }
        $activityIdsStr = implode(',', $validActivities);
        $assignedStr    = implode(',', $validEmployees);
        $departmentId   = $firstEmp['department_id'] !== null ? (int) $firstEmp['department_id'] : null;
        $sectionId      = $firstEmp['section_id'] !== null ? (int) $firstEmp['section_id'] : null;
        $subsectionId   = $firstEmp['subsection_id'] !== null ? (int) $firstEmp['subsection_id'] : null;

        $stmt = $this->db->prepare(
            "INSERT INTO performance_indicators
                (name, description, measure_unit, max_score, activity_ids, role, assigned_to_employee_ids,
                 department_id, section_id, subsection_id, is_active, is_recurrent, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $userId = $this->getUserId() ?: null;
        $stmt->bind_param(
            'sssisssssiiii',
            $name, $desc, $measureUnit, $maxScore, $activityIdsStr, $role, $assignedStr,
            $departmentId, $sectionId, $subsectionId, $isActive, $isRecurrent, $userId
        );
        $stmt->execute();
        $newId = (int) $this->db->insert_id;
        $stmt->close();

        $this->success(['id' => $newId], 'Sectional objective created', 201);
    }

    /**
     * True when the caller may modify this KPI row (unit scope or creator).
     */
    private function canModify(array $scope, array $row): bool
    {
        if ($this->isApex($scope)) {
            return true;
        }
        if ((int) ($row['created_by'] ?? 0) === (int) $scope['user_id']) {
            return true;
        }
        $dept = $scope['department_id'];
        $sec  = $scope['section_id'];
        $sub  = $scope['subsection_id'];
        if ($scope['is_sub_section_head'] && $sub !== null) {
            return (int) $row['subsection_id'] === (int) $sub;
        }
        if ($scope['is_section_head'] && $sec !== null) {
            return (int) $row['section_id'] === (int) $sec;
        }
        if ($dept !== null) {
            return (int) $row['department_id'] === (int) $dept;
        }
        return false;
    }

    /**
     * PUT /api/sectional-objectives/{id} - Update an objective.
     */
    public function updateAction(int $id): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canManagePerformance($scope)) {
            $this->forbidden('You do not have permission to manage sectional objectives.');
        }

        $stmt = $this->db->prepare('SELECT * FROM performance_indicators WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $old = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$old) {
            $this->notFound('Sectional objective not found.');
        }
        if (!$this->canModify($scope, $old)) {
            $this->forbidden('You do not have permission to edit this sectional objective.');
        }

        $data = $this->getJsonBody();

        $fields = [];
        $params = [];
        $types  = '';
        $activityIdsProvided = isset($data['activity_ids']) && is_array($data['activity_ids']);
        $employeeIds = $data['assigned_to_employee_ids'] ?? ($data['employee_ids'] ?? null);
        $employeeIdsProvided = is_array($employeeIds);
        $assignmentProvided = $activityIdsProvided || $employeeIdsProvided;
        $validActivities = [];
        $validEmployees = [];
        $firstEmp = null;
        if ($assignmentProvided) {
            $activityIdsToValidate = $activityIdsProvided
                ? $data['activity_ids']
                : $this->parseEmployeeIds($old['activity_ids'] ?? null);
            $employeeIdsToValidate = $employeeIdsProvided
                ? $employeeIds
                : $this->parseEmployeeIds($old['assigned_to_employee_ids'] ?? null);
            [$validActivities, $validEmployees, $firstEmp, $invalidActivities, $invalidEmployees] =
                $this->validateAssignments($scope, $activityIdsToValidate, $employeeIdsToValidate);
            if ($invalidEmployees !== []) {
                $this->error('One or more selected employees are outside your assignment scope.', 422);
            }
            if ($validEmployees === []) {
                $this->error('Select at least one valid employee.', 422);
            }
            if ($invalidActivities !== []) {
                $this->error('Every linked workplan activity must belong to a selected employee unit. Unlink incompatible activities and try again.', 422);
            }
            if ($validActivities === []) {
                $this->error('Select at least one valid workplan activity.', 422);
            }
        }

        $objectiveMarksProvided = array_key_exists('objective_marks', $data);
        if ($objectiveMarksProvided) {
            if (!is_array($data['objective_marks']) || !isset($data['activity_ids']) || !is_array($data['activity_ids'])) {
                $this->error('Objective marks must be submitted with the linked objectives.', 422);
            }
            $fields[] = 'max_score = ?';
            $params[] = $this->aggregateObjectiveMarks($data['objective_marks'], $validActivities);
            $types .= 'i';
        }

        foreach (['name', 'description', 'measure_unit', 'role'] as $f) {
            if (array_key_exists($f, $data)) {
                $val = trim((string) $data[$f]);
                $fields[] = "$f = ?";
                $params[] = $val !== '' ? $val : null;
                $types   .= 's';
            }
        }
        if (!$objectiveMarksProvided && isset($data['max_score']) && (int) $data['max_score'] > 0) {
            $fields[] = 'max_score = ?';
            $params[] = (int) $data['max_score'];
            $types   .= 'i';
        }
        if (isset($data['is_active'])) {
            $fields[] = 'is_active = ?';
            $params[] = !empty($data['is_active']) ? 1 : 0;
            $types   .= 'i';
        }
        if (isset($data['is_recurrent'])) {
            $fields[] = 'is_recurrent = ?';
            $params[] = !empty($data['is_recurrent']) ? 1 : 0;
            $types   .= 'i';
        }
        if ($activityIdsProvided) {
            $fields[] = 'activity_ids = ?';
            $params[] = implode(',', $validActivities);
            $types   .= 's';
        }

        if ($employeeIdsProvided) {
            $fields[] = 'assigned_to_employee_ids = ?';
            $params[] = implode(',', $validEmployees);
            $types   .= 's';
            if ($firstEmp) {
                $fields[] = 'department_id = ?';
                $params[] = $firstEmp['department_id'] !== null ? (int) $firstEmp['department_id'] : null;
                $types   .= 'i';
                $fields[] = 'section_id = ?';
                $params[] = $firstEmp['section_id'] !== null ? (int) $firstEmp['section_id'] : null;
                $types   .= 'i';
                $fields[] = 'subsection_id = ?';
                $params[] = $firstEmp['subsection_id'] !== null ? (int) $firstEmp['subsection_id'] : null;
                $types   .= 'i';
            }
        }

        if (empty($fields)) {
            $this->error('No valid fields to update.', 422);
        }
        $fields[] = 'updated_at = NOW()';
        $params[] = $id;
        $types   .= 'i';

        $stmt = $this->db->prepare('UPDATE performance_indicators SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();

        $this->success(null, 'Sectional objective updated');
    }

    /**
     * DELETE /api/sectional-objectives/{id} - Deactivate (soft) delete.
     */
    public function destroyAction(int $id): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canManagePerformance($scope)) {
            $this->forbidden('You do not have permission to manage sectional objectives.');
        }

        $stmt = $this->db->prepare('SELECT * FROM performance_indicators WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            $this->notFound('Sectional objective not found.');
        }
        if (!$this->canModify($scope, $row)) {
            $this->forbidden('You do not have permission to delete this sectional objective.');
        }

        // Soft-delete: deactivate rather than physically remove (referenced by
        // cycle_indicators / appraisal scoring).
        $stmt = $this->db->prepare('UPDATE performance_indicators SET is_active = 0, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        $this->success(null, 'Sectional objective deactivated');
    }
}

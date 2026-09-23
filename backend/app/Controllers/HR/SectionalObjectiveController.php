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
     * KPI; department heads (incl. hr_manager) their department; section and
     * sub-section heads their own unit. No department-id special cases.
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
        if ($scope['is_sub_section_head'] && $scope['subsection_id'] !== null) {
            $clauses[] = 'pi.subsection_id = ?';
            $params[]  = (int) $scope['subsection_id'];
        } elseif ($scope['is_section_head'] && $scope['section_id'] !== null) {
            $clauses[] = 'pi.section_id = ?';
            $params[]  = (int) $scope['section_id'];
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
        // department): section heads / sub-section heads / managers in own unit.
        if (($scope['is_dept_head'] || $scope['role'] === 'hr_manager') && $deptId !== null) {
            $rows = array_values(array_filter(
                $this->fetchEmployees($deptId, null, null),
                fn ($e) => in_array(self::roleOf($e), ['section_head', 'section_manager', 'sub_section_head', 'subsection_head', 'manager'], true)
            ));
            return ['employees' => self::withoutSelf($rows, $selfId), 'employee_record' => $own];
        }

        // Section head: junior staff + sub-section heads in own section.
        if ($scope['is_section_head'] && $secId !== null) {
            $rows = array_values(array_filter(
                $this->fetchEmployees(null, $secId, null),
                fn ($e) => !in_array(self::roleOf($e), ['dept_head', 'head_of_department', 'department_head', 'manager', 'section_head', 'section_manager'], true)
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
                WHERE wo.section_id = ? AND wo.parent_objective_id IS NOT NULL
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

        $stmt = $this->db->prepare(
            "SELECT pi.*, d.name AS department_name, s.name AS section_name,
                    ss.name AS subsection_name
             FROM performance_indicators pi
             LEFT JOIN departments d ON pi.department_id = d.id
             LEFT JOIN sections s ON pi.section_id = s.id
             LEFT JOIN subsections ss ON pi.subsection_id = ss.id
             WHERE pi.id = ?"
        );
        $stmt->bind_param('i', $id);
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
     * caller's scope. Returns [activityIds, employeeIds, firstEmpRef].
     */
    private function validateAssignments(array $scope, array $activityIds, array $employeeIds): array
    {
        $validActivities = [];
        if (!empty($activityIds)) {
            $allowed = array_map('intval', array_column($this->scopedActivities($scope), 'id'));
            foreach ($activityIds as $aid) {
                $aid = (int) $aid;
                if (in_array($aid, $allowed, true)) $validActivities[] = $aid;
            }
        }

        $emp = $this->scopedEmployees($scope);
        $allowedEmp = array_map('intval', array_column($emp['employees'], 'id'));
        $validEmployees = [];
        foreach ($employeeIds as $eid) {
            $eid = (int) $eid;
            if (in_array($eid, $allowedEmp, true)) $validEmployees[] = $eid;
        }

        $firstEmp = null;
        if (!empty($validEmployees)) {
            $stmt = $this->db->prepare('SELECT department_id, section_id, subsection_id FROM employees WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $validEmployees[0]);
            $stmt->execute();
            $firstEmp = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        return [$validActivities, $validEmployees, $firstEmp];
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

        [$validActivities, $validEmployees, $firstEmp] = $this->validateAssignments($scope, $activityIds, $employeeIds);

        if (empty($validActivities)) {
            $this->error('Select at least one valid workplan activity.', 422);
        }
        if (empty($validEmployees)) {
            $this->error('Select at least one valid employee.', 422);
        }

        $activityIdsStr = implode(',', $validActivities);
        $assignedStr    = implode(',', $validEmployees);
        $departmentId   = $firstEmp['department_id'] !== null ? (int) $firstEmp['department_id'] : null;
        $sectionId      = $firstEmp['section_id'] !== null ? (int) $firstEmp['section_id'] : null;
        $subsectionId   = $firstEmp['subsection_id'] !== null ? (int) $firstEmp['subsection_id'] : null;

        $stmt = $this->db->prepare(
            "INSERT INTO performance_indicators
                (name, description, max_score, activity_ids, role, assigned_to_employee_ids,
                 department_id, section_id, subsection_id, is_active, is_recurrent, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $userId = $this->getUserId() ?: null;
        $stmt->bind_param(
            'ssisssssiiii',
            $name, $desc, $maxScore, $activityIdsStr, $role, $assignedStr,
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

        foreach (['name', 'description', 'role'] as $f) {
            if (array_key_exists($f, $data)) {
                $val = trim((string) $data[$f]);
                $fields[] = "$f = ?";
                $params[] = $val !== '' ? $val : null;
                $types   .= 's';
            }
        }
        if (isset($data['max_score']) && (int) $data['max_score'] > 0) {
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
        if (isset($data['activity_ids']) && is_array($data['activity_ids'])) {
            [$validActivities] = $this->validateAssignments($scope, $data['activity_ids'], []);
            if (empty($validActivities)) {
                $this->error('Select at least one valid workplan activity.', 422);
            }
            $fields[] = 'activity_ids = ?';
            $params[] = implode(',', $validActivities);
            $types   .= 's';
        }

        $employeeIds = $data['assigned_to_employee_ids'] ?? ($data['employee_ids'] ?? null);
        if (is_array($employeeIds)) {
            [, $validEmployees, $firstEmp] = $this->validateAssignments($scope, [], $employeeIds);
            if (empty($validEmployees)) {
                $this->error('Select at least one valid employee.', 422);
            }
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

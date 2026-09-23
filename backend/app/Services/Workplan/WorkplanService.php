<?php

declare(strict_types=1);

namespace App\Services\Workplan;

use App\Helpers\OrgScope;

/**
 * Workplan Service
 *
 * Owns workplan domain logic: role-based view scoping, cascade lineage,
 * objective scope/parent validation, and shared workplan queries.
 * Extracted verbatim from WorkplanController (Phase 3, behavior-preserving).
 */
class WorkplanService
{
    public function __construct(private \mysqli $db)
    {
    }

    /**
     * True when the caller has organisation-wide workplan visibility
     * (legacy MD workplan behaviour: the managing director sees all four
     * departmental workplans unified under the organisation's goals).
     * 
     * PME and Audit department heads are NOT broad workplan viewers -
     * they are department heads scoped to their own department's workplans.
     * Only managing_director, super_admin, and hr_manager (as dept head of
     * HR/Admin) have broad visibility through other mechanisms.
     */
    public function isBroadWorkplan(array $scope): bool
    {
        return $scope['is_super_admin'] || $scope['role'] === 'managing_director';
    }

    /**
     * The hr_manager role is the DEPARTMENT HEAD of the HR and Admin department
     * (role-based only, never a hardcoded department id): it follows the exact
     * same unit-scoped workplan workflow as every other department head and
     * must never receive organisation-wide (broad) treatment.
     */
    private function isHrDeptHead(array $scope): bool
    {
        // Cast defensively: if OrgScope ever omits the key, this must still
        // return a bool (the declared return type), never trigger a
        // "Return value must be of type bool, none returned" TypeError.
        return (bool) ($scope['is_hr'] ?? false);
    }


    /**
     * The workplan tier the caller lands on by default, mirroring the legacy
     * four pages: MD -> managing director page, section_head -> section page,
     * sub_section_head -> subsection page, everything else -> department page.
     */

    /**
     * The workplan tier the caller lands on by default, mirroring the legacy
     * four pages: MD -> managing director page, section_head -> section page,
     * sub_section_head -> subsection page, everything else -> department page.
     */
    public function defaultView(array $scope): string
    {
        if ($this->isBroadWorkplan($scope) && !$this->isHrDeptHead($scope)) {
            return 'md';
        }
        // The HR manager (department head of HR/Admin) lands on the Managing
        // Director tier — the first tab they are allowed to open.
        if ($this->isHrDeptHead($scope)) {
            return 'md';
        }
        if ($scope['is_section_head']) {
            return 'section';
        }
        if ($scope['is_sub_section_head']) {
            return 'subsection';
        }
        return 'department';
    }

    /**
     * Workplan tiers the caller may open — strict one-tier-per-role model:
     * each organisational level only gets its own tier; hr_manager (the
     * department head of HR/Admin) additionally gets the Managing Director
     * tier, and super_admin keeps oversight of every tier.
     *
     * @return string[]
     */
    public function availableViews(array $scope): array
    {
        if ($this->isBroadWorkplan($scope) && !$this->isHrDeptHead($scope)) {
            // Managing Director sees only their own (organisation-wide) tier;
            // super_admin keeps oversight of every tier.
            if ($scope['role'] === 'managing_director') {
                return ['md'];
            }
            return ['md', 'department', 'section', 'subsection'];
        }
        // hr_manager is the department head of HR/Admin: Managing Director tier
        // plus their own departmental workplan.
        if ($this->isHrDeptHead($scope)) {
            return ['md', 'department'];
        }
        if ($scope['is_dept_head']) {
            return ['department'];
        }
        if ($scope['is_section_head']) {
            return ['section'];
        }
        if ($scope['is_sub_section_head']) {
            return ['subsection'];
        }
        // manager / officer — read-only departmental scope.
        return ['department'];
    }

    /**
     * Role-aware scoping for the four legacy workplan tiers.
     *
     * view=md          -> organisation-wide (MD / HR / super admin / PME / Audit
     *                     only; everyone else is pinned to their own department).
     * view=department  -> the caller's department.
     * view=section     -> section heads see their own section; department heads
     *                     see every section in their department.
     * view=subsection  -> sub-section heads see their own subsection; section
     *                     heads see their section's subsections; department heads
     *                     see their department's subsections.
     * view=integrated  -> same as department plus is_integrated = 1 (applied by
     *                     the caller).
     *
     * @return array{0: string, 1: array<int, int>}
     */

    /**
     * Role-aware scoping for the four legacy workplan tiers.
     *
     * view=md          -> organisation-wide (MD / HR / super admin / PME / Audit
     *                     only; everyone else is pinned to their own department).
     * view=department  -> the caller's department.
     * view=section     -> section heads see their own section; department heads
     *                     see every section in their department.
     * view=subsection  -> sub-section heads see their own subsection; section
     *                     heads see their section's subsections; department heads
     *                     see their department's subsections.
     * view=integrated  -> same as department plus is_integrated = 1 (applied by
     *                     the caller).
     *
     * @return array{0: string, 1: array<int, int>}
     */
    public function viewScope(array $scope, string $view): array
    {
        if ($this->isBroadWorkplan($scope) && !$this->isHrDeptHead($scope)) {
            return ['1=1', []];
        }

        // The HR manager may open the Managing Director tier and there sees
        // the organisation-wide workplan; every other tier stays pinned to
        // the HR/Admin department (their own unit).
        if ($this->isHrDeptHead($scope) && $view === 'md') {
            return ['1=1', []];
        }

        if ($view === 'section') {
            if ($scope['is_section_head'] && $scope['section_id'] !== null) {
                return ['w.section_id = ?', [(int) $scope['section_id']]];
            }
            if (($scope['is_dept_head'] || $this->isHrDeptHead($scope)) && $scope['department_id'] !== null) {
                return ['w.section_id IN (SELECT id FROM sections WHERE department_id = ?)', [(int) $scope['department_id']]];
            }
            return $this->departmentWhere($scope);
        }

        if ($view === 'subsection') {
            if ($scope['is_sub_section_head'] && $scope['subsection_id'] !== null) {
                return ['w.subsection_id = ?', [(int) $scope['subsection_id']]];
            }
            if ($scope['is_section_head'] && $scope['section_id'] !== null) {
                return ['w.subsection_id IN (SELECT id FROM subsections WHERE section_id = ?)', [(int) $scope['section_id']]];
            }
            if (($scope['is_dept_head'] || $this->isHrDeptHead($scope)) && $scope['department_id'] !== null) {
                return ['w.subsection_id IN (SELECT id FROM subsections WHERE department_id = ?)', [(int) $scope['department_id']]];
            }
            return $this->departmentWhere($scope);
        }

        // md (non-broad caller) and department — the caller's own department.
        return $this->departmentWhere($scope);
    }

    public function departmentWhere(array $scope): array
    {
        if ($scope['department_id'] !== null) {
            return ['c.department_id = ?', [(int) $scope['department_id']]];
        }
        return ['1=0', []];
    }

    /**
     * Sections the caller may cascade objectives down to (dept heads cascade to
     * sections; broad roles see every section in the organisation view).
     *
     * @return array<int, array{id:int, name:string, department_id:int}>
     */

    /**
     * Sections the caller may cascade objectives down to (dept heads cascade to
     * sections; broad roles see every section in the organisation view).
     *
     * @return array<int, array{id:int, name:string, department_id:int}>
     */
    public function cascadeSections(array $scope, string $view): array
    {
        if ($this->isBroadWorkplan($scope) && !$this->isHrDeptHead($scope) && $view === 'md') {
            return $this->selectRows('SELECT id, name, department_id FROM sections ORDER BY name');
        }
        if (($scope['is_dept_head'] || $this->isHrDeptHead($scope)) && $scope['department_id'] !== null) {
            return $this->selectRows(
                'SELECT id, name, department_id FROM sections WHERE department_id = ? ORDER BY name',
                'i', [(int) $scope['department_id']]
            );
        }
        if ($scope['is_section_head'] && $scope['section_id'] !== null && $view === 'section') {
            return $this->selectRows(
                'SELECT id, name, department_id FROM sections WHERE id = ? ORDER BY name',
                'i', [(int) $scope['section_id']]
            );
        }
        return [];
    }

    /**
     * Subsections the caller may cascade objectives down to (section heads
     * cascade to their section's subsections; dept heads to their dept's).
     *
     * @return array<int, array{id:int, name:string, section_id:int, department_id:int}>
     */

    /**
     * Subsections the caller may cascade objectives down to (section heads
     * cascade to their section's subsections; dept heads to their dept's).
     *
     * @return array<int, array{id:int, name:string, section_id:int, department_id:int}>
     */
    public function cascadeSubsections(array $scope, string $view): array
    {
        if ($this->isBroadWorkplan($scope) && !$this->isHrDeptHead($scope) && $view === 'md') {
            return $this->selectRows('SELECT id, name, section_id, department_id FROM subsections ORDER BY name');
        }
        if ($scope['is_section_head'] && $scope['section_id'] !== null) {
            return $this->selectRows(
                'SELECT id, name, section_id, department_id FROM subsections WHERE section_id = ? ORDER BY name',
                'i', [(int) $scope['section_id']]
            );
        }
        if (($scope['is_dept_head'] || $this->isHrDeptHead($scope)) && $scope['department_id'] !== null) {
            return $this->selectRows(
                'SELECT id, name, section_id, department_id FROM subsections WHERE department_id = ? ORDER BY name',
                'i', [(int) $scope['department_id']]
            );
        }
        if ($scope['is_sub_section_head'] && $scope['subsection_id'] !== null && $view === 'subsection') {
            return $this->selectRows(
                'SELECT id, name, section_id, department_id FROM subsections WHERE id = ? ORDER BY name',
                'i', [(int) $scope['subsection_id']]
            );
        }
        return [];
    }

    /**
     * Enforces the cascade boundaries when a head assigns an objective down
     * to a section / subsection. Returns an error string, or null when the
     * assignment is allowed.
     */

    /**
     * Enforces the cascade boundaries when a head assigns an objective down
     * to a section / subsection. Returns an error string, or null when the
     * assignment is allowed.
     */
    public function validateCascadeAssignment(array $scope, array $data): ?string
    {
        if (($this->isBroadWorkplan($scope) && !$this->isHrDeptHead($scope)) || !OrgScope::canManagePerformance($scope)) {
            return null;
        }

        if ($scope['is_section_head'] && isset($data['subsection_id'])) {
            $sub = (int) $data['subsection_id'];
            $stmt = $this->db->prepare('SELECT section_id FROM subsections WHERE id = ?');
            $stmt->bind_param('i', $sub);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$r || (int) $r['section_id'] !== (int) $scope['section_id']) {
                return 'You may only cascade objectives to subsections within your own section.';
            }
        }

        if ($scope['is_section_head'] && isset($data['section_id'])) {
            if ((int) $data['section_id'] !== (int) $scope['section_id']) {
                return 'You may only place objectives inside your own section.';
            }
        }

        if (($scope['is_dept_head'] || $this->isHrDeptHead($scope)) && isset($data['section_id'])) {
            $sec = (int) $data['section_id'];
            $stmt = $this->db->prepare('SELECT department_id FROM sections WHERE id = ?');
            $stmt->bind_param('i', $sec);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$r || (int) $r['department_id'] !== (int) $scope['department_id']) {
                return 'You may only cascade objectives to sections within your own department.';
            }
        }

        if (($scope['is_dept_head'] || $this->isHrDeptHead($scope)) && isset($data['subsection_id'])) {
            $sub = (int) $data['subsection_id'];
            $stmt = $this->db->prepare('SELECT department_id FROM subsections WHERE id = ?');
            $stmt->bind_param('i', $sub);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$r || (int) $r['department_id'] !== (int) $scope['department_id']) {
                return 'You may only assign subsections within your own department.';
            }
        }

        return null;
    }

    public function selectRows(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /**
     * @return array{string, array} [where_sql, params]
     */

    /**
     * @return array{string, array} [where_sql, params]
     */
    public function workplanScope(array $scope): array
    {
        if ($this->isBroadWorkplan($scope) && !$this->isHrDeptHead($scope)) {
            return ['1=1', []];
        }

        $clauses = [];
        $params  = [];
        if (($scope['is_sub_section_head']) && $scope['subsection_id'] !== null) {
            $clauses[] = 'w.subsection_id = ?';
            $params[]  = (int) $scope['subsection_id'];
        } elseif ($scope['is_section_head'] && $scope['section_id'] !== null) {
            $clauses[] = 'w.section_id = ?';
            $params[]  = (int) $scope['section_id'];
        } elseif ($scope['department_id'] !== null) {
            // Department heads, managers and officers: their own department.
            $clauses[] = 'c.department_id = ?';
            $params[]  = (int) $scope['department_id'];
        }

        if (empty($clauses)) {
            // Unit unresolved - expose nothing rather than everything.
            return ['1=0', []];
        }
        return [implode(' AND ', $clauses), $params];
    }

    public function rowExists(string $table, int $id): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) AS c FROM $table WHERE id = ?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['c'] ?? 0) > 0;
    }

    public function objectiveExists(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM workplan_objectives WHERE id = ? AND soft_deleted = 0');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc() !== null;
        $stmt->close();
        return $exists;
    }

    /**
     * Human-readable label for a workplan objective (used to enrich
     * dependency links returned to the API).
     */

    /**
     * Human-readable label for a workplan objective (used to enrich
     * dependency links returned to the API).
     */
    public function objectiveLabel(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT w.id, w.objective, c.department_id, d.name AS department
             FROM workplan_objectives w
             LEFT JOIN performance_contracts c ON w.performance_contract_id = c.id
             LEFT JOIN departments d ON c.department_id = d.id
             WHERE w.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }
        return [
            'id'          => (int) $row['id'],
            'objective'   => $row['objective'] ?? '',
            'department'  => $row['department'] ?? null,
            'department_id' => $row['department_id'] !== null ? (int) $row['department_id'] : null,
        ];
    }

    /**
     * Build a display name from a users join row (first/last/surname).
     */
    public function actorName(array $row): string
    {
        $parts = array_filter([
            $row['first_name'] ?? '', $row['last_name'] ?? '', $row['surname'] ?? '',
        ], fn ($v) => trim((string) $v) !== '');
        return $parts ? implode(' ', $parts) : 'System';
    }

    public function decodeJsonField(?string $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return $decoded !== null ? $decoded : $value;
    }

    /** Rank used to enforce that cascades always flow strictly downward. */
    private const LEVEL_RANK = [
        'organisation' => 0,
        'department'   => 1,
        'section'      => 2,
        'subsection'   => 3,
    ];

    /**
     * Derives the cascade level for an activity from its assignment context:
     * subsection assigned -> subsection, section assigned -> section,
     * department contract without a unit -> department, else organisation.
     */
    public function deriveLevel(?int $sectionId, ?int $subsectionId, int $contractId): string
    {
        if ($subsectionId !== null) {
            return 'subsection';
        }
        if ($sectionId !== null) {
            return 'section';
        }
        return $contractId > 0 ? 'department' : 'organisation';
    }

    /**
     * Human-readable organisational unit label for dashboard headers.
     */
    public function unitLabel(array $scope, string $view): string
    {
        if (($this->isBroadWorkplan($scope) && !$this->isHrDeptHead($scope))
            || ($this->isHrDeptHead($scope) && $view === 'md')) {
            return 'Organisation-wide';
        }
        if ($scope['is_sub_section_head'] && $scope['subsection_id'] !== null) {
            $rows = $this->selectRows('SELECT name FROM subsections WHERE id = ?', 'i', [(int) $scope['subsection_id']]);
            if ($rows) {
                return 'Subsection: ' . $rows[0]['name'];
            }
        }
        if (($scope['is_section_head'] || $view === 'section') && $scope['section_id'] !== null) {
            $rows = $this->selectRows('SELECT name FROM sections WHERE id = ?', 'i', [(int) $scope['section_id']]);
            if ($rows) {
                return 'Section: ' . $rows[0]['name'];
            }
        }
        if ($scope['department_id'] !== null) {
            $rows = $this->selectRows('SELECT name FROM departments WHERE id = ?', 'i', [(int) $scope['department_id']]);
            if ($rows) {
                return 'Department: ' . $rows[0]['name'];
            }
        }
        return ucfirst(str_replace('_', ' ', (string) $scope['role']));
    }

    /**
     * Active employees the caller may assign work to: heads see the staff of
     * their own unit; broad roles see everyone active.
     */
    public function assignableEmployees(array $scope, string $view): array
    {
        $sql = "SELECT e.id, e.employee_id, CONCAT_WS(' ', e.first_name, e.last_name, e.surname) AS name,
                       e.position, e.department_id, e.section_id, e.subsection_id,
                       d.name AS department_name, sec.name AS section_name, sub.name AS subsection_name
                FROM employees e
                LEFT JOIN departments d ON e.department_id = d.id
                LEFT JOIN sections sec ON e.section_id = sec.id
                LEFT JOIN subsections sub ON e.subsection_id = sub.id";
        $where  = " WHERE e.employee_status = 'active'";
        $types  = '';
        $params = [];

        if (!$this->isBroadWorkplan($scope) || $this->isHrDeptHead($scope)) {
            if ($scope['is_sub_section_head'] && $scope['subsection_id'] !== null) {
                $where .= ' AND e.subsection_id = ?';
                $types .= 'i';
                $params[] = (int) $scope['subsection_id'];
            } elseif ($scope['is_section_head'] && $scope['section_id'] !== null) {
                $where .= ' AND e.section_id = ?';
                $types .= 'i';
                $params[] = (int) $scope['section_id'];
            } elseif ($scope['department_id'] !== null) {
                $where .= ' AND e.department_id = ?';
                $types .= 'i';
                $params[] = (int) $scope['department_id'];
            } else {
                return [];
            }
        }

        return $this->selectRows($sql . $where . ' ORDER BY name LIMIT 500', $types, $params);
    }

    /**
     * GET /api/workplans/summary - Dashboard aggregates for one workplan tier.
     * Totals, per-status counts, completion rate, overdue / upcoming deadlines,
     * cascaded vs local split and the active financial year - all respecting
     * exactly the same role scoping as listAction().
     */

    /**
     * True when a workplan objective (given its unit references) lies inside
     * the caller's organisational scope. Mirrors OrgScope::scopeWhere() but
     * for an already-loaded row instead of SQL.
     */
    public function objectiveWithinScope(array $scope, array $ref): bool
    {
        if ($this->isBroadWorkplan($scope) && !$this->isHrDeptHead($scope)) {
            return true;
        }

        $sectionId    = isset($ref['section_id']) && $ref['section_id'] !== null ? (int) $ref['section_id'] : null;
        $subsectionId = isset($ref['subsection_id']) && $ref['subsection_id'] !== null ? (int) $ref['subsection_id'] : null;
        $deptId       = isset($ref['contract_department_id']) && $ref['contract_department_id'] !== null
            ? (int) $ref['contract_department_id'] : null;

        if ($scope['is_sub_section_head']) {
            return $scope['subsection_id'] !== null && $subsectionId === (int) $scope['subsection_id'];
        }

        if ($scope['is_section_head']) {
            if ($scope['section_id'] !== null && $sectionId === (int) $scope['section_id']) {
                return true;
            }
            if ($subsectionId !== null && $scope['section_id'] !== null) {
                $rows = $this->selectRows('SELECT section_id FROM subsections WHERE id = ?', 'i', [$subsectionId]);
                if ($rows && (int) $rows[0]['section_id'] === (int) $scope['section_id']) {
                    return true;
                }
            }
            return false;
        }

        // Department-scoped roles (dept heads, managers, officers).
        if ($deptId !== null && (int) $scope['department_id'] === $deptId) {
            return true;
        }
        if ($sectionId !== null && $scope['department_id'] !== null) {
            $rows = $this->selectRows('SELECT department_id FROM sections WHERE id = ?', 'i', [$sectionId]);
            if ($rows && (int) $rows[0]['department_id'] === (int) $scope['department_id']) {
                return true;
            }
        }
        if ($subsectionId !== null && $scope['department_id'] !== null) {
            $rows = $this->selectRows('SELECT department_id FROM subsections WHERE id = ?', 'i', [$subsectionId]);
            if ($rows && (int) $rows[0]['department_id'] === (int) $scope['department_id']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Validates that a responsible employee belongs to the unit the activity
     * is being placed in (subsection tasks -> subsection employees, etc.).
     */

    /**
     * Validates that a responsible employee belongs to the unit the activity
     * is being placed in (subsection tasks -> subsection employees, etc.).
     */
    public function validateOfficerPlacement(array $scope, int $officerId, ?int $subsectionId, ?int $sectionId): ?string
    {
        $rows = $this->selectRows(
            "SELECT department_id, section_id, subsection_id FROM employees
             WHERE employee_status = 'active' AND id = ?",
            'i',
            [$officerId]
        );
        if (!$rows) {
            return 'Selected responsible employee does not exist or is inactive.';
        }
        $emp = $rows[0];

        if ($this->isBroadWorkplan($scope) && !$this->isHrDeptHead($scope)) {
            return null;
        }
        if ($scope['is_sub_section_head']) {
            if ($scope['subsection_id'] !== null && (int) $emp['subsection_id'] === (int) $scope['subsection_id']) {
                return null;
            }
            return 'Responsible employees must belong to your own subsection.';
        }
        if ($scope['is_section_head']) {
            $checkSection = $sectionId ?? $scope['section_id'];
            if ($checkSection !== null && (int) $emp['section_id'] === (int) $checkSection) {
                return null;
            }
            return 'Responsible employees must belong to your own section.';
        }
        if ($scope['is_dept_head'] || $this->isHrDeptHead($scope)) {
            if ($scope['department_id'] !== null && (int) $emp['department_id'] === (int) $scope['department_id']) {
                return null;
            }
            return 'Responsible employees must belong to your own department.';
        }
        return null;
    }

    /**
     * Validates linking an activity under a parent objective: the parent must
     * exist and be soft-alive, be visible inside the caller's organisational
     * scope, sit AT or ABOVE the child's cascade level, and never create a
     * circular chain.
     *
     * Strict mode ($strictLevel = true, the default) requires the child to sit
     * STRICTLY below its parent — this is used by the structural cascade flow
     * (cascadeAction) where org→dept→section→subsection must always move one
     * level further down the organisation.
     *
     * Relaxed mode ($strictLevel = false) permits same-level nesting — this is
     * used by storeAction when a section/subsection head decomposes a cascaded
     * source into their own unit-level work (a work breakdown, not a re-org of
     * the hierarchy). In both modes the parent must be visible to the caller and
     * may never be an ancestor of itself.
     *
     * @param string $childLevel The cascade level of the child being created/moved.
     * @param bool   $strictLevel When true the child must sit strictly below the
     *                            parent; when false same-level nesting is allowed.
     */
    public function validateParentLinkage(array $scope, int $parentId, string $childLevel, ?int $movingId = null, bool $strictLevel = true): ?string
    {
        $parentRows = $this->selectRows(
            "SELECT w.id, w.parent_objective_id, w.level, w.section_id, w.subsection_id,
                    c.department_id AS contract_department_id
             FROM workplan_objectives w
             LEFT JOIN performance_contracts c ON w.performance_contract_id = c.id
             WHERE w.id = ? AND w.soft_deleted = 0
             LIMIT 1",
            'i',
            [$parentId]
        );
        $parent = $parentRows[0] ?? null;
        if (!$parent) {
            return 'The selected parent objective does not exist.';
        }
        if ($movingId !== null && (int) $parent['id'] === $movingId) {
            return 'An objective cannot be its own parent.';
        }

        // Cycle guard: walk up the ancestor chain looking for the moved record.
        $cursor = $parent;
        $guard  = 0;
        while ($cursor && (int) $cursor['parent_objective_id'] > 0 && $guard++ < 20) {
            if ($movingId !== null && (int) $cursor['parent_objective_id'] === $movingId) {
                return 'This parent would create a circular cascade.';
            }
            $next   = $this->selectRows(
                'SELECT id, parent_objective_id FROM workplan_objectives WHERE id = ? LIMIT 1',
                'i',
                [(int) $cursor['parent_objective_id']]
            );
            $cursor = $next[0] ?? null;
        }

        // Scope: the caller must be able to see the parent.
        if (!$this->objectiveWithinScope($scope, [
            'section_id'             => $parent['section_id'],
            'subsection_id'          => $parent['subsection_id'],
            'contract_department_id' => $parent['contract_department_id'],
        ])) {
            return 'You cannot link this objective under a parent outside your organisational scope.';
        }

        // Hierarchy: in strict mode the child must sit strictly below its parent
        // (structural cascade org→dept→section→subsection). In relaxed mode
        // same-level nesting is permitted (work breakdown within one unit).
        $parentRank = self::LEVEL_RANK[$parent['level'] ?? 'organisation'] ?? 0;
        $childRank  = self::LEVEL_RANK[$childLevel] ?? 0;
        $tooHigh = $strictLevel ? ($childRank <= $parentRank) : ($childRank < $parentRank);
        if ($tooHigh) {
            return sprintf(
                'A %s-level activity cannot be nested under another %s-level activity.',
                str_replace('_', ' ', $childLevel),
                str_replace('_', ' ', (string) ($parent['level'] ?? 'organisation'))
            );
        }

        return null;
    }

    /**
     * GET /api/workplans/{id}/traceability - Full lineage of one activity:
     * the ancestor chain up to the strategic plan plus the descendant tree
     * down to employee-level tasks. Scope-checked per caller so users can
     * only trace activities they are allowed to see.
     */
}

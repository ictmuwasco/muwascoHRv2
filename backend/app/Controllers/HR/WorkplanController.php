<?php

declare(strict_types=1);

namespace App\Controllers\HR;

use App\Controllers\BaseController;
use App\Helpers\Database;
use App\Helpers\OrgScope;
use App\Services\Workplan\WorkplanService;

/**
 * WorkplanController - Workplan objectives stored in `workplan_objectives`,
 * each linked to a performance contract (which in turn links to the strategic
 * plan / goal / target / department / financial year).
 */
class WorkplanController extends BaseController
{
    private \mysqli $db;

    /** Domain/data service owning workplan query, scope and validation logic. */
    private WorkplanService $workplans;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->workplans = new WorkplanService($this->db);
    }

    /**
     * GET /api/workplans - List workplan objectives scoped to the caller.
     * Supports `view=department|integrated|management` and optional
     * pagination (`page`, `per_page`).
     */
    public function listAction(): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canViewAny($scope)) {
            $this->forbidden('You do not have permission to view workplans.');
        }

        try {
            // Role-based view (legacy workplan tiers): md / department / section /
            // subsection / integrated. The view controls how far down the cascade
            // the caller may look — never wider than their own unit.
            $view = (string) ($_GET['view'] ?? $this->workplans->defaultView($scope));
            $allowedViews = ['md', 'department', 'section', 'subsection', 'integrated'];
            $view = in_array($view, $allowedViews, true) ? $view : $this->workplans->defaultView($scope);

            [$where, $params] = $this->workplans->viewScope($scope, $view);
            $types = str_repeat('i', count($params));

            // Hide soft-deleted rows by default.
            $where .= ' AND w.soft_deleted = 0';

            // `integrated` lists only objectives flagged for organisation-level view.
            if ($view === 'integrated') {
                $where .= ' AND w.is_integrated = 1';
            }

            if (isset($_GET['performance_contract_id']) && $_GET['performance_contract_id'] !== '') {
                $where .= ' AND w.performance_contract_id = ?';
                $params[] = (int) $_GET['performance_contract_id'];
                $types   .= 'i';
            }

            // Optional status filter.
            if (isset($_GET['status']) && $_GET['status'] !== '') {
                $where .= ' AND w.status = ?';
                $params[] = (string) $_GET['status'];
                $types   .= 's';
            }

            // Optional financial-year filter (via contract).
            if (isset($_GET['financial_year_id']) && $_GET['financial_year_id'] !== '') {
                $where .= ' AND c.financial_year_id = ?';
                $params[] = (int) $_GET['financial_year_id'];
                $types   .= 'i';
            }

            // Cascade lineage filter: children of a specific parent, top-level
            // items ('none') or any cascaded item ('any').
            if (isset($_GET['parent_id']) && $_GET['parent_id'] !== '') {
                if ($_GET['parent_id'] === 'none') {
                    $where .= ' AND w.parent_objective_id IS NULL';
                } elseif ($_GET['parent_id'] === 'any') {
                    $where .= ' AND w.parent_objective_id IS NOT NULL';
                } else {
                    $where .= ' AND w.parent_objective_id = ?';
                    $params[] = (int) $_GET['parent_id'];
                    $types   .= 'i';
                }
            }

            // Explicit cascade level filter (organisation|department|section|subsection).
            if (isset($_GET['level']) && $_GET['level'] !== '') {
                $where .= ' AND w.level = ?';
                $params[] = (string) $_GET['level'];
                $types   .= 's';
            }

            // Free-text search across objective and KPI.
            $search = $this->getSearchQuery();
            if ($search !== '') {
                $where   .= ' AND (w.objective LIKE ? OR w.kpi LIKE ?)';
                $like     = '%' . $search . '%';
                $params[] = $like; $types .= 's';
                $params[] = $like; $types .= 's';
            }

            [$page, $perPage] = $this->getPaginationParams();

            $countSql = "SELECT COUNT(*) AS c
                         FROM workplan_objectives w
                         LEFT JOIN performance_contracts c ON w.performance_contract_id = c.id
                         WHERE $where";
            $cStmt = $this->db->prepare($countSql);
            if ($params) {
                $cStmt->bind_param($types, ...$params);
            }
            $cStmt->execute();
            $total = (int) $cStmt->get_result()->fetch_assoc()['c'];
            $cStmt->close();

            $offset = ($page - 1) * $perPage;
            $sql = "
                SELECT w.*, s.name AS section_name, ss.name AS subsection_name,
                       c.name AS contract_name, c.department_id AS contract_department_id,
                       c.financial_year_id, d.name AS department_name,
                       g.name AS goal_name, t.name AS target_name,
                       sp.name AS strategic_plan_name,
                       p.objective AS parent_objective, p.level AS parent_level,
                       CONCAT_WS(' ', u.first_name, u.last_name, u.surname) AS created_by_name,
                       CONCAT_WS(' ', e.first_name, e.last_name, e.surname) AS officer_name,
                       (SELECT COUNT(*) FROM workplan_objectives wc
                         WHERE wc.parent_objective_id = w.id AND wc.soft_deleted = 0) AS children_count
                FROM workplan_objectives w
                LEFT JOIN sections s ON w.section_id = s.id
                LEFT JOIN subsections ss ON w.subsection_id = ss.id
                LEFT JOIN performance_contracts c ON w.performance_contract_id = c.id
                LEFT JOIN departments d ON c.department_id = d.id
                LEFT JOIN goals g ON g.id = COALESCE(w.goal_id, c.goal_id)
                LEFT JOIN strategic_targets t ON w.strategic_target_id = t.id
                LEFT JOIN strategic_plan sp ON sp.id = COALESCE(t.strategic_plan_id, g.strategic_plan_id)
                LEFT JOIN users u ON w.created_by = u.id
                LEFT JOIN employees e ON w.responsible_officer_id = e.id
                LEFT JOIN workplan_objectives p ON w.parent_objective_id = p.id
                WHERE $where
                ORDER BY w.updated_at DESC, w.id DESC
                LIMIT $perPage OFFSET $offset
            ";

            $stmt = $this->db->prepare($sql);
            if ($params) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $this->success([
                'workplans'       => $rows,
                'can_manage'      => OrgScope::canManagePerformance($scope),
                'view'            => $view,
                'default_view'    => $this->workplans->defaultView($scope),
                'available_views' => $this->workplans->availableViews($scope),
                'sections'        => $this->workplans->cascadeSections($scope, $view),
                'subsections'     => $this->workplans->cascadeSubsections($scope, $view),
                'employees'       => $this->workplans->assignableEmployees($scope, $view),
                'scope'           => [
                    'role'       => $scope['role'],
                    'department' => $scope['department_id'],
                    'section'    => $scope['section_id'],
                    'subsection' => $scope['subsection_id'],
                ],
                'pagination'  => [
                    'total'         => $total,
                    'per_page'      => $perPage,
                    'current_page'  => $page,
                    'last_page'     => (int) ceil($total / $perPage),
                ],
            ]);
        } catch (\Throwable $e) {
            \logger()->error('Workplan list error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve workplans.', 500);
        }
    }

    /**
     * GET /api/strategic-plans/{id}/workplans - Workplans under a strategic
     * plan (joined through performance contracts). Kept for compatibility.
     */
    public function indexAction(int $planId): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canViewAny($scope)) {
            $this->forbidden('You do not have permission to view workplans.');
        }

        [$baseWhere, $baseParams] = $this->workplans->workplanScope($scope);
        $baseWhere .= ' AND w.soft_deleted = 0 AND c.strategic_plan_id = ?';
        $baseParams[] = $planId;
        $types = str_repeat('i', count($baseParams));

        $sql = "
            SELECT w.*, s.name AS section_name, ss.name AS subsection_name,
                   c.name AS contract_name, c.department_id AS contract_department_id,
                   g.name AS goal_name
            FROM workplan_objectives w
            LEFT JOIN sections s ON w.section_id = s.id
            LEFT JOIN subsections ss ON w.subsection_id = ss.id
            LEFT JOIN performance_contracts c ON w.performance_contract_id = c.id
            LEFT JOIN goals g ON c.goal_id = g.id
            WHERE $baseWhere
            ORDER BY w.created_at DESC, w.id DESC
        ";

        $stmt = $this->db->prepare($sql);
        if ($baseParams) {
            $stmt->bind_param($types, ...$baseParams);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $this->success($rows);
    }

    /**
     * GET /api/workplans/{id} - One workplan objective.
     */
    public function showAction(int $id): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canViewAny($scope)) {
            $this->forbidden('You do not have permission to view workplans.');
        }

        $stmt = $this->db->prepare(
            "SELECT w.*, s.name AS section_name, ss.name AS subsection_name,
                    c.name AS contract_name
             FROM workplan_objectives w
             LEFT JOIN sections s ON w.section_id = s.id
             LEFT JOIN subsections ss ON w.subsection_id = ss.id
             LEFT JOIN performance_contracts c ON w.performance_contract_id = c.id
             WHERE w.id = ? AND w.soft_deleted = 0"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $this->notFound('Workplan objective not found.');
        }
        $this->success($row);
    }
/**
     * POST /api/workplans - Create a workplan objective.
     */
    public function storeAction(): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canManagePerformance($scope)) {
            $this->forbidden('You do not have permission to manage workplans.');
        }
        $data = $this->getJsonBody();

        $contractId = (int) ($data['performance_contract_id'] ?? 0);
        $objective  = trim((string) ($data['objective'] ?? ''));
        $kpi        = trim((string) ($data['kpi'] ?? ''));
        $measure    = trim((string) ($data['measure_unit'] ?? ''));
        $sectionId  = isset($data['section_id']) && (int) $data['section_id'] > 0 ? (int) $data['section_id'] : null;
        $subsectionId = isset($data['subsection_id']) && (int) $data['subsection_id'] > 0 ? (int) $data['subsection_id'] : null;
        $cycleIds   = isset($data['cycle_ids']) ? trim((string) $data['cycle_ids']) : '';
        $y1 = $data['Y1'] ?? null; $y2 = $data['Y2'] ?? null; $y3 = $data['Y3'] ?? null;
        $y4 = $data['Y4'] ?? null; $y5 = $data['Y5'] ?? null;

        // New tracking / alignment fields.
        $goalId      = isset($data['goal_id']) && (int) $data['goal_id'] > 0 ? (int) $data['goal_id'] : null;
        $targetId    = isset($data['strategic_target_id']) && (int) $data['strategic_target_id'] > 0 ? (int) $data['strategic_target_id'] : null;
        $parentId    = isset($data['parent_objective_id']) && (int) $data['parent_objective_id'] > 0 ? (int) $data['parent_objective_id'] : null;
        $officerId   = isset($data['responsible_officer_id']) && (int) $data['responsible_officer_id'] > 0 ? (int) $data['responsible_officer_id'] : null;
        $progress    = (int) ($data['progress_percent'] ?? 0);
        $status      = isset($data['status']) ? trim((string) $data['status']) : 'not_started';
        $status      = in_array($status, ['not_started', 'in_progress', 'completed', 'at_risk', 'off_track'], true) ? $status : 'not_started';
        $evidence    = isset($data['evidence_path']) ? trim((string) $data['evidence_path']) : null;
        $budget      = isset($data['budget_amount']) && $data['budget_amount'] !== '' ? (float) $data['budget_amount'] : 0.00;
        $resources   = isset($data['resource_notes']) ? trim((string) $data['resource_notes']) : null;
        $pStart      = isset($data['planned_start_date']) && $data['planned_start_date'] !== '' ? (string) $data['planned_start_date'] : null;
        $pEnd        = isset($data['planned_end_date']) && $data['planned_end_date'] !== '' ? (string) $data['planned_end_date'] : null;
        $actualDone  = isset($data['actual_completion_date']) && $data['actual_completion_date'] !== '' ? (string) $data['actual_completion_date'] : null;
        $depJson     = isset($data['dependencies']) && is_array($data['dependencies'])
            ? json_encode($data['dependencies'], JSON_UNESCAPED_SLASHES)
            : null;
        $isIntegrated = isset($data['is_integrated']) ? (int) $data['is_integrated'] : 0;

        // Organisation-level activities on the Managing Director workplan may
        // anchor directly to a strategic goal/target without a departmental
        // performance contract; every other activity requires one.
        $broadCaller = $this->workplans->isBroadWorkplan($scope);
        if (!$broadCaller && $contractId <= 0) {
            $this->error('Performance contract, objective, kpi and measure unit are required.', 422);
        }
        // Legacy parity: departmental activity rows carry a description only -
        // KPI / measure are optional extras filled later if needed.
        if ($objective === '') {
            $this->error('The activity description is required.', 422);
        }
        if ($contractId > 0 && !$this->workplans->rowExists('performance_contracts', $contractId)) {
            $this->error('Selected performance contract does not exist.', 422);
        }
        if ($contractId <= 0 && $parentId === null && $goalId === null && $targetId === null) {
            $this->error('Organisation-level activities must reference a strategic goal or target (or a parent activity).', 422);
        }
        if ($sectionId !== null && !$this->workplans->rowExists('sections', $sectionId)) {
            $this->error('Selected section does not exist.', 422);
        }
        if ($subsectionId !== null && !$this->workplans->rowExists('subsections', $subsectionId)) {
            $this->error('Selected subsection does not exist.', 422);
        }

        // If the strategic target is supplied but goal_id is not, derive the goal
        // perspective from the target, mirroring the performance-contract alignment.
        if ($targetId !== null && $goalId === null) {
            $tStmt = $this->db->prepare('SELECT goal_id FROM strategic_targets WHERE id = ? LIMIT 1');
            $tStmt->bind_param('i', $targetId);
            $tStmt->execute();
            $tRow = $tStmt->get_result()->fetch_assoc();
            $tStmt->close();
            if ($tRow) {
                $goalId = (int) $tRow['goal_id'];
            }
        }

        // Server-side unit pinning: heads of smaller units can never place an
        // activity outside their own organisational unit.
        if ($scope['is_sub_section_head']) {
            if ($scope['section_id'] !== null) {
                $sectionId = (int) $scope['section_id'];
            }
            if ($scope['subsection_id'] !== null) {
                $subsectionId = (int) $scope['subsection_id'];
            }
        } elseif ($scope['is_section_head'] && $scope['section_id'] !== null) {
            $sectionId = (int) $scope['section_id'];
        }

        // Cascade boundary validation for explicitly requested units: heads
        // may never place an activity into another head's unit.
        if ($sectionId !== null || $subsectionId !== null) {
            $assignErr = $this->workplans->validateCascadeAssignment($scope, [
                'section_id'    => $sectionId,
                'subsection_id' => $subsectionId,
            ]);
            if ($assignErr !== null) {
                $this->error($assignErr, 403);
            }
        }

        // Derive the cascade level from the final assignment context.
        $level = $this->workplans->deriveLevel($sectionId, $subsectionId, $contractId);

        // Parent linkage must exist, sit strictly above the child level and be
        // inside the caller's organisational scope (no cross-unit re-parenting).
        if ($parentId !== null) {
            $parentErr = $this->workplans->validateParentLinkage($scope, $parentId, $level);
            if ($parentErr !== null) {
                $this->error($parentErr, 403);
            }
        }

        // Organisation-level MD activities carry no department contract (NULL).
        $pcVal     = $contractId > 0 ? $contractId : null;
        $createdBy = $this->getUserId();

        $stmt = $this->db->prepare(
            "INSERT INTO workplan_objectives
                (performance_contract_id, objective, kpi, measure_unit, section_id,
                 subsection_id, cycle_ids, Y1, Y2, Y3, Y4, Y5,
                 goal_id, strategic_target_id, parent_objective_id, responsible_officer_id,
                 progress_percent, status, evidence_path, budget_amount, resource_notes,
                 planned_start_date, planned_end_date, actual_completion_date,
                 dependencies, is_integrated, level, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                     ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $stmt->bind_param(
            'isssiissssssiiiiissdsssssisi',
            $pcVal, $objective, $kpi, $measure, $sectionId, $subsectionId,
            $cycleIds, $y1, $y2, $y3, $y4, $y5,
            $goalId, $targetId, $parentId, $officerId,
            $progress, $status, $evidence, $budget, $resources,
            $pStart, $pEnd, $actualDone, $depJson, $isIntegrated, $level, $createdBy
        );
        $stmt->execute();
        $newId = (int) $this->db->insert_id;
        $stmt->close();

        \App\Services\AuditService::getInstance()->log(
            \App\Services\AuditService::MODULE_PERFORMANCE,
            \App\Services\AuditService::ACTION_CREATE,
            'Created workplan objective: ' . mb_substr($objective, 0, 120),
            ['target_type' => 'WorkplanObjective', 'target_id' => $newId, 'new_values' => [
                'performance_contract_id' => $contractId, 'status' => $status, 'progress_percent' => $progress,
                'level' => $level, 'parent_objective_id' => $parentId,
            ]]
        );

        $this->success(['id' => $newId], 'Workplan objective created', 201);
    }

    /**
     * PUT /api/workplans/{id} - Update a workplan objective.
     */
    public function updateAction(int $id): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canManagePerformance($scope)) {
            $this->forbidden('You do not have permission to manage workplans.');
        }
        $data = $this->getJsonBody();

        // Capture the current row for scoping + audit old-values.
        $exStmt = $this->db->prepare("SELECT w.*, c.department_id AS dept_id
                                      FROM workplan_objectives w
                                      LEFT JOIN performance_contracts c ON w.performance_contract_id = c.id
                                      WHERE w.id = ? AND w.soft_deleted = 0");
        $exStmt->bind_param('i', $id);
        $exStmt->execute();
        $existing = $exStmt->get_result()->fetch_assoc();
        $exStmt->close();

        if (!$existing) {
            $this->notFound('Workplan objective not found.');
        }

        $fields = [];
        $params = [];
        $types  = '';

        foreach (['objective', 'kpi', 'measure_unit', 'cycle_ids', 'resource_notes', 'evidence_path'] as $f) {
            if (isset($data[$f]) && trim((string) $data[$f]) !== '') {
                $fields[] = "$f = ?";
                $params[] = trim((string) $data[$f]);
                $types   .= 's';
            }
        }
        foreach (['Y1', 'Y2', 'Y3', 'Y4', 'Y5'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = $data[$f] !== '' ? (string) $data[$f] : null;
                $types   .= 's';
            }
        }
        foreach (['section_id', 'subsection_id'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = $data[$f] !== '' && (int) $data[$f] > 0 ? (int) $data[$f] : null;
                $types   .= 'i';
            }
        }
        // Nullable integer foreign keys (allow clearing with '' or 0).
        foreach (['goal_id', 'strategic_target_id', 'responsible_officer_id'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = isset($data[$f]) && (int) $data[$f] > 0 ? (int) $data[$f] : null;
                $types   .= 'i';
            }
        }
        if (array_key_exists('progress_percent', $data)) {
            $fields[] = 'progress_percent = ?';
            $p = (int) $data['progress_percent'];
            $params[] = max(0, min(100, $p));
            $types   .= 'i';
        }
        if (array_key_exists('status', $data) && in_array($data['status'], ['not_started', 'in_progress', 'completed', 'at_risk', 'off_track'], true)) {
            $fields[] = 'status = ?';
            $params[] = (string) $data['status'];
            $types   .= 's';
        }
        if (array_key_exists('budget_amount', $data) && $data['budget_amount'] !== '') {
            $fields[] = 'budget_amount = ?';
            $params[] = (float) $data['budget_amount'];
            $types   .= 'd';
        }
        foreach (['planned_start_date', 'planned_end_date', 'actual_completion_date'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = (isset($data[$f]) && $data[$f] !== '') ? (string) $data[$f] : null;
                $types   .= 's';
            }
        }
        if (array_key_exists('dependencies', $data)) {
            $fields[] = 'dependencies = ?';
            $params[] = is_array($data['dependencies'])
                ? json_encode($data['dependencies'], JSON_UNESCAPED_SLASHES)
                : null;
            $types   .= 's';
        }
        if (array_key_exists('is_integrated', $data)) {
            $fields[] = 'is_integrated = ?';
            $params[] = (int) $data['is_integrated'] ? 1 : 0;
            $types   .= 'i';
        }
        if (array_key_exists('performance_contract_id', $data)) {
            $fields[] = 'performance_contract_id = ?';
            $params[] = (int) $data['performance_contract_id'];
            $types   .= 'i';
        }

        if (empty($fields)) {
            $this->error('No valid fields to update.', 422);
        }

        // Cascade ownership: only allow assigning units the caller may cascade
        // to. Existing values are merged in so partial updates are still
        // validated against the caller's cascade boundaries.
        if (array_key_exists('section_id', $data) || array_key_exists('subsection_id', $data)) {
            $assignErr = $this->workplans->validateCascadeAssignment($scope, array_merge($existing, $data));
            if ($assignErr !== null) {
                $this->error($assignErr, 403);
            }
        }

        // Parent linkage: any re-parenting must stay inside the caller's scope,
        // sit strictly above the record's own cascade level, and never create a
        // circular chain.
        if (array_key_exists('parent_objective_id', $data)) {
            $newParent = isset($data['parent_objective_id']) && (int) $data['parent_objective_id'] > 0
                ? (int) $data['parent_objective_id']
                : null;
            if ($newParent !== null && (int) ($existing['parent_objective_id'] ?? 0) !== $newParent) {
                $currentLevel = $existing['level'] ?? $this->workplans->deriveLevel(
                    $existing['section_id'] !== null ? (int) $existing['section_id'] : null,
                    $existing['subsection_id'] !== null ? (int) $existing['subsection_id'] : null,
                    (int) ($existing['performance_contract_id'] ?? 0)
                );
                $parentErr = $this->workplans->validateParentLinkage($scope, $newParent, (string) $currentLevel, (int) $existing['id']);
                if ($parentErr !== null) {
                    $this->error($parentErr, 403);
                }
            }
        }

        $fields[] = 'updated_at = NOW()';
        $params[] = $id;
        $types   .= 'i';

        $stmt = $this->db->prepare('UPDATE workplan_objectives SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();

        // Keep the derived cascade level consistent with the final assignment.
        $syncRow = $this->workplans->selectRows(
            'SELECT section_id, subsection_id, performance_contract_id FROM workplan_objectives WHERE id = ?',
            'i',
            [$id]
        )[0] ?? null;
        if ($syncRow) {
            $syncedLevel = $this->workplans->deriveLevel(
                $syncRow['section_id'] !== null ? (int) $syncRow['section_id'] : null,
                $syncRow['subsection_id'] !== null ? (int) $syncRow['subsection_id'] : null,
                $syncRow['performance_contract_id'] !== null ? (int) $syncRow['performance_contract_id'] : 0
            );
            if ($syncedLevel !== ($existing['level'] ?? null)) {
                $lvStmt = $this->db->prepare('UPDATE workplan_objectives SET level = ? WHERE id = ?');
                $lvStmt->bind_param('si', $syncedLevel, $id);
                $lvStmt->execute();
                $lvStmt->close();
            }
        }

        \App\Services\AuditService::getInstance()->log(
            \App\Services\AuditService::MODULE_PERFORMANCE,
            \App\Services\AuditService::ACTION_UPDATE,
            'Updated workplan objective #' . $id,
            ['target_type' => 'WorkplanObjective', 'target_id' => $id,
             'old_values' => array_intersect_key($existing, array_flip(['objective', 'kpi', 'measure_unit', 'status', 'progress_percent'])),
             'new_values' => array_intersect_key($data, array_flip(['objective', 'kpi', 'measure_unit', 'status', 'progress_percent'])),
            ]
        );

        $this->success(null, 'Workplan objective updated');
    }

    /**
     * DELETE /api/workplans/{id} - Delete a workplan objective and its
     * cycle links (child links only; contract/KPI data is untouched).
     */
    public function destroyAction(int $id): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canManagePerformance($scope)) {
            $this->forbidden('You do not have permission to manage workplans.');
        }

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare('UPDATE workplan_objectives SET soft_deleted = 1, updated_at = NOW() WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            $this->db->commit();

            if ($affected === 0) {
                $this->notFound('Workplan objective not found.');
            }

            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_PERFORMANCE,
                \App\Services\AuditService::ACTION_DELETE,
                'Soft-deleted workplan objective #' . $id,
                ['target_type' => 'WorkplanObjective', 'target_id' => $id]
            );

            $this->success(null, 'Workplan objective deleted');
        } catch (\Throwable $e) {
            $this->db->rollback();
            \logger()->error('Workplan delete error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to delete workplan objective.', 500);
        }
    }

    /**
     * GET /api/workplans/integrated-view - The unified, organisation-level
     * workplan grouped by Goal --> Strategic Target --> Department --> Activity.
     * Mirrors the legacy four-department integration: objectives flagged
     * is_integrated=1 are surfaced to organisation-wide viewers (PME, Audit,
     * HR, super admin, managing director).
     */
    public function integratedViewAction(): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canViewAny($scope)) {
            $this->forbidden('You do not have permission to view workplans.');
        }

        [$where, $params] = $this->workplans->workplanScope($scope);
        $where .= ' AND w.soft_deleted = 0';
        $types = str_repeat('i', count($params));

        $sql = "
            SELECT w.*,
                   COALESCE(w.goal_id, c.goal_id) AS gid,
                   COALESCE(w.strategic_target_id, c.target_id) AS tid,
                   g.name AS goal_name, t.name AS target_name,
                   d.name AS department_name, c.department_id,
                   s.name AS section_name, ss.name AS subsection_name,
                   c.name AS contract_name, c.financial_year_id
            FROM workplan_objectives w
            LEFT JOIN performance_contracts c ON w.performance_contract_id = c.id
            LEFT JOIN goals g ON COALESCE(w.goal_id, c.goal_id) = g.id
            LEFT JOIN strategic_targets t ON COALESCE(w.strategic_target_id, c.target_id) = t.id
            LEFT JOIN departments d ON c.department_id = d.id
            LEFT JOIN sections s ON w.section_id = s.id
            LEFT JOIN subsections ss ON w.subsection_id = ss.id
            WHERE $where
            ORDER BY g.id ASC, t.id ASC, d.id ASC, w.id ASC
        ";

        $stmt = $this->db->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $goals = [];
        $summary = [
            'total_objectives' => 0,
            'in_progress'       => 0,
            'completed'         => 0,
            'at_risk'           => 0,
            'total_budget'      => 0.0,
            'overdue'           => 0,
        ];
        $today = date('Y-m-d');

        foreach ($rows as $r) {
            $gid   = (int) ($r['gid'] ?? 0);
            $tid   = (int) ($r['tid'] ?? 0);
            $did   = (int) ($r['department_id'] ?? 0);

            if (!isset($goals[$gid])) {
                $goals[$gid] = ['id' => $gid, 'name' => $r['goal_name'] ?? 'Unassigned', 'targets' => []];
            }
            if (!isset($goals[$gid]['targets'][$tid])) {
                $goals[$gid]['targets'][$tid] = ['id' => $tid, 'name' => $r['target_name'] ?? 'Unassigned', 'departments' => []];
            }
            if (!isset($goals[$gid]['targets'][$tid]['departments'][$did])) {
                $goals[$gid]['targets'][$tid]['departments'][$did] = [
                    'id' => $did, 'name' => $r['department_name'] ?? 'Unassigned', 'items' => [],
                ];
            }
            $goals[$gid]['targets'][$tid]['departments'][$did]['items'][] = $r;

            $summary['total_objectives']++;
            $budget = (float) ($r['budget_amount'] ?? 0);
            $summary['total_budget'] += $budget;

            // Status rollups.
            switch ($r['status'] ?? '') {
                case 'completed': $summary['completed']++; break;
                case 'in_progress': $summary['in_progress']++; break;
                case 'at_risk':
                case 'off_track':
                    $summary['at_risk']++; break;
            }
            // Overdue when planned end has passed and not completed.
            if (in_array($r['status'] ?? '', ['not_started', 'in_progress', 'at_risk', 'off_track'], true)
                && !empty($r['planned_end_date']) && $r['planned_end_date'] < $today) {
                $summary['overdue']++;
            }
        }
        $summary['budget_total'] = round($summary['total_budget'], 2);
        unset($summary['total_budget']);

        // Fold nested arrays into sorted lists.
        $goalList = [];
        foreach ($goals as $g) {
            $g['targets'] = array_values($g['targets']);
            foreach ($g['targets'] as $i => $t) {
                $g['targets'][$i]['departments'] = array_values($t['departments']);
            }
            $goalList[] = $g;
        }

        $this->success([
            'goals'      => $goalList,
            'summary'    => $summary,
            'can_manage' => OrgScope::canManagePerformance($scope),
        ]);
    }

    /**
     * GET /api/workplans/{id}/progress-history - Chronological progress
     * updates for a single objective (from workplan_logs).
     */
    public function progressHistoryAction(int $id): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canViewAny($scope)) {
            $this->forbidden('You do not have permission to view workplans.');
        }
        if (!$this->workplans->objectiveExists($id)) {
            $this->notFound('Workplan objective not found.');
        }

        $stmt = $this->db->prepare(
            "SELECT wl.*, u.first_name, u.last_name, u.surname
             FROM workplan_logs wl
             LEFT JOIN users u ON wl.user_id = u.id
             WHERE wl.objective_id = ?
             ORDER BY wl.created_at ASC, wl.id ASC"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as &$r) {
            $r['actor_name'] = $this->workplans->actorName($r);
            unset($r['first_name'], $r['last_name'], $r['surname']);
            $r['old_values'] = $this->workplans->decodeJsonField($r['old_values']);
            $r['new_values'] = $this->workplans->decodeJsonField($r['new_values']);
        }
        unset($r);

        $this->success(['updates' => $rows]);
    }

    /**
     * PUT /api/workplans/{id}/progress - Record a progress update / status
     * change / evidence upload, pushing a workplan_logs row and updating the
     * objective's current progress fields.
     */
    public function progressUpdateAction(int $id): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canManagePerformance($scope)) {
            $this->forbidden('You do not have permission to update workplans.');
        }
        $data = $this->getJsonBody();

        $cur = $this->db->prepare('SELECT * FROM workplan_objectives WHERE id = ? AND soft_deleted = 0');
        $cur->bind_param('i', $id);
        $cur->execute();
        $current = $cur->get_result()->fetch_assoc();
        $cur->close();
        if (!$current) {
            $this->notFound('Workplan objective not found.');
        }

        $oldPct    = (int) ($current['progress_percent'] ?? 0);
        $oldStatus = (string) ($current['status'] ?? 'not_started');
        $oldEvid   = (string) ($current['evidence_path'] ?? '');

        $newPct    = array_key_exists('progress_percent', $data) ? (int) $data['progress_percent'] : $oldPct;
        $newPct    = max(0, min(100, $newPct));
        $newStatus = isset($data['status']) ? (string) $data['status'] : $oldStatus;
        if (!in_array($newStatus, ['not_started', 'in_progress', 'completed', 'at_risk', 'off_track'], true)) {
            $newStatus = $oldStatus;
        }
        $newEvid   = isset($data['evidence_path']) ? trim((string) $data['evidence_path']) : $oldEvid;
        $desc      = isset($data['description']) ? trim((string) $data['description']) : 'Progress updated';

        $actionType = 'progress_update';
        if ($newPct === 100 || $newStatus === 'completed') {
            $actionType = 'status_change';
        }

        $this->db->begin_transaction();
        try {
            $up = $this->db->prepare(
                'UPDATE workplan_objectives
                 SET progress_percent = ?, status = ?, evidence_path = ?,
                     actual_completion_date = IF(? = 100, COALESCE(actual_completion_date, CURDATE()), actual_completion_date),
                     updated_at = NOW()
                 WHERE id = ?'
            );
            $up->bind_param('issii', $newPct, $newStatus, $newEvid, $newPct, $id);
            $up->execute();
            $up->close();

            $log = $this->db->prepare(
                'INSERT INTO workplan_logs
                    (objective_id, user_id, action_type, old_values, new_values,
                     progress_percent, status, evidence_path, description)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $userId = $this->getUserId() ?: null;
            $oldJson = json_encode(['progress_percent' => $oldPct, 'status' => $oldStatus, 'evidence_path' => $oldEvid]);
            $newJson = json_encode(['progress_percent' => $newPct, 'status' => $newStatus, 'evidence_path' => $newEvid]);
            $log->bind_param('iisssisss', $id, $userId, $actionType, $oldJson, $newJson, $newPct, $newStatus, $newEvid, $desc);
            $log->execute();
            $log->close();

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            \logger()->error('Workplan progress update error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to update workplan progress.', 500);
        }

        \App\Services\AuditService::getInstance()->log(
            \App\Services\AuditService::MODULE_PERFORMANCE,
            \App\Services\AuditService::ACTION_STATUS_CHANGE,
            'Updated progress on workplan objective #' . $id . " to {$newPct}% ({$newStatus})",
            ['target_type' => 'WorkplanObjective', 'target_id' => $id,
             'old_values' => ['progress_percent' => $oldPct, 'status' => $oldStatus],
             'new_values' => ['progress_percent' => $newPct, 'status' => $newStatus],
            ]
        );

        $this->success([
            'id' => $id, 'progress_percent' => $newPct, 'status' => $newStatus,
        ], 'Workplan progress updated');
    }

    /**
     * GET /api/workplans/{id}/dependencies - Resolve the cross-workplan
     * dependency links stored in the objective's `dependencies` JSON, enriching
     * each link with the referenced objective's description and department.
     */
    public function dependenciesAction(int $id): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canViewAny($scope)) {
            $this->forbidden('You do not have permission to view workplans.');
        }
        if (!$this->workplans->objectiveExists($id)) {
            $this->notFound('Workplan objective not found.');
        }

        $stmt = $this->db->prepare('SELECT dependencies FROM workplan_objectives WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $deps = [];
        if (!empty($row['dependencies'])) {
            $decoded = json_decode($row['dependencies'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $link) {
                    $targetId = (int) ($link['workplan_objective_id'] ?? 0);
                    $deps[] = [
                        'workplan_objective_id' => $targetId,
                        'type'     => $link['type'] ?? 'dependency',
                        'description' => $link['description'] ?? '',
                        'resolved' => $targetId > 0 ? $this->workplans->objectiveLabel($targetId) : null,
                    ];
                }
            }
        }

        $this->success(['dependencies' => $deps]);
    }

    /**
     * GET /api/workplans/export - Consolidated CSV export of workplan
     * objectives within the caller's organisational scope, across all four
     * legacy departmental workplans. Requires ?format=csv.
     */
    public function exportAction(): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canViewAny($scope)) {
            $this->forbidden('You do not have permission to view workplans.');
        }

        [$where, $params] = $this->workplans->workplanScope($scope);
        $where .= ' AND w.soft_deleted = 0';
        $types = str_repeat('i', count($params));

        $sql = "
            SELECT w.id, w.objective, w.kpi, w.measure_unit, w.progress_percent, w.status,
                   w.budget_amount, w.planned_start_date, w.planned_end_date,
                   w.actual_completion_date, w.is_integrated,
                   c.name AS contract_name, c.department_id, d.name AS department_name,
                   g.name AS goal_name, t.name AS target_name,
                   fy.year_name AS financial_year_name,
                   s.name AS section_name, ss.name AS subsection_name
            FROM workplan_objectives w
            LEFT JOIN performance_contracts c ON w.performance_contract_id = c.id
            LEFT JOIN departments d ON c.department_id = d.id
            LEFT JOIN goals g ON c.goal_id = g.id
            LEFT JOIN strategic_targets t ON COALESCE(w.strategic_target_id, c.target_id) = t.id
            LEFT JOIN financial_years fy ON c.financial_year_id = fy.id
            LEFT JOIN sections s ON w.section_id = s.id
            LEFT JOIN subsections ss ON w.subsection_id = ss.id
            WHERE $where
            ORDER BY c.department_id ASC, w.id ASC
        ";

        $stmt = $this->db->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // CSV headers.
        $headers = [
            'ID', 'Department', 'Financial Year', 'Contract', 'Objective', 'KPI',
            'Measure Unit', 'Section', 'Subsection', 'Goal', 'Target',
            'Progress %', 'Status', 'Budget', 'Planned Start', 'Planned End',
            'Actual Completion', 'Integrated',
        ];

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        $ts = date('Ymd_His');
        header('Content-Disposition: attachment; filename="workplans_' . $ts . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, $headers);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['id'], $r['department_name'] ?? 'Unassigned', $r['financial_year_name'] ?? '',
                $r['contract_name'] ?? '', $r['objective'] ?? '', $r['kpi'] ?? '',
                $r['measure_unit'] ?? '', $r['section_name'] ?? '', $r['subsection_name'] ?? '',
                $r['goal_name'] ?? '', $r['target_name'] ?? '',
                (int) ($r['progress_percent'] ?? 0), $r['status'] ?? '',
                $r['budget_amount'] ?? '0.00', $r['planned_start_date'] ?? '',
                $r['planned_end_date'] ?? '', $r['actual_completion_date'] ?? '',
                ((int) ($r['is_integrated'] ?? 0) === 1) ? 'Yes' : 'No',
            ]);
        }
        fclose($out);

        \App\Services\AuditService::getInstance()->log(
            \App\Services\AuditService::MODULE_PERFORMANCE,
            \App\Services\AuditService::ACTION_EXPORT,
            'Exported workplan objectives (' . count($rows) . ' rows)',
            ['target_type' => 'WorkplanExport']
        );
        exit();
    }
// ========================================================================
    // Private helpers
    // ========================================================================

    public function summaryAction(): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canViewAny($scope)) {
            $this->forbidden('You do not have permission to view workplans.');
        }

        try {
            $view = (string) ($_GET['view'] ?? $this->workplans->defaultView($scope));
            $allowedViews = ['md', 'department', 'section', 'subsection', 'integrated'];
            $view = in_array($view, $allowedViews, true) ? $view : $this->workplans->defaultView($scope);

                        [$where, $params] = $this->workplans->viewScope($scope, $view);
            $where .= ' AND w.soft_deleted = 0';

            // Default to the currently-active financial year so the dashboard
            // only shows the live workplan unless a specific FY is requested.
            $fyWhere = '';
            if (isset($_GET['financial_year_id']) && $_GET['financial_year_id'] !== '') {
                $fyWhere = ' AND c.financial_year_id = ?';
                $params[] = (int) $_GET['financial_year_id'];
            } elseif ($view === 'md') {
                // Broad (MD) view: pin to the active FY by default so the feeds
                // don't scatter across every historical year.
                $activeFy = $this->workplans->selectRows(
                    'SELECT id FROM financial_years WHERE is_active = 1 ORDER BY start_date DESC LIMIT 1'
                );
                if ($activeFy) {
                    $fyWhere = ' AND c.financial_year_id = ?';
                    $params[] = (int) $activeFy[0]['id'];
                }
            }
            $types = str_repeat('i', count($params));

            $fromWhere = "
                FROM workplan_objectives w
                LEFT JOIN performance_contracts c ON w.performance_contract_id = c.id
                LEFT JOIN sections s ON w.section_id = s.id
                LEFT JOIN subsections ss ON w.subsection_id = ss.id
                WHERE $where$fyWhere";

            // Aggregate totals / status breakdown / cascade split in one pass.
            $aggSql = "
                SELECT COUNT(*) AS total_activities,
                       COALESCE(SUM(w.status = 'not_started'), 0) AS not_started,
                       COALESCE(SUM(w.status = 'in_progress'), 0) AS in_progress,
                       COALESCE(SUM(w.status = 'completed'), 0) AS completed,
                       COALESCE(SUM(w.status = 'at_risk'), 0) AS at_risk,
                       COALESCE(SUM(w.status = 'off_track'), 0) AS off_track,
                       COALESCE(AVG(w.progress_percent), 0) AS completion_rate,
                       COALESCE(SUM(w.parent_objective_id IS NOT NULL), 0) AS cascaded_count,
                       COALESCE(SUM(w.parent_objective_id IS NULL), 0) AS local_count,
                       COALESCE(SUM(w.parent_objective_id IS NOT NULL AND w.status = 'not_started'), 0) AS awaiting_action,
                       COALESCE(SUM(w.planned_end_date IS NOT NULL AND w.planned_end_date < CURDATE()
                                 AND w.status <> 'completed'), 0) AS overdue_count,
                       COALESCE(SUM(w.budget_amount), 0) AS budget_total
                $fromWhere";
            $aggStmt = $this->db->prepare($aggSql);
            if ($params) {
                $aggStmt->bind_param($types, ...$params);
            }
            $aggStmt->execute();
            $totals = $aggStmt->get_result()->fetch_assoc();
            $aggStmt->close();

            // Upcoming deadlines (next 30 days, unfinished work only).
            $upcomingSql = "
                SELECT w.id, w.objective, w.level, w.status, w.progress_percent, w.planned_end_date,
                       s.name AS section_name, ss.name AS subsection_name
                $fromWhere
                  AND w.planned_end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                  AND w.status <> 'completed'
                ORDER BY w.planned_end_date ASC
                LIMIT 8";
            $upcoming = $this->workplans->selectRows($upcomingSql, $types, $params);

            // Recently updated activities for the activity feed.
            $recentSql = "
                SELECT w.id, w.objective, w.level, w.status, w.progress_percent, w.updated_at,
                       s.name AS section_name, ss.name AS subsection_name
                $fromWhere
                ORDER BY w.updated_at DESC
                LIMIT 5";
            $recent = $this->workplans->selectRows($recentSql, $types, $params);

            // Currently active financial year (workplan period).
            $fyRows = $this->workplans->selectRows(
                'SELECT id, year_name, start_date, end_date FROM financial_years WHERE is_active = 1 ORDER BY start_date DESC LIMIT 1'
            );

            $this->success([
                'view'            => $view,
                'default_view'    => $this->workplans->defaultView($scope),
                'available_views' => $this->workplans->availableViews($scope),
                'can_manage'      => OrgScope::canManagePerformance($scope),
                'unit_label'      => $this->workplans->unitLabel($scope, $view),
                'totals'          => [
                    'total_activities' => (int) ($totals['total_activities'] ?? 0),
                    'not_started'      => (int) ($totals['not_started'] ?? 0),
                    'in_progress'      => (int) ($totals['in_progress'] ?? 0),
                    'completed'        => (int) ($totals['completed'] ?? 0),
                    'at_risk'          => (int) ($totals['at_risk'] ?? 0),
                    'off_track'        => (int) ($totals['off_track'] ?? 0),
                    'completion_rate'  => round((float) ($totals['completion_rate'] ?? 0), 1),
                    'cascaded_count'   => (int) ($totals['cascaded_count'] ?? 0),
                    'local_count'      => (int) ($totals['local_count'] ?? 0),
                    'awaiting_action'  => (int) ($totals['awaiting_action'] ?? 0),
                    'overdue_count'    => (int) ($totals['overdue_count'] ?? 0),
                    'budget_total'     => (float) ($totals['budget_total'] ?? 0),
                ],
                'upcoming_deadlines'    => $upcoming,
                'recently_updated'      => $recent,
                'active_financial_year' => $fyRows[0] ?? null,
                'scope'                 => [
                    'role'       => $scope['role'],
                    'department' => $scope['department_id'],
                    'section'    => $scope['section_id'],
                    'subsection' => $scope['subsection_id'],
                ],
            ]);
        } catch (\Throwable $e) {
            \logger()->error('Workplan summary error', ['error' => $e->getMessage()]);
            $this->error('Failed to build the workplan summary.', 500);
        }
        }

    /**
     * GET /api/workplans/section-sources — Returns only the cascaded parent
     * activities that the caller may create child activities under (the
     * "Source Activity (added by management)" list) for section / subsection
     * heads.
     *
     * Unlike the general list endpoint + client-side filter, this is filtered
     * server-side so that:
     *  - a section head only sees section-level activities cascaded to their
     *    OWN section by their department head (never neighbour departments),
     *  - a subsection head only sees subsection-level activities cascaded to
     *    their OWN subsection by their section head,
     *  - the caller's own locally-created activities are excluded so only the
     *    management-cascaded sources remain.
     *
     * The created_by filter runs in the database (integer comparison), avoiding
     * the string-vs-integer strict-inequality pitfall that the client-side
     * filter suffered from.
     */
    public function sectionSourcesAction(): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canViewAny($scope)) {
            $this->forbidden('You do not have permission to view workplan sources.');
        }

        $view = (string) ($_GET['view'] ?? $this->workplans->defaultView($scope));
        if (!in_array($view, ['section', 'subsection'], true)) {
            $view = $this->workplans->defaultView($scope);
        }

        try {
            $userId = $this->getUserId() ?: 0;

            // Role-aware unit scope (same logic as listAction's viewScope)
            // guarantees the section/subsection head never sees other units.
            [$scopeWhere, $scopeParams] = $this->workplans->viewScope($scope, $view);

            $where  = $scopeWhere . ' AND w.parent_objective_id IS NOT NULL AND w.soft_deleted = 0';
            $params = $scopeParams;
            $types  = str_repeat('i', count($scopeParams));

            // Only activities the caller did NOT create themselves — i.e. the
            // ones their supervisor cascaded to them.
            $where  .= ' AND w.created_by != ?';
            $params[] = $userId;
            $types   .= 'i';

            $rows = $this->workplans->selectRows(
                "SELECT w.id, w.objective, w.level,
                        CONCAT_WS(' ', u.first_name, u.last_name, u.surname) AS created_by_name
                 FROM workplan_objectives w
                 LEFT JOIN users u ON w.created_by = u.id
                 WHERE {$where}
                 ORDER BY w.created_at DESC, w.id ASC
                 LIMIT 200",
                $types,
                $params
            );

            $sources = array_map(function (array $r): array {
                return [
                    'id'              => (int) $r['id'],
                    'objective'       => (string) ($r['objective'] ?? ''),
                    'level'           => (string) ($r['level'] ?? ''),
                    'created_by_name' => (string) ($r['created_by_name'] ?? ''),
                ];
            }, $rows);

            $this->success([
                'sources'    => $sources,
                'can_manage' => true,
                'view'       => $view,
            ]);
        } catch (\Throwable $e) {
            \logger()->error('Workplan section sources error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve section sources.', 500);
        }
    }

    /**
     * POST /api/workplans/{id}/cascade - Create a validated CHILD activity
     * beneath a parent objective. The child inherits the parent's strategic
     * alignment, keeps a permanent parent_objective_id lineage reference and
     * may only be placed inside units the caller is allowed to cascade to.
     * Cascading never duplicates the parent record - it creates a linked,
     * lower-level activity in the same hierarchy.
     */
    public function cascadeAction(int $id): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canManagePerformance($scope)) {
            $this->forbidden('You do not have permission to manage workplans.');
        }
        // Cascading downward is a leadership action.
        if (!$this->workplans->isBroadWorkplan($scope) && !$scope['is_dept_head']
            && !$scope['is_section_head'] && !$scope['is_sub_section_head']) {
            $this->forbidden('Only unit heads may cascade objectives downward.');
        }

        $data = $this->getJsonBody();

        $parentRows = $this->workplans->selectRows(
            "SELECT w.*, c.department_id AS contract_department_id,
                    c.goal_id AS contract_goal_id
             FROM workplan_objectives w
             LEFT JOIN performance_contracts c ON w.performance_contract_id = c.id
             WHERE w.id = ? AND w.soft_deleted = 0
             LIMIT 1",
            'i',
            [$id]
        );
        $parent = $parentRows[0] ?? null;
        if (!$parent) {
            $this->notFound('Workplan objective not found.');
        }

        // The caller must be able to see the parent inside their own scope -
        // cascading another department's work is never allowed.
        if (!$this->workplans->objectiveWithinScope($scope, [
            'section_id'             => $parent['section_id'],
            'subsection_id'          => $parent['subsection_id'],
            'contract_department_id' => $parent['contract_department_id'],
        ])) {
            $this->forbidden('You may only cascade objectives that belong to your own organisational unit.');
        }

        $objective = trim((string) ($data['objective'] ?? ''));
        $kpi       = trim((string) ($data['kpi'] ?? ''));
        $measure   = trim((string) ($data['measure_unit'] ?? ''));
        // Legacy parity: breakdown rows carry description + subsection + cycles;
        // KPI / measure stay optional.
        if ($objective === '') {
            $this->error('The activity description is required.', 422);
        }

        $sectionId    = isset($data['section_id']) && (int) $data['section_id'] > 0 ? (int) $data['section_id'] : null;
        $subsectionId = isset($data['subsection_id']) && (int) $data['subsection_id'] > 0 ? (int) $data['subsection_id'] : null;
        $officerId    = isset($data['responsible_officer_id']) && (int) $data['responsible_officer_id'] > 0
            ? (int) $data['responsible_officer_id'] : null;

        // Server-side pinning for heads of smaller units.
        if ($scope['is_sub_section_head']) {
            $sectionId    = $scope['section_id'] !== null ? (int) $scope['section_id'] : null;
            $subsectionId = $scope['subsection_id'] !== null ? (int) $scope['subsection_id'] : null;
        } elseif ($scope['is_section_head'] && $scope['section_id'] !== null) {
            $sectionId = (int) $scope['section_id'];
        }

        // Cascade boundary checks (department/section/subsection ownership),
        // evaluated against the merged placement context.
        $assignData = [];
        if ($sectionId !== null) {
            $assignData['section_id'] = $sectionId;
        }
        if ($subsectionId !== null) {
            $assignData['subsection_id'] = $subsectionId;
        }
        $assignErr = $assignData
            ? $this->workplans->validateCascadeAssignment($scope, array_merge($parent, $assignData))
            : null;
        if ($assignErr !== null) {
            $this->error($assignErr, 403);
        }

        // Employee assignment (employee-level tasks): the officer must belong
        // to the unit the child activity is being placed in.
        if ($officerId !== null) {
            $empErr = $this->workplans->validateOfficerPlacement($scope, $officerId, $subsectionId, $sectionId);
            if ($empErr !== null) {
                $this->error($empErr, 403);
            }
        }

        // Inherit strategic alignment from the parent unless explicitly
        // overridden by the caller.
        $contractId = isset($data['performance_contract_id']) && (int) $data['performance_contract_id'] > 0
            ? (int) $data['performance_contract_id']
            : ($parent['performance_contract_id'] !== null ? (int) $parent['performance_contract_id'] : 0);
        if ($contractId > 0 && !$this->workplans->rowExists('performance_contracts', $contractId)) {
            $this->error('Selected performance contract does not exist.', 422);
        }
        $goalId = isset($data['goal_id']) && (int) $data['goal_id'] > 0
            ? (int) $data['goal_id']
            : ($parent['goal_id'] !== null
                ? (int) $parent['goal_id']
                : ($parent['contract_goal_id'] !== null ? (int) $parent['contract_goal_id'] : null));
        $targetId = isset($data['strategic_target_id']) && (int) $data['strategic_target_id'] > 0
            ? (int) $data['strategic_target_id']
            : ($parent['strategic_target_id'] !== null ? (int) $parent['strategic_target_id'] : null);

        if ($targetId !== null && $goalId === null) {
            $tRow = $this->workplans->selectRows('SELECT goal_id FROM strategic_targets WHERE id = ?', 'i', [$targetId]);
            if ($tRow) {
                $goalId = (int) $tRow[0]['goal_id'];
            }
        }

        // Optional planning fields (dates default from nothing; budget optional).
        $pStart    = isset($data['planned_start_date']) && $data['planned_start_date'] !== '' ? (string) $data['planned_start_date'] : null;
        $pEnd      = isset($data['planned_end_date']) && $data['planned_end_date'] !== '' ? (string) $data['planned_end_date'] : null;
        $budget    = isset($data['budget_amount']) && $data['budget_amount'] !== '' ? (float) $data['budget_amount'] : 0.00;
        $resources = isset($data['resource_notes']) && trim((string) $data['resource_notes']) !== ''
            ? trim((string) $data['resource_notes']) : null;

        // The child must sit strictly below its parent in the cascade.
        $level = $this->workplans->deriveLevel($sectionId, $subsectionId, $contractId);
        $parentErr = $this->workplans->validateParentLinkage($scope, (int) $parent['id'], $level);
        if ($parentErr !== null) {
            $this->error($parentErr, 403);
        }

        $status    = 'not_started';
        $progress  = 0;
        $createdBy = $this->getUserId();
        // Cycles: accept an explicit list from the caller (section / subsection
        // heads pick their own quarters); otherwise inherit the parent's.
        $cycleRaw  = $data['cycle_ids'] ?? null;
        if (is_array($cycleRaw)) {
            $cycleIds = implode(',', array_filter(array_map('intval', $cycleRaw)));
        } else {
            $cycleIds = trim((string) $cycleRaw);
        }
        if ($cycleIds === '') {
            $cycleIds = (string) ($parent['cycle_ids'] ?? '');
        }
        $pcVal     = $contractId > 0 ? $contractId : null;
        $parentId2 = (int) $parent['id'];

        $stmt = $this->db->prepare(
            "INSERT INTO workplan_objectives
                (performance_contract_id, objective, kpi, measure_unit, section_id,
                 subsection_id, cycle_ids, goal_id, strategic_target_id,
                 parent_objective_id, responsible_officer_id,
                 progress_percent, status, budget_amount, resource_notes,
                 planned_start_date, planned_end_date, level, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $stmt->bind_param(
            'isssiisiiiiisdsssssi',
            $pcVal, $objective, $kpi, $measure, $sectionId, $subsectionId,
            $cycleIds, $goalId, $targetId,
            $parentId2, $officerId,
            $progress, $status, $budget, $resources,
            $pStart, $pEnd, $level, $createdBy
        );
        $stmt->execute();
        $newId = (int) $this->db->insert_id;
        $stmt->close();

        // Dedicated cascade entry in the workplan audit trail.
        $actionType  = 'cascaded';
        $newValues   = json_encode([
            'child_id'               => $newId,
            'parent_id'              => $parentId2,
            'parent_level'           => $parent['level'],
            'child_level'            => $level,
            'section_id'             => $sectionId,
            'subsection_id'          => $subsectionId,
            'responsible_officer_id' => $officerId,
        ], JSON_UNESCAPED_SLASHES);
        $description = 'Cascaded from objective #' . $parentId2 . ': ' . mb_substr((string) $parent['objective'], 0, 120);
        $logStmt = $this->db->prepare(
            'INSERT INTO workplan_logs (objective_id, user_id, action_type, old_values, new_values, progress_percent, status, description)
             VALUES (?, ?, ?, NULL, ?, ?, ?, ?)'
        );
        $logStmt->bind_param('iississ', $newId, $createdBy, $actionType, $newValues, $progress, $status, $description);
        $logStmt->execute();
        $logStmt->close();

        \App\Services\AuditService::getInstance()->log(
            \App\Services\AuditService::MODULE_PERFORMANCE,
            \App\Services\AuditService::ACTION_CREATE,
            'Cascaded workplan activity #' . $parentId2 . ' to ' . $level . ' level',
            ['target_type' => 'WorkplanObjective', 'target_id' => $newId, 'new_values' => [
                'parent_objective_id' => $parentId2, 'level' => $level,
            ]]
        );

        $this->success(
            ['id' => $newId, 'level' => $level, 'parent_id' => $parentId2],
            'Activity cascaded successfully',
            201
        );
    }

    public function traceabilityAction(int $id): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canViewAny($scope)) {
            $this->forbidden('You do not have permission to view workplans.');
        }

        try {
            $rootRows = $this->workplans->selectRows(
                "SELECT w.id, w.objective, w.kpi, w.measure_unit, w.level,
                        w.status, w.progress_percent, w.parent_objective_id,
                        w.section_id, w.subsection_id,
                        w.planned_start_date, w.planned_end_date, w.actual_completion_date,
                        s.name AS section_name, ss.name AS subsection_name,
                        c.name AS contract_name, c.kra AS contract_kra,
                        c.department_id AS contract_department_id,
                        d.name AS department_name, fy.year_name AS financial_year_name,
                        gg.name AS goal_name, gg.strategic_plan_id AS goal_strategic_plan_id,
                        t.name AS target_name, sp.name AS strategic_plan_name,
                        CONCAT_WS(' ', u.first_name, u.last_name, u.surname) AS created_by_name,
                        po.objective AS parent_objective
                 FROM workplan_objectives w
                 LEFT JOIN sections s ON w.section_id = s.id
                 LEFT JOIN subsections ss ON w.subsection_id = ss.id
                 LEFT JOIN performance_contracts c ON w.performance_contract_id = c.id
                 LEFT JOIN departments d ON c.department_id = d.id
                 LEFT JOIN financial_years fy ON c.financial_year_id = fy.id
                 LEFT JOIN goals gg ON gg.id = COALESCE(w.goal_id, c.goal_id)
                 LEFT JOIN strategic_targets t ON w.strategic_target_id = t.id
                 LEFT JOIN strategic_plan sp ON sp.id = COALESCE(t.strategic_plan_id, gg.strategic_plan_id)
                 LEFT JOIN users u ON w.created_by = u.id
                 LEFT JOIN workplan_objectives po ON w.parent_objective_id = po.id
                 WHERE w.id = ? AND w.soft_deleted = 0
                 LIMIT 1",
                'i',
                [$id]
            );
            $root = $rootRows[0] ?? null;
            if (!$root) {
                $this->notFound('Workplan objective not found.');
            }

            if (!$this->workplans->objectiveWithinScope($scope, [
                'section_id'             => $root['section_id'],
                'subsection_id'          => $root['subsection_id'],
                'contract_department_id' => $root['contract_department_id'],
            ])) {
                $this->forbidden('This activity is outside your organisational scope.');
            }

            // Ancestor chain (walked bottom-up, presented top-down).
            $ancestors = [];
            $cursorPid = $root['parent_objective_id'] !== null ? (int) $root['parent_objective_id'] : null;
            $guard     = 0;
            while ($cursorPid !== null && $guard++ < 15) {
                $row = $this->workplans->selectRows(
                    "SELECT w.id, w.objective, w.level, w.status, w.progress_percent,
                            w.section_id, w.subsection_id, w.parent_objective_id,
                            s.name AS section_name, ss.name AS subsection_name,
                            c.name AS contract_name,
                            c.department_id AS contract_department_id,
                            d.name AS department_name
                     FROM workplan_objectives w
                     LEFT JOIN sections s ON w.section_id = s.id
                     LEFT JOIN subsections ss ON w.subsection_id = ss.id
                     LEFT JOIN performance_contracts c ON w.performance_contract_id = c.id
                     LEFT JOIN departments d ON c.department_id = d.id
                     WHERE w.id = ? AND w.soft_deleted = 0
                     LIMIT 1",
                    'i',
                    [$cursorPid]
                )[0] ?? null;
                if (!$row) {
                    break;
                }
                array_unshift($ancestors, [
                    'id'               => (int) $row['id'],
                    'objective'        => $row['objective'],
                    'level'            => $row['level'],
                    'status'           => $row['status'],
                    'progress_percent' => (int) $row['progress_percent'],
                    'owner'            => $row['subsection_name'] ?: ($row['section_name'] ?: ($row['department_name'] ?: null)),
                    'contract_name'    => $row['contract_name'],
                ]);
                $cursorPid = $row['parent_objective_id'] !== null ? (int) $row['parent_objective_id'] : null;
            }

            // Descendant tree, depth-limited to three levels below the root.
            $fetchChildren = function (int $pid, int $depth) use (&$fetchChildren): array {
                $kids = $this->workplans->selectRows(
                    "SELECT w.id, w.objective, w.level, w.status, w.progress_percent,
                            w.responsible_officer_id, w.planned_end_date,
                            CONCAT_WS(' ', e.first_name, e.last_name, e.surname) AS officer_name,
                            s.name AS section_name, ss.name AS subsection_name,
                            (SELECT COUNT(*) FROM workplan_objectives gc
                              WHERE gc.parent_objective_id = w.id AND gc.soft_deleted = 0) AS children_count
                     FROM workplan_objectives w
                     LEFT JOIN employees e ON w.responsible_officer_id = e.id
                     LEFT JOIN sections s ON w.section_id = s.id
                     LEFT JOIN subsections ss ON w.subsection_id = ss.id
                     WHERE w.parent_objective_id = ? AND w.soft_deleted = 0
                     ORDER BY w.id ASC",
                    'i',
                    [$pid]
                );
                $out = [];
                foreach ($kids as $k) {
                    $out[] = [
                        'id'               => (int) $k['id'],
                        'objective'        => $k['objective'],
                        'level'            => $k['level'],
                        'status'           => $k['status'],
                        'progress_percent' => (int) $k['progress_percent'],
                        'planned_end_date' => $k['planned_end_date'],
                        'officer_name'     => $k['officer_name'] !== '' ? $k['officer_name'] : null,
                        'owner'            => $k['subsection_name'] ?: ($k['section_name'] ?: null),
                        'children_count'   => (int) $k['children_count'],
                        'children'         => ((int) $k['children_count'] > 0 && $depth > 1)
                            ? $fetchChildren((int) $k['id'], $depth - 1)
                            : [],
                    ];
                }
                return $out;
            };

            $context = [
                'strategic_plan'       => $root['strategic_plan_name'],
                'goal'                 => $root['goal_name'],
                'target'               => $root['target_name'],
                'performance_contract' => $root['contract_name'],
                'kra'                  => $root['contract_kra'],
                'department'           => $root['department_name'],
                'financial_year'       => $root['financial_year_name'],
                'section'              => $root['section_name'],
                'subsection'           => $root['subsection_name'],
                'parent_objective'     => $root['parent_objective'],
                'created_by_name'      => $root['created_by_name'] !== '' ? $root['created_by_name'] : null,
            ];

            $this->success([
                'objective'   => [
                    'id'                     => (int) $root['id'],
                    'objective'              => $root['objective'],
                    'kpi'                    => $root['kpi'],
                    'measure_unit'           => $root['measure_unit'],
                    'level'                  => $root['level'],
                    'status'                 => $root['status'],
                    'progress_percent'       => (int) $root['progress_percent'],
                    'planned_start_date'     => $root['planned_start_date'],
                    'planned_end_date'       => $root['planned_end_date'],
                    'actual_completion_date' => $root['actual_completion_date'],
                    'owner'                  => $root['subsection_name'] ?: ($root['section_name'] ?: ($root['department_name'] ?: null)),
                ],
                'context'     => $context,
                'ancestors'   => $ancestors,
                'descendants' => $fetchChildren((int) $root['id'], 3),
            ]);
        } catch (\Throwable $e) {
            \logger()->error('Workplan traceability error', ['error' => $e->getMessage()]);
            $this->error('Failed to build traceability.', 500);
        }
    }

    /**
     * POST /api/workplans/bulk - Legacy-parity departmental batch creation:
     * ONE performance contract -> MANY activities, each targeted to its own
     * section (and optionally subsection) with its own appraisal cycles.
     * Body: { performance_contract_id, items: [{ objective, kpi?, measure_unit?,
     *         section_id?, subsection_id?, cycle_ids?, planned_*? }] }
     */
    public function bulkAction(): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canManagePerformance($scope)) {
            $this->forbidden('You do not have permission to manage workplans.');
        }
        if (!$this->workplans->isBroadWorkplan($scope) && !$scope['is_dept_head']) {
            $this->forbidden('Only department heads may create departmental workplans.');
        }

        $data       = $this->getJsonBody();
        $contractId = (int) ($data['performance_contract_id'] ?? 0);
        $items      = $data['items'] ?? [];

        if ($contractId <= 0 || !is_array($items) || count($items) === 0) {
            $this->error('A performance contract and at least one activity are required.', 422);
        }

        // Contract ownership: broad roles may use any contract; department
        // heads only the contracts of their own department.
        $cRow = $this->workplans->selectRows(
            'SELECT id, goal_id, department_id FROM performance_contracts WHERE id = ? LIMIT 1',
            'i',
            [$contractId]
        )[0] ?? null;
        if (!$cRow) {
            $this->error('Selected performance contract does not exist.', 422);
        }
        if (!$this->workplans->isBroadWorkplan($scope)) {
            if ($scope['department_id'] === null || (int) $cRow['department_id'] !== (int) $scope['department_id']) {
                $this->forbidden("You can only create workplans under your own department's contracts.");
            }
        }

        $createdBy = $this->getUserId();
        $saved     = 0;
        $failed    = 0;
        $lastError = '';

        foreach ($items as $item) {
            if (!is_array($item)) { $failed++; continue; }

            $objective = trim((string) ($item['objective'] ?? ''));
            $kpi       = trim((string) ($item['kpi'] ?? ''));
            $measure   = trim((string) ($item['measure_unit'] ?? ''));
            if ($objective === '') { $failed++; continue; }

            $sectionId    = isset($item['section_id']) && (int) $item['section_id'] > 0 ? (int) $item['section_id'] : null;
            $subsectionId = isset($item['subsection_id']) && (int) $item['subsection_id'] > 0 ? (int) $item['subsection_id'] : null;

            // Cascade boundaries per row (dept head -> own sections/subsections).
            $assignData = [];
            if ($sectionId !== null) { $assignData['section_id'] = $sectionId; }
            if ($subsectionId !== null) { $assignData['subsection_id'] = $subsectionId; }
            $assignErr = $assignData ? $this->workplans->validateCascadeAssignment($scope, $assignData) : null;
            if ($assignErr !== null) { $failed++; $lastError = $assignErr; continue; }

            $cycleRaw = $item['cycle_ids'] ?? '';
            if (is_array($cycleRaw)) {
                $cycleIds = implode(',', array_filter(array_map('intval', $cycleRaw)));
            } else {
                $cycleIds = trim((string) $cycleRaw);
            }

            $level  = $this->workplans->deriveLevel($sectionId, $subsectionId, $contractId);
            $pStart = isset($item['planned_start_date']) && $item['planned_start_date'] !== '' ? (string) $item['planned_start_date'] : null;
            $pEnd   = isset($item['planned_end_date']) && $item['planned_end_date'] !== '' ? (string) $item['planned_end_date'] : null;
            $goalId = $cRow['goal_id'] !== null ? (int) $cRow['goal_id'] : null;

            $stmt = $this->db->prepare(
                'INSERT INTO workplan_objectives
                    (performance_contract_id, objective, kpi, measure_unit, section_id,
                     subsection_id, cycle_ids, goal_id, level, status, progress_percent,
                     planned_start_date, planned_end_date, created_by, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'not_started\', 0, ?, ?, ?, NOW(), NOW())'
            );
            $stmt->bind_param(
                'isssiisisssi',
                $contractId, $objective, $kpi, $measure, $sectionId,
                $subsectionId, $cycleIds, $goalId, $level, $pStart, $pEnd, $createdBy
            );
            if ($stmt->execute()) {
                $saved++;
                \App\Services\AuditService::getInstance()->log(
                    \App\Services\AuditService::MODULE_PERFORMANCE,
                    \App\Services\AuditService::ACTION_CREATE,
                    'Bulk-created workplan activity under contract #' . $contractId,
                    ['target_type' => 'WorkplanObjective', 'target_id' => (int) $this->db->insert_id,
                     'new_values' => ['level' => $level, 'section_id' => $sectionId, 'cycle_ids' => $cycleIds]]
                );
            } else {
                $failed++;
                $lastError = $stmt->error;
            }
            $stmt->close();
        }

        if ($saved === 0) {
            $this->error($lastError !== '' ? $lastError : 'No activities could be saved.', 422);
        }

        $this->success(
            ['saved' => $saved, 'failed' => $failed],
            $saved . ' activit' . ($saved === 1 ? 'y' : 'ies') . ' saved'
            . ($failed > 0 ? ", {$failed} failed." : '.'),
            201
        );
    }
}
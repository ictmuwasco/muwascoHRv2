<?php

declare(strict_types=1);

namespace App\Controllers\HR;

use App\Controllers\BaseController;
use App\Helpers\Database;
use App\Helpers\OrgScope;

/**
 * StrategicPlanController - Organisation strategy, goals and strategic targets.
 *
 * Reads and writes against the REAL legacy tables that already hold the
 * organisation's strategic data:
 *   - strategic_plan        (parent strategic plan / 5-year plan)
 *   - goals                 (strategic objectives / "perspectives")
 *   - strategic_targets     (organisational goals / strategic targets)
 *
 * Performance contracts, workplans, KPIs and appraisals hang off these via
 * their existing foreign keys. Deletion is protected: a record that still has
 * dependent children is never silently orphaned.
 */
class StrategicPlanController extends BaseController
{
    private \mysqli $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * GET /api/strategic-plans - Overview for the Strategic Plan page:
     * active financial year, all plans (with dependency counts), goals and
     * strategic targets (with department/plan detail), departments and
     * financial years for management dropdowns, and the manage flag.
     */
    public function indexAction(): void
    {
        $scope = OrgScope::current();

        if (!OrgScope::canViewAny($scope)) {
            $this->forbidden('You do not have permission to view strategic plans.');
        }

        try {
            $canManage = OrgScope::canManageStrategicPlan($scope)
                || OrgScope::canManagePerformance($scope);

            $activeFy = $this->db->query(
                "SELECT id, year_name, start_date, end_date
                 FROM financial_years WHERE is_active = 1 ORDER BY start_date DESC LIMIT 1"
            )->fetch_assoc();

            $plans = [];
            $plansRes = $this->db->query(
                "SELECT sp.*,
                    (SELECT COUNT(*) FROM goals g WHERE g.strategic_plan_id = sp.id) AS goal_count,
                    (SELECT COUNT(*) FROM strategic_targets t WHERE t.strategic_plan_id = sp.id) AS target_count,
                    (SELECT COUNT(*) FROM performance_contracts c WHERE c.strategic_plan_id = sp.id) AS contract_count,
                    (SELECT COUNT(*) FROM workplan_objectives w
                       JOIN performance_contracts c2 ON w.performance_contract_id = c2.id
                      WHERE c2.strategic_plan_id = sp.id) AS workplan_count
                 FROM strategic_plan sp
                 ORDER BY sp.start_date DESC, sp.id DESC"
            );
            if ($plansRes) {
                while ($row = $plansRes->fetch_assoc()) {
                    $plans[] = $row;
                }
            }

            $this->success([
                'active_financial_year' => $activeFy,
                'plans'           => $plans,
                'goals'           => $this->goals(),
                'targets'         => $this->targets(),
                'departments'     => $canManage ? $this->departments() : [],
                'financial_years' => $canManage ? $this->financialYears() : [],
                'can_manage'      => $canManage,
                'scope'           => [
                    'department_id'   => $scope['department_id'],
                    'department_name' => $scope['department_name'],
                    'section_id'      => $scope['section_id'],
                    'subsection_id'   => $scope['subsection_id'],
                ],
            ]);
        } catch (\Throwable $e) {
            \logger()->error('Strategic plan overview error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve strategic plans.', 500);
        }
    }
/**
     * POST /api/strategic-plans - Create a strategic plan.
     */
    public function storeAction(): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canManageStrategicPlan($scope)) {
            $this->forbidden('You do not have permission to manage strategic plans.');
        }
        $data = $this->getJsonBody();
        $name  = trim((string) ($data['name'] ?? ''));
        $start = trim((string) ($data['start_date'] ?? ''));
        $end   = trim((string) ($data['end_date'] ?? ''));

        if ($name === '' || $start === '' || $end === '') {
            $this->error('Plan name, start date and end date are required.', 422);
        }
        if (!$this->validDate($start) || !$this->validDate($end) || $end < $start) {
            $this->error('End date must be a valid date on or after the start date.', 422);
        }

        $stmt = $this->db->prepare(
            "INSERT INTO strategic_plan (name, start_date, end_date, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW())"
        );
        $stmt->bind_param('sss', $name, $start, $end);
        $stmt->execute();
        $newId = (int) $this->db->insert_id;
        $stmt->close();

        $this->success(['id' => $newId], 'Strategic plan created', 201);
    }

    /**
     * PUT /api/strategic-plans/{id} - Update a strategic plan.
     */
    public function updateAction(int $id): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canManageStrategicPlan($scope)) {
            $this->forbidden('You do not have permission to manage strategic plans.');
        }
        $data = $this->getJsonBody();

        $fields = [];
        $params = [];
        $types  = '';

        foreach (['name', 'start_date', 'end_date'] as $f) {
            if (isset($data[$f]) && trim((string) $data[$f]) !== '') {
                $value = trim((string) $data[$f]);
                if (($f === 'start_date' || $f === 'end_date') && !$this->validDate($value)) {
                    $this->error("Invalid $f.", 422);
                }
                $fields[] = "$f = ?";
                $params[] = $value;
                $types   .= 's';
            }
        }
        if (empty($fields)) {
            $this->error('No valid fields to update.', 422);
        }

        $hasStart = isset($data['start_date']) && trim((string) $data['start_date']) !== '';
        $hasEnd   = isset($data['end_date']) && trim((string) $data['end_date']) !== '';
        if ($hasStart && $hasEnd && (string) $data['end_date'] < (string) $data['start_date']) {
            $this->error('End date must be on or after the start date.', 422);
        }

        $fields[] = 'updated_at = NOW()';
        $params[] = $id;
        $types   .= 'i';

        $stmt = $this->db->prepare('UPDATE strategic_plan SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();

        $this->success(null, 'Strategic plan updated');
    }

    /**
     * DELETE /api/strategic-plans/{id} - Delete a strategic plan.
     * A plan that still has dependent goals, targets or performance contracts
     * is protected (409) so children are never silently orphaned.
     */
    public function destroyAction(int $id): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canManageStrategicPlan($scope)) {
            $this->forbidden('You do not have permission to manage strategic plans.');
        }

        $deps = $this->deleteGuard('strategic_plan', $id);
        if (is_array($deps)) {
            $labels = [];
            foreach ($deps as $key => $count) {
                $labels[] = "$count " . str_replace('_', ' ', $key);
            }
            $this->json([
                'success' => false,
                'message' => 'This strategic plan has dependent records (' . implode(', ', $labels)
                    . ') and cannot be deleted. Archive it or reassign the dependent records first.',
            ], 409);
        }

        $stmt = $this->db->prepare('DELETE FROM strategic_plan WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        $this->success(null, 'Strategic plan deleted');
    }
// ========================================================================
    // Goals (strategic objectives / perspectives)
    // ========================================================================

    /**
     * POST /api/strategic-plans/{id}/goals - Create a goal for a plan.
     */
    public function storeGoalAction(int $planId): void
    {
        $scope   = OrgScope::current();
        $data    = $this->getJsonBody();
        $name    = trim((string) ($data['name'] ?? ''));
        $planId2 = (int) ($data['strategic_plan_id'] ?? $planId);

        if (!OrgScope::canManageStrategicPlan($scope)) {
            $this->forbidden('You do not have permission to manage strategic plans.');
        }
        if ($name === '' || $planId2 <= 0) {
            $this->error('Strategic plan and goal name are required.', 422);
        }

        $stmt = $this->db->prepare(
            "INSERT INTO goals (strategic_plan_id, name, created_at, updated_at) VALUES (?, ?, NOW(), NOW())"
        );
        $stmt->bind_param('is', $planId2, $name);
        $stmt->execute();
        $newId = (int) $this->db->insert_id;
        $stmt->close();

        $this->success(['id' => $newId], 'Goal added', 201);
    }

    /**
     * PUT /api/goals/{id} - Update a goal.
     */
    public function updateGoalAction(int $id): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canManageStrategicPlan($scope)) {
            $this->forbidden('You do not have permission to manage strategic plans.');
        }
        $data = $this->getJsonBody();
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            $this->error('Goal name is required.', 422);
        }

        $stmt = $this->db->prepare('UPDATE goals SET name = ?, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('si', $name, $id);
        $stmt->execute();
        $stmt->close();

        $this->success(null, 'Goal updated');
    }

    /**
     * DELETE /api/goals/{id} - Delete a goal (guarded against depended targets).
     */
    public function destroyGoalAction(int $id): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canManageStrategicPlan($scope)) {
            $this->forbidden('You do not have permission to manage strategic plans.');
        }

        $deps = $this->deleteGoalGuard($id);
        if (is_array($deps)) {
            $labels = [];
            foreach ($deps as $key => $count) {
                $labels[] = "$count " . str_replace('_', ' ', $key);
            }
            $this->json([
                'success' => false,
                'message' => 'This goal has dependent records (' . implode(', ', $labels)
                    . ') and cannot be deleted.',
], 409);
        }

        $stmt = $this->db->prepare('DELETE FROM goals WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        $this->success(null, 'Goal deleted');
    }
// ========================================================================
    // Strategic targets (organisational goals / strategic targets)
    // ========================================================================

    /**
     * POST /api/strategic-plans/{id}/targets - Create a strategic target.
     */
    public function storeTargetAction(int $planId): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canManageStrategicPlan($scope)) {
            $this->forbidden('You do not have permission to manage strategic plans.');
        }
        $data = $this->getJsonBody();

        $goalId  = (int) ($data['goal_id'] ?? 0);
        $planId2 = (int) ($data['strategic_plan_id'] ?? $planId);
        $name    = trim((string) ($data['name'] ?? ''));
        $deptId  = isset($data['department_id']) && (int) $data['department_id'] > 0
            ? (int) $data['department_id'] : null;
        $description = trim((string) ($data['description'] ?? ''));
        $baseline = isset($data['baseline_value']) ? trim((string) $data['baseline_value']) : '';
        $targetV  = isset($data['target_value']) ? trim((string) $data['target_value']) : '';
        $unit     = isset($data['unit']) ? trim((string) $data['unit']) : '';

        if ($goalId <= 0 || $planId2 <= 0 || $name === '') {
            $this->error('Goal, strategic plan and target name are required.', 422);
        }
        if ($deptId !== null && !$this->departmentExists($deptId)) {
            $this->error('Selected department does not exist.', 422);
        }

        $stmt = $this->db->prepare(
            "INSERT INTO strategic_targets
                (goal_id, strategic_plan_id, department_id, name, description,
                 baseline_value, target_value, unit, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $stmt->bind_param(
            'iiisssss',
            $goalId, $planId2, $deptId, $name, $description, $baseline, $targetV, $unit
        );
        $stmt->execute();
        $newId = (int) $this->db->insert_id;
        $stmt->close();

        $this->success(['id' => $newId], 'Organisational goal (strategic target) added', 201);
    }

    /**
     * PUT /api/targets/{id} - Update a strategic target.
     */
    public function updateTargetAction(int $id): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canManageStrategicPlan($scope)) {
            $this->forbidden('You do not have permission to manage strategic plans.');
        }
        $data = $this->getJsonBody();

        $goalId  = (int) ($data['goal_id'] ?? 0);
        $planId2 = (int) ($data['strategic_plan_id'] ?? 0);
        $name    = trim((string) ($data['name'] ?? ''));
        $deptId  = isset($data['department_id']) && (int) $data['department_id'] > 0
            ? (int) $data['department_id'] : null;
        $description = trim((string) ($data['description'] ?? ''));
        $baseline = isset($data['baseline_value']) ? trim((string) $data['baseline_value']) : '';
        $targetV  = isset($data['target_value']) ? trim((string) $data['target_value']) : '';
        $unit     = isset($data['unit']) ? trim((string) $data['unit']) : '';

        if ($goalId <= 0 || $planId2 <= 0 || $name === '') {
            $this->error('Goal, strategic plan and target name are required.', 422);
        }
        if ($deptId !== null && !$this->departmentExists($deptId)) {
            $this->error('Selected department does not exist.', 422);
        }

        $stmt = $this->db->prepare(
            "UPDATE strategic_targets
                SET goal_id = ?, strategic_plan_id = ?, department_id = ?, name = ?,
                    description = ?, baseline_value = ?, target_value = ?, unit = ?,
                    updated_at = NOW()
              WHERE id = ?"
        );
        $stmt->bind_param(
            'iiisssssi',
            $goalId, $planId2, $deptId, $name, $description, $baseline, $targetV, $unit, $id
        );
        $stmt->execute();
        $stmt->close();

        $this->success(null, 'Organisational goal (strategic target) updated');
    }

    /**
     * DELETE /api/targets/{id} - Delete a strategic target (guarded).
     */
    public function destroyTargetAction(int $id): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canManageStrategicPlan($scope)) {
            $this->forbidden('You do not have permission to manage strategic plans.');
        }

        $count = $this->countStatement(
            'SELECT COUNT(*) AS c FROM performance_contracts WHERE target_id = ?',
            'i',
            $id
        );
        if ($count > 0) {
            $this->json([
                'success' => false,
                'message' => "This target is referenced by $count performance contracts and cannot be deleted.",
            ], 409);
        }

        $stmt = $this->db->prepare('DELETE FROM strategic_targets WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        $this->success(null, 'Organisational goal (strategic target) deleted');
    }
// ========================================================================
    // Private helpers
    // ========================================================================

    private function goals(): array
    {
        $out = [];
        $res = $this->db->query(
            "SELECT g.*, sp.name AS strategic_plan_name,
                (SELECT COUNT(*) FROM strategic_targets t WHERE t.goal_id = g.id) AS target_count
             FROM goals g
             LEFT JOIN strategic_plan sp ON g.strategic_plan_id = sp.id
             ORDER BY sp.start_date DESC, g.name ASC"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $out[] = $row;
            }
        }
        return $out;
    }

    private function targets(): array
    {
        $out = [];
        $res = $this->db->query(
            "SELECT t.*, g.name AS goal_name, sp.name AS strategic_plan_name,
                    d.name AS department_name,
                    (SELECT COUNT(*) FROM performance_contracts c WHERE c.target_id = t.id) AS contract_count
             FROM strategic_targets t
             LEFT JOIN goals g ON t.goal_id = g.id
             LEFT JOIN strategic_plan sp ON t.strategic_plan_id = sp.id
             LEFT JOIN departments d ON t.department_id = d.id
             ORDER BY g.name ASC, t.created_at DESC"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $out[] = $row;
            }
        }
        return $out;
    }

    private function departments(): array
    {
        $out = [];
        $res = $this->db->query("SELECT id, name FROM departments ORDER BY name");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $out[] = $row;
            }
        }
        return $out;
    }

    private function financialYears(): array
    {
        $out = [];
        $res = $this->db->query(
            "SELECT id, year_name, start_date, end_date, is_active
             FROM financial_years ORDER BY start_date DESC"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $out[] = $row;
            }
        }
        return $out;
    }

    private function validDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    private function departmentExists(int $id): bool
    {
        return $this->countStatement('SELECT COUNT(*) AS c FROM departments WHERE id = ?', 'i', $id) > 0;
    }

    private function countStatement(string $sql, string $types, ...$params): int
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['c'] ?? 0);
    }

    /**
     * @return array<string,int>|null map of dependency => count when blocked
     */
    private function deleteGuard(string $table, int $id): ?array
    {
        if ($table === 'strategic_plan') {
            $deps = [];
            $deps['goals']             = $this->countStatement('SELECT COUNT(*) AS c FROM goals WHERE strategic_plan_id = ?', 'i', $id);
            $deps['strategic_targets'] = $this->countStatement('SELECT COUNT(*) AS c FROM strategic_targets WHERE strategic_plan_id = ?', 'i', $id);
            $deps['performance_contracts'] = $this->countStatement('SELECT COUNT(*) AS c FROM performance_contracts WHERE strategic_plan_id = ?', 'i', $id);
            return array_filter($deps) === [] ? null : array_filter($deps);
        }
        return null;
    }

    /**
     * @return array<string,int>|null map of dependency => count when blocked
     */
    private function deleteGoalGuard(int $id): ?array
    {
        $deps = [];
        $deps['strategic_targets'] = $this->countStatement('SELECT COUNT(*) AS c FROM strategic_targets WHERE goal_id = ?', 'i', $id);
        $deps['performance_contracts'] = $this->countStatement('SELECT COUNT(*) AS c FROM performance_contracts WHERE goal_id = ?', 'i', $id);
        return array_filter($deps) === [] ? null : array_filter($deps);
    }
}
<?php

declare(strict_types=1);

namespace App\Controllers\HR;

use App\Controllers\BaseController;
use App\Helpers\Database;
use App\Helpers\OrgScope;

/**
 * PerformanceContractController - Performance contracts linked to the
 * strategic chain: strategic_plan -> goals -> strategic_targets ->
 * performance_contracts -> workplan_objectives -> kpis.
 */
class PerformanceContractController extends BaseController
{
    private \mysqli $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * GET /api/performance-contracts - List contracts scoped to the caller.
     */
    public function indexAction(): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canViewAny($scope)) {
            $this->forbidden('You do not have permission to view performance contracts.');
        }

        try {
            [$where, $params] = OrgScope::scopeWhere($scope, ['department_id' => 'c.department_id']);
            $types = str_repeat('i', count($params));

            $sql = "
                SELECT c.*, d.name AS department_name, g.name AS goal_name,
                       sp.name AS strategic_plan_name, t.name AS target_name,
                       fy.year_name AS financial_year_name,
                       (SELECT COUNT(*) FROM workplan_objectives w WHERE w.performance_contract_id = c.id) AS objective_count
                FROM performance_contracts c
                LEFT JOIN departments d ON c.department_id = d.id
                LEFT JOIN goals g ON c.goal_id = g.id
                LEFT JOIN strategic_plan sp ON c.strategic_plan_id = sp.id
                LEFT JOIN strategic_targets t ON c.target_id = t.id
                LEFT JOIN financial_years fy ON c.financial_year_id = fy.id
                WHERE $where
            ";

            $extra = [];
            foreach (['financial_year_id' => 'c.financial_year_id', 'department_id' => 'c.department_id', 'strategic_plan_id' => 'c.strategic_plan_id'] as $paramName => $col) {
                if (isset($_GET[$paramName]) && $_GET[$paramName] !== '') {
                    $extra[] = "$col = ?";
                    $params[] = (int) $_GET[$paramName];
                    $types .= 'i';
                }
            }
            if ($extra) {
                $sql .= ' AND ' . implode(' AND ', $extra);
            }
            $sql .= ' ORDER BY c.created_at DESC, c.id DESC';

            $stmt = $this->db->prepare($sql);
            if ($params) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $this->success([
                'contracts'       => $rows,
                'can_manage'      => OrgScope::canManageContracts($scope),
                'can_view_all'    => OrgScope::canViewAllContracts($scope),
                'financial_years' => $this->financialYears(),
                'departments'     => $this->departments(),
            ]);
        } catch (\Throwable $e) {
            \logger()->error('Performance contract list error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve performance contracts.', 500);
        }
    }

    /**
     * GET /api/performance-contracts/{id} - One contract with its workplans.
     */
    public function showAction(int $id): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canViewAny($scope)) {
            $this->forbidden('You do not have permission to view performance contracts.');
        }

        $stmt = $this->db->prepare(
            "SELECT c.*, d.name AS department_name, g.name AS goal_name,
                    sp.name AS strategic_plan_name, t.name AS target_name,
                    fy.year_name AS financial_year_name
             FROM performance_contracts c
             LEFT JOIN departments d ON c.department_id = d.id
             LEFT JOIN goals g ON c.goal_id = g.id
             LEFT JOIN strategic_plan sp ON c.strategic_plan_id = sp.id
             LEFT JOIN strategic_targets t ON c.target_id = t.id
             LEFT JOIN financial_years fy ON c.financial_year_id = fy.id
             WHERE c.id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $contract = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$contract) {
            $this->notFound('Performance contract not found.');
        }

        $wStmt = $this->db->prepare(
            "SELECT w.*, s.name AS section_name, ss.name AS subsection_name
             FROM workplan_objectives w
             LEFT JOIN sections s ON w.section_id = s.id
             LEFT JOIN subsections ss ON w.subsection_id = ss.id
             WHERE w.performance_contract_id = ?
             ORDER BY w.id ASC"
        );
        $wStmt->bind_param('i', $id);
        $wStmt->execute();
        $contract['workplans'] = $wStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $wStmt->close();

        $this->success($contract);
    }
/**
     * POST /api/performance-contracts - Create a contract.
     */
    public function storeAction(): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canManageContracts($scope)) {
            $this->forbidden('You do not have permission to manage performance contracts.');
        }
        $data = $this->getJsonBody();

        $alignment = $this->resolveAlignment($data);
        if ($alignment['errors'] !== []) {
            $this->error(implode(' ', $alignment['errors']), 422);
        }

        $name = trim((string) ($data['name'] ?? ''));
        $kra  = trim((string) ($data['kra'] ?? ''));
        if ($name === '') {
            $this->error('The departmental objective name is required.', 422);
        }

        $planId   = $alignment['strategic_plan_id'];
        $goalId   = $alignment['goal_id'];
        $targetId = $alignment['target_id'];
        $deptId   = $alignment['department_id'];
        $fyId     = $alignment['financial_year_id'];

        $stmt = $this->db->prepare(
            "INSERT INTO performance_contracts
                (strategic_plan_id, goal_id, target_id, name, kra, department_id, financial_year_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $stmt->bind_param('iiisssi', $planId, $goalId, $targetId, $name, $kra, $deptId, $fyId);
        $stmt->execute();
        $newId = (int) $this->db->insert_id;
        $stmt->close();

        \App\Services\AuditService::getInstance()->log(
            \App\Services\AuditService::MODULE_PERFORMANCE,
            \App\Services\AuditService::ACTION_CREATE,
            'Created performance contract: ' . $name,
            ['target_type' => 'PerformanceContract', 'target_id' => $newId, 'new_values' => [
                'strategic_plan_id' => $planId, 'goal_id' => $goalId, 'target_id' => $targetId,
                'department_id' => $deptId, 'financial_year_id' => $fyId,
            ]]
        );

        $this->success(['id' => $newId], 'Performance contract created', 201);
    }

    /**
     * PUT /api/performance-contracts/{id} - Update a contract.
     */
    public function updateAction(int $id): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canManageContracts($scope)) {
            $this->forbidden('You do not have permission to manage performance contracts.');
        }
        $data = $this->getJsonBody();

        $fields = [];
        $params = [];
        $types  = '';

        foreach (['strategic_plan_id', 'department_id', 'financial_year_id'] as $f) {
            if (isset($data[$f]) && $data[$f] !== '' && (int) $data[$f] > 0) {
                $fields[] = "$f = ?";
                $params[] = (int) $data[$f];
                $types   .= 'i';
            }
        }
        foreach (['name', 'kra'] as $f) {
            if (isset($data[$f]) && trim((string) $data[$f]) !== '') {
                $fields[] = "$f = ?";
                $params[] = trim((string) $data[$f]);
                $types   .= 's';
            }
        }

        // goal_id is always derived from the selected organisational goal so the
        // perspective / org-goal pairing can never drift out of sync.
        if (array_key_exists('target_id', $data)) {
            $alignment = $this->resolveAlignment($data, false);
            if ($alignment['errors'] !== []) {
                $this->error(implode(' ', $alignment['errors']), 422);
            }
            $fields[] = 'goal_id = ?';
            $params[] = $alignment['goal_id'];
            $types   .= 'i';
            $fields[] = 'target_id = ?';
            $params[] = $alignment['target_id'];
            $types   .= 'i';
        }

        if (empty($fields)) {
            $this->error('No valid fields to update.', 422);
        }

        $fields[] = 'updated_at = NOW()';
        $params[] = $id;
        $types   .= 'i';

        $stmt = $this->db->prepare('UPDATE performance_contracts SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();

        \App\Services\AuditService::getInstance()->log(
            \App\Services\AuditService::MODULE_PERFORMANCE,
            \App\Services\AuditService::ACTION_UPDATE,
            'Updated performance contract #' . $id,
            ['target_type' => 'PerformanceContract', 'target_id' => $id, 'new_values' => $data]
        );

        $this->success(null, 'Performance contract updated');
    }

    /**
     * DELETE /api/performance-contracts/{id} - Guarded delete.
     */
    public function destroyAction(int $id): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canManageContracts($scope)) {
            $this->forbidden('You do not have permission to manage performance contracts.');
        }

        $objCount = $this->countStatement(
            'SELECT COUNT(*) AS c FROM workplan_objectives WHERE performance_contract_id = ?',
            'i',
            $id
        );
        $kpiCount = $this->countStatement(
            'SELECT COUNT(*) AS c FROM kpis WHERE performance_contract_id = ?',
            'i',
            $id
        );
        if ($objCount > 0 || $kpiCount > 0) {
            $this->json([
                'success' => false,
                'message' => "This performance contract has $objCount workplan objective(s) and $kpiCount KPI(s). Reassign or remove them before deleting.",
            ], 409);
        }

        $stmt = $this->db->prepare('DELETE FROM performance_contracts WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        \App\Services\AuditService::getInstance()->log(
            \App\Services\AuditService::MODULE_PERFORMANCE,
            \App\Services\AuditService::ACTION_DELETE,
            'Deleted performance contract #' . $id,
            ['target_type' => 'PerformanceContract', 'target_id' => $id]
        );

        $this->success(null, 'Performance contract deleted');
    }
// ========================================================================
    // Private helpers
    // ========================================================================

    /**
     * Validates and resolves the strategic alignment of a contract.
     *
     * Legacy behaviour preserved: the goal perspective (goal_id) is always
     * DERIVED from the selected organisational goal (strategic_targets.id),
     * and the org goal must belong to the selected strategic plan.
     *
     * @return array{strategic_plan_id:int, goal_id:int, target_id:?int,
     *               department_id:int, financial_year_id:int, errors:string[]}
     */
    private function resolveAlignment(array $data, bool $requireAll = true): array
    {
        $errors   = [];
        $planId   = isset($data['strategic_plan_id']) ? (int) $data['strategic_plan_id'] : 0;
        $deptId   = isset($data['department_id']) ? (int) $data['department_id'] : 0;
        $fyId     = isset($data['financial_year_id']) ? (int) $data['financial_year_id'] : 0;
        $targetId = isset($data['target_id']) && (int) $data['target_id'] > 0 ? (int) $data['target_id'] : null;
        $goalId   = 0;

        if ($targetId !== null) {
            $stmt = $this->db->prepare('SELECT goal_id, strategic_plan_id FROM strategic_targets WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $targetId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                $errors[] = 'The selected organisational goal does not exist.';
            } else {
                // Derive the goal perspective from the organisational goal.
                $goalId = (int) $row['goal_id'];
                if ($planId > 0 && (int) $row['strategic_plan_id'] !== $planId) {
                    $errors[] = 'The selected organisational goal does not belong to the selected strategic plan.';
                }
            }
        } elseif ($requireAll) {
            $errors[] = 'An organisational goal must be selected.';
        }

        if ($requireAll) {
            if ($planId <= 0) { $errors[] = 'A strategic plan must be selected.'; }
            if ($deptId <= 0) { $errors[] = 'A department must be selected.'; }
            if ($fyId <= 0)   { $errors[] = 'A financial year must be selected.'; }
        }

        if ($deptId > 0 && !$this->rowExists('departments', $deptId)) {
            $errors[] = 'The selected department does not exist.';
        }
        if ($fyId > 0 && !$this->rowExists('financial_years', $fyId)) {
            $errors[] = 'The selected financial year does not exist.';
        }
        if ($planId > 0 && !$this->rowExists('strategic_plan', $planId)) {
            $errors[] = 'The selected strategic plan does not exist.';
        }

        return [
            'strategic_plan_id' => $planId,
            'goal_id'           => $goalId,
            'target_id'         => $targetId,
            'department_id'     => $deptId,
            'financial_year_id' => $fyId,
            'errors'            => $errors,
        ];
    }

    private function rowExists(string $table, int $id): bool
    {
        return $this->countStatement("SELECT COUNT(*) AS c FROM $table WHERE id = ?", 'i', $id) > 0;
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
}
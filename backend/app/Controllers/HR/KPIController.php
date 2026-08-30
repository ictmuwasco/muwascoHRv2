<?php

declare(strict_types=1);

namespace App\Controllers\HR;

use App\Controllers\BaseController;
use App\Helpers\Database;
use App\Helpers\OrgScope;

/**
 * KPIController - Key performance indicators stored in the `kpis` table,
 * each linked to a performance contract (performance_contract_id). The
 * contract's strategic_plan/goal/target/department chain provides traceability
 * from a KPI back to the organisation's strategy.
 */
class KPIController extends BaseController
{
    private \mysqli $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * GET /api/contracts/{id}/kpis - KPIs belonging to a performance contract.
     */
    public function indexAction(int $contractId): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canViewAny($scope)) {
            $this->forbidden('You do not have permission to view KPIs.');
        }

        try {
            $scopeSql = $this->contractScope($scope);

            $sql = "
                SELECT k.*, c.name AS contract_name
                FROM kpis k
                LEFT JOIN performance_contracts c ON k.performance_contract_id = c.id
                WHERE k.performance_contract_id = ? AND $scopeSql
                ORDER BY k.created_at DESC, k.id DESC
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('i', $contractId);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $this->success([
                'kpis'       => $rows,
                'can_manage' => OrgScope::canManagePerformance($scope),
            ]);
        } catch (\Throwable $e) {
            \logger()->error('KPI list error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve KPIs.', 500);
        }
    }

    /**
     * GET /api/kpis - List all KPIs scoped to the caller (management view).
     */
    public function listAction(): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canViewAny($scope)) {
            $this->forbidden('You do not have permission to view KPIs.');
        }

        try {
            $scopeSql = $this->contractScope($scope);
            $sql = "
                SELECT k.*, c.name AS contract_name, d.name AS department_name,
                       sp.name AS strategic_plan_name, g.name AS goal_name,
                       s.name AS section_name, ss.name AS subsection_name
                FROM kpis k
                LEFT JOIN performance_contracts c ON k.performance_contract_id = c.id
                LEFT JOIN departments d ON c.department_id = d.id
                LEFT JOIN strategic_plan sp ON c.strategic_plan_id = sp.id
                LEFT JOIN goals g ON c.goal_id = g.id
                LEFT JOIN workplan_objectives w ON w.performance_contract_id = c.id
                LEFT JOIN sections s ON w.section_id = s.id
                LEFT JOIN subsections ss ON w.subsection_id = ss.id
                WHERE $scopeSql
                ORDER BY k.created_at DESC, k.id DESC
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $this->success(['kpis' => $rows]);
        } catch (\Throwable $e) {
            \logger()->error('KPI list error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve KPIs.', 500);
        }
    }
/**
     * POST /api/contracts/{id}/kpis - Create a KPI for a performance contract.
     */
    public function storeAction(int $contractId): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canManagePerformance($scope)) {
            $this->forbidden('You do not have permission to manage KPIs.');
        }
        $data = $this->getJsonBody();

        $kpiName   = trim((string) ($data['kpi_name'] ?? ''));
        $desc      = trim((string) ($data['kpi_description'] ?? ''));
        $target    = isset($data['target']) ? trim((string) $data['target']) : '';
        $unit      = isset($data['unit_of_measure']) ? trim((string) $data['unit_of_measure']) : '';
        $source    = isset($data['data_source']) ? trim((string) $data['data_source']) : '';
        $frequency = isset($data['frequency']) ? trim((string) $data['frequency']) : '';
        $responsible = isset($data['responsible_person']) ? trim((string) $data['responsible_person']) : '';
        $weight    = (float) ($data['weight'] ?? 0);

        if ($kpiName === '') {
            $this->error('KPI name is required.', 422);
        }
        if (!$this->rowExists('performance_contracts', $contractId)) {
            $this->error('Selected performance contract does not exist.', 422);
        }

        $stmt = $this->db->prepare(
            "INSERT INTO kpis
                (performance_contract_id, kpi_name, kpi_description, target, unit_of_measure,
                 data_source, frequency, responsible_person, weight, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $userId = $this->getUserId() ?: null;
        $stmt->bind_param(
            'isssssssdi',
            $contractId, $kpiName, $desc, $target, $unit, $source, $frequency, $responsible,
            $weight, $userId
        );
        $stmt->execute();
        $newId = (int) $this->db->insert_id;
        $stmt->close();

        $this->success(['id' => $newId], 'KPI created', 201);
    }

    /**
     * PUT /api/kpis/{id} - Update a KPI (targets + scores, metadata).
     */
    public function updateAction(int $id): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canManagePerformance($scope)) {
            $this->forbidden('You do not have permission to manage KPIs.');
        }
        $data = $this->getJsonBody();

        $fields = [];
        $params = [];
        $types  = '';

        foreach (['kpi_name', 'kpi_description', 'target', 'unit_of_measure',
                  'data_source', 'frequency', 'responsible_person'] as $f) {
            if (isset($data[$f]) && trim((string) $data[$f]) !== '') {
                $fields[] = "$f = ?";
                $params[] = trim((string) $data[$f]);
                $types   .= 's';
            }
        }
        foreach (['y1_target', 'y2_target', 'y3_target', 'y4_target', 'y5_target',
                  'y1_score', 'y2_score', 'y3_score', 'y4_score', 'y5_score'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = $data[$f] !== '' ? (string) $data[$f] : null;
                $types   .= 's';
            }
        }
        if (isset($data['weight'])) {
            $fields[] = 'weight = ?';
            $params[] = (float) $data['weight'];
            $types   .= 'd';
        }

        if (empty($fields)) {
            $this->error('No valid fields to update.', 422);
        }
        $fields[] = 'updated_at = NOW()';
        $params[] = $id;
        $types   .= 'i';

        $stmt = $this->db->prepare('UPDATE kpis SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();

        $this->success(null, 'KPI updated');
    }

    /**
     * DELETE /api/kpis/{id} - Delete a KPI.
     */
    public function destroyAction(int $id): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canManagePerformance($scope)) {
            $this->forbidden('You do not have permission to manage KPIs.');
        }

        $stmt = $this->db->prepare('DELETE FROM kpis WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected === 0) {
            $this->notFound('KPI not found.');
        }
        $this->success(null, 'KPI deleted');
    }
// ========================================================================
    // Private helpers
    // ========================================================================

    /**
     * Scope clause for kpis (via their contract's department). For lower heads
     * the contract's department is the coarsest available unit on `kpis`.
     */
    private function contractScope(array $scope): string
    {
        if ($scope['is_hr'] || $scope['is_super_admin'] || $scope['is_pme_or_audit']) {
            return '1=1';
        }
        if ($scope['is_dept_head'] && $scope['department_id'] !== null) {
            return 'c.department_id = ' . (int) $scope['department_id'];
        }
        // Section / subsection heads see KPIs for their department scope.
        if ($scope['department_id'] !== null) {
            return 'c.department_id = ' . (int) $scope['department_id'];
        }
        return '1=0';
    }

    private function rowExists(string $table, int $id): bool
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
}
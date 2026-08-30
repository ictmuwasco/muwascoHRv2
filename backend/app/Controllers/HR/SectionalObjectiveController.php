<?php

declare(strict_types=1);

namespace App\Controllers\HR;

use App\Controllers\BaseController;
use App\Helpers\Database;
use App\Helpers\OrgScope;

/**
 * SectionalObjectiveController - Sectional objectives / KPIs stored in
 * `performance_indicators`. These carry department/section/subsection and
 * role ownership and feed the appraisal/performance-cycle scoring.
 */
class SectionalObjectiveController extends BaseController
{
    private \mysqli $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * GET /api/sectional-objectives - List scoped to the caller.
     */
    public function indexAction(): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canViewAny($scope)) {
            $this->forbidden('You do not have permission to view sectional objectives.');
        }

        try {
            [$where, $params] = OrgScope::scopeWhere(
                $scope,
                ['department_id' => 'department_id', 'section_id' => 'section_id', 'subsection_id' => 'subsection_id']
            );
            $types = str_repeat('i', count($params));

            if (isset($_GET['department_id']) && $_GET['department_id'] !== '') {
                $where .= ' AND department_id = ?';
                $params[] = (int) $_GET['department_id'];
                $types   .= 'i';
            }

            $sql = "
                SELECT pi.*, d.name AS department_name, s.name AS section_name,
                       ss.name AS subsection_name
                FROM performance_indicators pi
                LEFT JOIN departments d ON pi.department_id = d.id
                LEFT JOIN sections s ON pi.section_id = s.id
                LEFT JOIN subsections ss ON pi.subsection_id = ss.id
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

            $this->success([
                'objectives' => $rows,
                'can_manage' => OrgScope::canManagePerformance($scope),
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
     * POST /api/sectional-objectives - Create an objective.
     */
    public function storeAction(): void
    {
        $scope = OrgScope::current();
        if (!OrgScope::canManagePerformance($scope)) {
            $this->forbidden('You do not have permission to manage sectional objectives.');
        }
        $data = $this->getJsonBody();

        $name      = trim((string) ($data['name'] ?? ''));
        $desc      = trim((string) ($data['description'] ?? ''));
        $maxScore  = (int) ($data['max_score'] ?? 5);
        $role      = isset($data['role']) ? trim((string) $data['role']) : '';
        $departmentId = isset($data['department_id']) && (int) $data['department_id'] > 0 ? (int) $data['department_id'] : null;
        $sectionId = isset($data['section_id']) && (int) $data['section_id'] > 0 ? (int) $data['section_id'] : null;
        $subsectionId = isset($data['subsection_id']) && (int) $data['subsection_id'] > 0 ? (int) $data['subsection_id'] : null;
        $activityIds = isset($data['activity_ids']) ? trim((string) $data['activity_ids']) : '';
        $assigned   = isset($data['assigned_to_employee_ids']) ? trim((string) $data['assigned_to_employee_ids']) : '';

        if ($name === '') {
            $this->error('Objective name is required.', 422);
        }

        $stmt = $this->db->prepare(
            "INSERT INTO performance_indicators
                (name, description, max_score, activity_ids, role, assigned_to_employee_ids,
                 department_id, section_id, subsection_id, is_active, is_recurrent, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, NOW(), NOW())"
        );
        $userId = $this->getUserId() ?: null;
        $stmt->bind_param(
            'ssisssssii',
            $name, $desc, $maxScore, $activityIds, $role, $assigned,
            $departmentId, $sectionId, $subsectionId, $userId
        );
        $stmt->execute();
        $newId = (int) $this->db->insert_id;
        $stmt->close();

        $this->success(['id' => $newId], 'Sectional objective created', 201);
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
        $data = $this->getJsonBody();

        $fields = [];
        $params = [];
        $types  = '';

        foreach (['name', 'description', 'role', 'activity_ids', 'assigned_to_employee_ids'] as $f) {
            if (isset($data[$f]) && trim((string) $data[$f]) !== '') {
                $fields[] = "$f = ?";
                $params[] = trim((string) $data[$f]);
                $types   .= 's';
            }
        }
        if (isset($data['max_score']) && (int) $data['max_score'] > 0) {
            $fields[] = 'max_score = ?';
            $params[] = (int) $data['max_score'];
            $types   .= 'i';
        }
        foreach (['department_id', 'section_id', 'subsection_id'] as $f) {
            if (array_key_exists($f, $data) && $data[$f] !== '') {
                $fields[] = "$f = ?";
                $params[] = (int) $data[$f] > 0 ? (int) $data[$f] : null;
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

        // Soft-delete: deactivate rather than physically remove (referenced by
        // cycle_indicators / appraisal scoring).
        $stmt = $this->db->prepare('UPDATE performance_indicators SET is_active = 0, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        $this->success(null, 'Sectional objective deactivated');
    }
}
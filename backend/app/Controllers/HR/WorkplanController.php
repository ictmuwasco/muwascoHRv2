<?php

declare(strict_types=1);

namespace App\Controllers\HR;

use App\Controllers\BaseController;
use App\Helpers\Database;

/**
 * Workplan Controller - Workplans linked to a strategic plan.
 */
class WorkplanController extends BaseController
{
    private \mysqli $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /** GET /api/strategic-plans/{planId}/workplans */
    public function indexAction(int $planId): void
    {
        $this->requirePermission('dashboard', 'view');
        try {
            $check = $this->db->query("SHOW TABLES LIKE 'workplans'");
            if (!$check || $check->num_rows === 0) { $this->success([], 'No workplans yet'); return; }
            $stmt = $this->db->prepare(
                'SELECT w.*, u.username as created_by_name
                 FROM workplans w
                 LEFT JOIN users u ON w.created_by = u.id
                 WHERE w.strategic_plan_id = ?
                 ORDER BY w.created_at DESC'
            );
            $stmt->bind_param('i', $planId);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $this->success($result);
        } catch (\Throwable $e) {
            \logger()->error('Workplan list error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve workplans', 500);
        }
    }

    /** POST /api/strategic-plans/{planId}/workplans */
    public function storeAction(int $planId): void
    {
        $this->requirePermission('admin', 'manage');
        $data = $this->getJsonBody();
        $missing = $this->validateRequired($data, ['title']);
        if ($missing) { $this->error('Missing required fields: ' . implode(', ', $missing), 422); return; }
        try {
            $this->db->query(
                "CREATE TABLE IF NOT EXISTS workplans (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    strategic_plan_id INT NOT NULL,
                    title VARCHAR(500) NOT NULL,
                    description TEXT,
                    status VARCHAR(20) NOT NULL DEFAULT 'draft',
                    start_date DATE,
                    end_date DATE,
                    created_by INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )"
            );
            $userId    = $this->getUserId();
            $title     = htmlspecialchars($data['title'], ENT_QUOTES, 'UTF-8');
            $desc      = htmlspecialchars($data['description'] ?? '', ENT_QUOTES, 'UTF-8');
            $status    = in_array($data['status'] ?? '', ['draft', 'active', 'completed']) ? $data['status'] : 'draft';
            $startDate = $data['start_date'] ?? null;
            $endDate   = $data['end_date']   ?? null;
            $stmt = $this->db->prepare(
                'INSERT INTO workplans (strategic_plan_id, title, description, status, start_date, end_date, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('isssssi', $planId, $title, $desc, $status, $startDate, $endDate, $userId);
            $stmt->execute();
            $newId = $this->db->insert_id;
            $stmt->close();
            $this->success(['id' => $newId], 'Workplan created', 201);
        } catch (\Throwable $e) {
            \logger()->error('Workplan create error', ['error' => $e->getMessage()]);
            $this->error('Failed to create workplan', 500);
        }
    }

    /** PUT /api/workplans/{id} */
    public function updateAction(int $id): void
    {
        $this->requirePermission('admin', 'manage');
        $data = $this->getJsonBody();
        try {
            $fields = []; $params = []; $types = '';
            foreach (['title', 'description', 'status', 'start_date', 'end_date'] as $f) {
                if (!isset($data[$f])) continue;
                $fields[] = "$f = ?";
                $params[] = htmlspecialchars((string)$data[$f], ENT_QUOTES, 'UTF-8');
                $types   .= 's';
            }
            if (empty($fields)) { $this->error('No valid fields', 422); return; }
            $params[] = $id; $types .= 'i';
            $stmt = $this->db->prepare('UPDATE workplans SET ' . implode(', ', $fields) . ' WHERE id = ?');
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            if ($stmt->affected_rows === 0) { $stmt->close(); $this->notFound('Workplan not found'); return; }
            $stmt->close();
            $this->success(null, 'Workplan updated');
        } catch (\Throwable $e) {
            \logger()->error('Workplan update error', ['error' => $e->getMessage()]);
            $this->error('Failed to update workplan', 500);
        }
    }

    /** DELETE /api/workplans/{id} */
    public function destroyAction(int $id): void
    {
        $this->requirePermission('admin', 'manage');
        try {
            $stmt = $this->db->prepare('DELETE FROM workplans WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            if ($stmt->affected_rows === 0) { $stmt->close(); $this->notFound('Workplan not found'); return; }
            $stmt->close();
            $this->success(null, 'Workplan deleted');
        } catch (\Throwable $e) {
            $this->error('Failed to delete workplan', 500);
        }
    }
}
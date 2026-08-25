<?php

declare(strict_types=1);

namespace App\Controllers\HR;

use App\Controllers\BaseController;
use App\Helpers\Database;

/**
 * KPI Controller - Key Performance Indicators linked to workplans.
 */
class KPIController extends BaseController
{
    private \mysqli $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /** GET /api/workplans/{workplanId}/kpis */
    public function indexAction(int $workplanId): void
    {
        $this->requirePermission('dashboard', 'view');
        try {
            $check = $this->db->query("SHOW TABLES LIKE 'kpis'");
            if (!$check || $check->num_rows === 0) { $this->success([], 'No KPIs yet'); return; }
            $stmt = $this->db->prepare(
                'SELECT k.*, u.username as assigned_to_name
                 FROM kpis k
                 LEFT JOIN users u ON k.assigned_to = u.id
                 WHERE k.workplan_id = ?
                 ORDER BY k.created_at DESC'
            );
            $stmt->bind_param('i', $workplanId);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $this->success($result);
        } catch (\Throwable $e) {
            \logger()->error('KPI list error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve KPIs', 500);
        }
    }

    /** POST /api/workplans/{workplanId}/kpis */
    public function storeAction(int $workplanId): void
    {
        $this->requirePermission('admin', 'manage');
        $data = $this->getJsonBody();
        $missing = $this->validateRequired($data, ['title', 'target']);
        if ($missing) { $this->error('Missing required fields: ' . implode(', ', $missing), 422); return; }
        try {
            $this->db->query(
                "CREATE TABLE IF NOT EXISTS kpis (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    workplan_id INT NOT NULL,
                    title VARCHAR(500) NOT NULL,
                    description TEXT,
                    target VARCHAR(255),
                    actual VARCHAR(255),
                    weight DECIMAL(5,2) DEFAULT 0,
                    status VARCHAR(20) NOT NULL DEFAULT 'pending',
                    assigned_to INT,
                    due_date DATE,
                    created_by INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )"
            );
            $userId = $this->getUserId();
            $title  = htmlspecialchars($data['title'],  ENT_QUOTES, 'UTF-8');
            $desc   = htmlspecialchars($data['description'] ?? '', ENT_QUOTES, 'UTF-8');
            $target = htmlspecialchars($data['target'], ENT_QUOTES, 'UTF-8');
            $weight = (float)($data['weight'] ?? 0);
            $status = in_array($data['status'] ?? '', ['pending', 'in_progress', 'achieved', 'not_achieved']) ? $data['status'] : 'pending';
            $assignedTo = isset($data['assigned_to']) ? (int)$data['assigned_to'] : null;
            $dueDate    = $data['due_date'] ?? null;

            $stmt = $this->db->prepare(
                'INSERT INTO kpis (workplan_id, title, description, target, weight, status, assigned_to, due_date, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('isssdsssi', $workplanId, $title, $desc, $target, $weight, $status, $dueDate, $assignedTo, $userId);
            $stmt->execute();
            $newId = $this->db->insert_id;
            $stmt->close();
            $this->success(['id' => $newId], 'KPI created', 201);
        } catch (\Throwable $e) {
            \logger()->error('KPI create error', ['error' => $e->getMessage()]);
            $this->error('Failed to create KPI', 500);
        }
    }

    /** PUT /api/kpis/{id} */
    public function updateAction(int $id): void
    {
        $this->requirePermission('admin', 'manage');
        $data = $this->getJsonBody();
        try {
            $fields = []; $params = []; $types = '';
            $stringFields = ['title', 'description', 'target', 'actual', 'status', 'due_date'];
            foreach ($stringFields as $f) {
                if (!isset($data[$f])) continue;
                $fields[] = "$f = ?";
                $params[] = htmlspecialchars((string)$data[$f], ENT_QUOTES, 'UTF-8');
                $types   .= 's';
            }
            if (isset($data['weight'])) {
                $fields[] = 'weight = ?'; $params[] = (float)$data['weight']; $types .= 'd';
            }
            if (empty($fields)) { $this->error('No valid fields', 422); return; }
            $params[] = $id; $types .= 'i';
            $stmt = $this->db->prepare('UPDATE kpis SET ' . implode(', ', $fields) . ' WHERE id = ?');
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            if ($stmt->affected_rows === 0) { $stmt->close(); $this->notFound('KPI not found'); return; }
            $stmt->close();
            $this->success(null, 'KPI updated');
        } catch (\Throwable $e) {
            \logger()->error('KPI update error', ['error' => $e->getMessage()]);
            $this->error('Failed to update KPI', 500);
        }
    }

    /** DELETE /api/kpis/{id} */
    public function destroyAction(int $id): void
    {
        $this->requirePermission('admin', 'manage');
        try {
            $stmt = $this->db->prepare('DELETE FROM kpis WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            if ($stmt->affected_rows === 0) { $stmt->close(); $this->notFound('KPI not found'); return; }
            $stmt->close();
            $this->success(null, 'KPI deleted');
        } catch (\Throwable $e) {
            $this->error('Failed to delete KPI', 500);
        }
    }
}
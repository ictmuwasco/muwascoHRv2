<?php

declare(strict_types=1);

namespace App\Controllers\HR;

use App\Controllers\BaseController;
use App\Helpers\Database;

/**
 * Strategic Plan Controller - Manage organisational strategic plans.
 */
class StrategicPlanController extends BaseController
{
    private \mysqli $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /** GET /api/strategic-plans */
    public function indexAction(): void
    {
        $this->requirePermission('dashboard', 'view');
        try {
            $check = $this->db->query("SHOW TABLES LIKE 'strategic_plans'");
            if (!$check || $check->num_rows === 0) {
                $this->success([], 'Strategic plans module not yet set up');
                return;
            }
            $stmt = $this->db->prepare(
                'SELECT sp.*, u.username as created_by_name
                 FROM strategic_plans sp
                 LEFT JOIN users u ON sp.created_by = u.id
                 ORDER BY sp.year DESC, sp.created_at DESC'
            );
            $stmt->execute();
            $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $this->success($result);
        } catch (\Throwable $e) {
            \logger()->error('SP list error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve strategic plans', 500);
        }
    }

    /** GET /api/strategic-plans/{id} */
    public function showAction(int $id): void
    {
        $this->requirePermission('dashboard', 'view');
        try {
            $check = $this->db->query("SHOW TABLES LIKE 'strategic_plans'");
            if (!$check || $check->num_rows === 0) {
                $this->notFound('Strategic plan not found');
                return;
            }
            $stmt = $this->db->prepare('SELECT * FROM strategic_plans WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $plan = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$plan) { $this->notFound('Strategic plan not found'); return; }
            $wCheck = $this->db->query("SHOW TABLES LIKE 'workplans'");
            if ($wCheck && $wCheck->num_rows > 0) {
                $ws = $this->db->prepare('SELECT * FROM workplans WHERE strategic_plan_id = ? ORDER BY created_at DESC');
                $ws->bind_param('i', $id);
                $ws->execute();
                $plan['workplans'] = $ws->get_result()->fetch_all(MYSQLI_ASSOC);
                $ws->close();
            } else {
                $plan['workplans'] = [];
            }
            $this->success($plan);
        } catch (\Throwable $e) {
            \logger()->error('SP show error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve strategic plan', 500);
        }
    }

    /** POST /api/strategic-plans */
    public function storeAction(): void
    {
        $this->requirePermission('admin', 'manage');
        $data = $this->getJsonBody();
        $missing = $this->validateRequired($data, ['title', 'year']);
        if ($missing) { $this->error('Missing required fields: ' . implode(', ', $missing), 422); return; }
        try {
            $this->db->query(
                'CREATE TABLE IF NOT EXISTS strategic_plans (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    title VARCHAR(500) NOT NULL,
                    description TEXT,
                    year YEAR NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT \'draft\',
                    start_date DATE,
                    end_date DATE,
                    created_by INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )'
            );
            $userId = $this->getUserId();
            $title = htmlspecialchars($data['title'], ENT_QUOTES, 'UTF-8');
            $desc = htmlspecialchars($data['description'] ?? '', ENT_QUOTES, 'UTF-8');
            $year = (int)$data['year'];
            $status = in_array($data['status'] ?? '', ['draft', 'active', 'completed', 'archived'])
                ? $data['status'] : 'draft';
            $startDate = $data['start_date'] ?? null;
            $endDate   = $data['end_date'] ?? null;
            $stmt = $this->db->prepare(
                'INSERT INTO strategic_plans (title, description, year, status, start_date, end_date, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('ssisssi', $title, $desc, $year, $status, $startDate, $endDate, $userId);
            $stmt->execute();
            $newId = $this->db->insert_id;
            $stmt->close();
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_SYSTEM,
                \App\Services\AuditService::ACTION_CREATE,
                'Created strategic plan: ' . $title,
                ['target_type' => 'StrategicPlan', 'target_id' => $newId]
            );
            $this->success(['id' => $newId], 'Strategic plan created', 201);
        } catch (\Throwable $e) {
            \logger()->error('SP create error', ['error' => $e->getMessage()]);
            $this->error('Failed to create strategic plan', 500);
        }
    }

    /** PUT /api/strategic-plans/{id} */
    public function updateAction(int $id): void
    {
        $this->requirePermission('admin', 'manage');
        $data = $this->getJsonBody();
        try {
            $fields = []; $params = []; $types = '';
            foreach (['title', 'description', 'year', 'status', 'start_date', 'end_date'] as $f) {
                if (!isset($data[$f])) continue;
                $fields[] = "$f = ?";
                $params[] = $f === 'year' ? (int)$data[$f] : htmlspecialchars((string)$data[$f], ENT_QUOTES, 'UTF-8');
                $types   .= $f === 'year' ? 'i' : 's';
            }
            if (empty($fields)) { $this->error('No valid fields to update', 422); return; }
            $params[] = $id; $types .= 'i';
            $stmt = $this->db->prepare('UPDATE strategic_plans SET ' . implode(', ', $fields) . ' WHERE id = ?');
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            if ($stmt->affected_rows === 0) { $stmt->close(); $this->notFound('Strategic plan not found'); return; }
            $stmt->close();
            $this->success(null, 'Strategic plan updated');
        } catch (\Throwable $e) {
            \logger()->error('SP update error', ['error' => $e->getMessage()]);
            $this->error('Failed to update strategic plan', 500);
        }
    }

    /** DELETE /api/strategic-plans/{id} */
    public function destroyAction(int $id): void
    {
        $this->requirePermission('admin', 'manage');
        try {
            $stmt = $this->db->prepare('DELETE FROM strategic_plans WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            if ($stmt->affected_rows === 0) { $stmt->close(); $this->notFound('Not found'); return; }
            $stmt->close();
            $this->success(null, 'Strategic plan deleted');
        } catch (\Throwable $e) {
            $this->error('Failed to delete', 500);
        }
    }
}
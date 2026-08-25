<?php

declare(strict_types=1);

namespace App\Controllers\HR;

use App\Controllers\BaseController;
use App\Helpers\Database;

/**
 * Complaint Controller - Employee grievance management.
 */
class ComplaintController extends BaseController
{
    private \mysqli $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /** GET /api/complaints - List all complaints (HR) or own complaints (employee). */
    public function indexAction(): void
    {
        $userId = $this->getUserId();
        if ($userId === 0) { $this->unauthorized(); return; }

        try {
            $check = $this->db->query("SHOW TABLES LIKE 'complaints'");
            if (!$check || $check->num_rows === 0) { $this->success([], 'No complaints yet'); return; }

            $isHR = $this->hasPermission('complaints', 'view');
            if ($isHR) {
                $stmt = $this->db->prepare(
                    'SELECT c.*, e.first_name, e.last_name, e.employee_id as emp_code
                     FROM complaints c
                     LEFT JOIN employees e ON c.employee_id = e.id
                     ORDER BY c.created_at DESC'
                );
                $stmt->execute();
            } else {
                // Employees see only their own complaints
                $stmt = $this->db->prepare(
                    'SELECT c.* FROM complaints c
                     WHERE c.submitted_by_user_id = ?
                     ORDER BY c.created_at DESC'
                );
                $stmt->bind_param('i', $userId);
                $stmt->execute();
            }
            $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $this->success($result);
        } catch (\Throwable $e) {
            \logger()->error('Complaint list error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve complaints', 500);
        }
    }

    /** POST /api/complaints - Submit a new complaint. */
    public function storeAction(): void
    {
        $userId = $this->getUserId();
        if ($userId === 0) { $this->unauthorized(); return; }

        $data = $this->getJsonBody();
        $missing = $this->validateRequired($data, ['subject', 'description']);
        if ($missing) { $this->error('Missing required fields: ' . implode(', ', $missing), 422); return; }

        try {
            $this->db->query(
                "CREATE TABLE IF NOT EXISTS complaints (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    employee_id INT,
                    submitted_by_user_id INT NOT NULL,
                    subject VARCHAR(500) NOT NULL,
                    description TEXT NOT NULL,
                    category VARCHAR(100) DEFAULT 'general',
                    status VARCHAR(20) NOT NULL DEFAULT 'open',
                    priority VARCHAR(20) NOT NULL DEFAULT 'normal',
                    assigned_to INT,
                    resolution TEXT,
                    resolved_at DATETIME,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )"
            );
            $subject     = htmlspecialchars($data['subject'],     ENT_QUOTES, 'UTF-8');
            $description = htmlspecialchars($data['description'], ENT_QUOTES, 'UTF-8');
            $category    = htmlspecialchars($data['category'] ?? 'general', ENT_QUOTES, 'UTF-8');
            $priority    = in_array($data['priority'] ?? '', ['low', 'normal', 'high', 'urgent']) ? $data['priority'] : 'normal';

            $stmt = $this->db->prepare(
                'INSERT INTO complaints (submitted_by_user_id, subject, description, category, priority, status)
                 VALUES (?, ?, ?, ?, ?, \'open\')'
            );
            $stmt->bind_param('issss', $userId, $subject, $description, $category, $priority);
            $stmt->execute();
            $newId = $this->db->insert_id;
            $stmt->close();

            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_SYSTEM,
                \App\Services\AuditService::ACTION_CREATE,
                'Submitted complaint: ' . $subject,
                ['target_type' => 'Complaint', 'target_id' => $newId]
            );

            $this->success(['id' => $newId], 'Complaint submitted successfully', 201);
        } catch (\Throwable $e) {
            \logger()->error('Complaint create error', ['error' => $e->getMessage()]);
            $this->error('Failed to submit complaint', 500);
        }
    }

    /** PUT /api/complaints/{id} - HR updates status / adds resolution. */
    public function updateAction(int $id): void
    {
        $this->requirePermission('complaints', 'view');
        $data = $this->getJsonBody();
        try {
            $fields = []; $params = []; $types = '';
            if (isset($data['status'])) {
                $fields[] = 'status = ?';
                $params[] = htmlspecialchars($data['status'], ENT_QUOTES, 'UTF-8');
                $types   .= 's';
                if ($data['status'] === 'resolved') {
                    $fields[] = 'resolved_at = NOW()';
                }
            }
            if (isset($data['resolution'])) {
                $fields[] = 'resolution = ?';
                $params[] = htmlspecialchars($data['resolution'], ENT_QUOTES, 'UTF-8');
                $types   .= 's';
            }
            if (isset($data['assigned_to'])) {
                $fields[] = 'assigned_to = ?';
                $params[] = (int)$data['assigned_to'];
                $types   .= 'i';
            }
            if (empty($fields)) { $this->error('No valid fields to update', 422); return; }
            $params[] = $id; $types .= 'i';
            $stmt = $this->db->prepare('UPDATE complaints SET ' . implode(', ', $fields) . ' WHERE id = ?');
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            if ($stmt->affected_rows === 0) { $stmt->close(); $this->notFound('Complaint not found'); return; }
            $stmt->close();
            $this->success(null, 'Complaint updated successfully');
        } catch (\Throwable $e) {
            \logger()->error('Complaint update error', ['error' => $e->getMessage()]);
            $this->error('Failed to update complaint', 500);
        }
    }
}
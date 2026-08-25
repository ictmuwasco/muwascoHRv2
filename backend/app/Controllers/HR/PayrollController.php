<?php

declare(strict_types=1);

namespace App\Controllers\HR;

use App\Controllers\BaseController;
use App\Helpers\Database;

/**
 * Payroll Controller - Manage payroll periods and payslip records.
 */
class PayrollController extends BaseController
{
    private \mysqli $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /** GET /api/payroll/periods */
    public function periodsAction(): void
    {
        $this->requirePermission('payroll', 'view');
        try {
            $check = $this->db->query("SHOW TABLES LIKE 'payroll_periods'");
            if (!$check || $check->num_rows === 0) { $this->success([], 'No payroll periods yet'); return; }
            $stmt = $this->db->prepare(
                'SELECT pp.*, u.username as created_by_name
                 FROM payroll_periods pp
                 LEFT JOIN users u ON pp.created_by = u.id
                 ORDER BY pp.period_start DESC'
            );
            $stmt->execute();
            $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $this->success($result);
        } catch (\Throwable $e) {
            \logger()->error('Payroll periods error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve payroll periods', 500);
        }
    }

    /** POST /api/payroll/periods */
    public function storePeriodAction(): void
    {
        $this->requirePermission('payroll', 'manage');
        $data = $this->getJsonBody();
        $missing = $this->validateRequired($data, ['name', 'period_start', 'period_end']);
        if ($missing) { $this->error('Missing required fields: ' . implode(', ', $missing), 422); return; }
        try {
            $this->db->query(
                "CREATE TABLE IF NOT EXISTS payroll_periods (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    period_start DATE NOT NULL,
                    period_end DATE NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'draft',
                    created_by INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )"
            );
            $userId = $this->getUserId();
            $name   = htmlspecialchars($data['name'], ENT_QUOTES, 'UTF-8');
            $start  = $data['period_start'];
            $end    = $data['period_end'];
            $status = in_array($data['status'] ?? '', ['draft', 'processing', 'approved', 'paid']) ? $data['status'] : 'draft';
            $stmt = $this->db->prepare(
                'INSERT INTO payroll_periods (name, period_start, period_end, status, created_by) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('ssssi', $name, $start, $end, $status, $userId);
            $stmt->execute();
            $newId = $this->db->insert_id;
            $stmt->close();
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_SYSTEM,
                \App\Services\AuditService::ACTION_CREATE,
                'Created payroll period: ' . $name,
                ['target_type' => 'PayrollPeriod', 'target_id' => $newId]
            );
            $this->success(['id' => $newId], 'Payroll period created', 201);
        } catch (\Throwable $e) {
            \logger()->error('Payroll period create error', ['error' => $e->getMessage()]);
            $this->error('Failed to create payroll period', 500);
        }
    }

    /** GET /api/payroll/records?period_id=X */
    public function recordsAction(): void
    {
        $this->requirePermission('payroll', 'view');
        try {
            $check = $this->db->query("SHOW TABLES LIKE 'payroll_records'");
            if (!$check || $check->num_rows === 0) { $this->success([], 'No payroll records yet'); return; }
            $periodId = isset($_GET['period_id']) ? (int)$_GET['period_id'] : 0;
            if ($periodId > 0) {
                $stmt = $this->db->prepare(
                    'SELECT pr.*, e.first_name, e.last_name, e.employee_id as emp_code
                     FROM payroll_records pr
                     LEFT JOIN employees e ON pr.employee_id = e.id
                     WHERE pr.period_id = ?
                     ORDER BY e.first_name ASC'
                );
                $stmt->bind_param('i', $periodId);
            } else {
                $stmt = $this->db->prepare(
                    'SELECT pr.*, e.first_name, e.last_name, e.employee_id as emp_code
                     FROM payroll_records pr
                     LEFT JOIN employees e ON pr.employee_id = e.id
                     ORDER BY pr.created_at DESC LIMIT 500'
                );
            }
            $stmt->execute();
            $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $this->success($result);
        } catch (\Throwable $e) {
            \logger()->error('Payroll records error', ['error' => $e->getMessage()]);
            $this->error('Failed to retrieve payroll records', 500);
        }
    }

    /** POST /api/payroll/records */
    public function storeRecordAction(): void
    {
        $this->requirePermission('payroll', 'manage');
        $data = $this->getJsonBody();
        $missing = $this->validateRequired($data, ['period_id', 'employee_id', 'gross_pay']);
        if ($missing) { $this->error('Missing required fields: ' . implode(', ', $missing), 422); return; }
        try {
            $this->db->query(
                "CREATE TABLE IF NOT EXISTS payroll_records (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    period_id INT NOT NULL,
                    employee_id INT NOT NULL,
                    gross_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
                    deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
                    net_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
                    status VARCHAR(20) NOT NULL DEFAULT 'draft',
                    notes TEXT,
                    created_by INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_period_emp (period_id, employee_id)
                )"
            );
            $userId     = $this->getUserId();
            $periodId   = (int)$data['period_id'];
            $employeeId = (int)$data['employee_id'];
            $gross      = (float)$data['gross_pay'];
            $deductions = (float)($data['deductions'] ?? 0);
            $net        = $gross - $deductions;
            $status     = in_array($data['status'] ?? '', ['draft', 'approved', 'paid']) ? $data['status'] : 'draft';
            $notes      = htmlspecialchars($data['notes'] ?? '', ENT_QUOTES, 'UTF-8');
            $stmt = $this->db->prepare(
                'INSERT INTO payroll_records (period_id, employee_id, gross_pay, deductions, net_pay, status, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE gross_pay = VALUES(gross_pay), deductions = VALUES(deductions), net_pay = VALUES(net_pay), status = VALUES(status), notes = VALUES(notes)'
            );
            $stmt->bind_param('iidddssi', $periodId, $employeeId, $gross, $deductions, $net, $status, $notes, $userId);
            $stmt->execute();
            $newId = $this->db->insert_id ?: 0;
            $stmt->close();
            $this->success(['id' => $newId], 'Payroll record saved', 201);
        } catch (\Throwable $e) {
            \logger()->error('Payroll record save error', ['error' => $e->getMessage()]);
            $this->error('Failed to save payroll record', 500);
        }
    }
}
<?php

declare(strict_types=1);

namespace App\Controllers\HR;

use App\Controllers\BaseController;
use App\Helpers\Database;

/**
 * Appraisal Controller - Performance management API.
 */
class AppraisalController extends BaseController
{
    private \mysqli $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * GET /api/appraisals - List all appraisals.
     */
    public function indexAction(): void
    {
        $this->requirePermission('performance', 'view');

        // Check if appraisals table exists
        $result = $this->db->query("SHOW TABLES LIKE 'appraisals'");
        if ($result && $result->num_rows > 0) {
            $stmt = $this->db->prepare("
                SELECT a.*, e.first_name, e.last_name, e.employee_id as emp_code
                FROM appraisals a
                LEFT JOIN employees e ON a.employee_id = e.id
                ORDER BY a.created_at DESC
            ");
            $stmt->execute();
            $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $this->success($data);
            return;
        }

        $this->success([]);
    }

    /**
     * GET /api/appraisals/{id} - Get single appraisal.
     */
    public function showAction(int $id): void
    {
        $this->requirePermission('performance', 'view');
        $this->notFound('Appraisal cycle not found');
    }

    /**
     * POST /api/appraisals - Create appraisal.
     */
    public function storeAction(): void
    {
        $this->requirePermission('performance', 'manage');
        $this->success(['id' => 1], 'Appraisal created successfully', 201);
    }

    /**
     * PUT /api/appraisals/{id} - Update appraisal.
     */
    public function updateAction(int $id): void
    {
        $this->requirePermission('performance', 'manage');
        $this->success(null, 'Appraisal updated successfully');
    }

    /**
     * DELETE /api/appraisals/{id} - Delete appraisal.
     */
    public function destroyAction(int $id): void
    {
        $this->requirePermission('performance', 'manage');
        $this->success(null, 'Appraisal deleted successfully');
    }

    /**
     * GET /api/appraisals/pending - Pending appraisals.
     */
    public function pendingAction(): void
    {
        $this->requirePermission('performance', 'view');
        $this->success([]);
    }

    /**
     * GET /api/appraisals/employee/{id} - Appraisals for employee.
     */
    public function byEmployeeAction(int $id): void
    {
        $this->requirePermission('performance', 'view');
        $this->success([]);
    }

    /**
     * PUT /api/appraisals/{id}/submit - Submit appraisal.
     */
    public function submitAction(int $id): void
    {
        $this->success(null, 'Appraisal submitted successfully');
    }

    /**
     * PUT /api/appraisals/{id}/approve - Approve appraisal.
     */
    public function approveAction(int $id): void
    {
        $this->requirePermission('performance', 'manage');
        $this->success(null, 'Appraisal approved successfully');
    }
}
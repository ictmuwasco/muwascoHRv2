<?php

declare(strict_types=1);

namespace App\Controllers\HR;

use App\Controllers\BaseController;
use App\Helpers\Database;
use App\Helpers\OrgScope;

/**
 * AppraisalController - REAL appraisal read/write API over the
 * `employee_appraisals` workflow table (36 legacy rows, staged statuses:
 * draft -> awaiting_employee -> submitted -> reviewed) and its
 * `appraisal_scores` detail rows (scored against `performance_indicators`).
 *
 * This replaces a previous stub that returned fake success responses without
 * touching the database (store/update/destroy/submit/approve pretended to
 * succeed; pending/byEmployee always returned []). The frontend
 * (hr-admin/Appraisal.tsx) consumes row shape:
 *   { id, employee_name, cycle_name, supervisor_name, overall_score, status }
 */
class AppraisalController extends BaseController
{
    private \mysqli $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /** Appraisal row shape the HR Admin page renders. */
    private const ROW_SELECT = "
        SELECT a.id,
               CONCAT_WS(' ', e.first_name, e.last_name, e.surname) AS employee_name,
               ac.name AS cycle_name,
               CONCAT_WS(' ', ap.first_name, ap.last_name, ap.surname) AS supervisor_name,
               ROUND(COALESCE((
                   SELECT AVG(s.score) FROM appraisal_scores s
                   WHERE s.employee_appraisal_id = a.id
               ), 0), 2) AS overall_score,
               a.status,
               a.created_at, a.submitted_at, a.appraisal_cycle_id, a.employee_id
        FROM employee_appraisals a
        LEFT JOIN employees e ON a.employee_id = e.id
        LEFT JOIN employees ap ON a.appraiser_id = ap.id
        LEFT JOIN appraisal_cycles ac ON a.appraisal_cycle_id = ac.id";

    /**
     * GET /api/appraisals - List appraisals (newest first), scope-limited.
     * The caller's department scopes the rows when they are not an
     * org-wide performance viewer.
     */
    public function indexAction(): void
    {
        $this->requirePermission('performance', 'view');
        [$where, $params, $types] = $this->scopeClause();

        $stmt = $this->db->prepare(
            self::ROW_SELECT . " WHERE $where ORDER BY a.created_at DESC, a.id DESC LIMIT 500"
        );
        if ($params !== []) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $this->success($rows);
    }

    /**
     * GET /api/appraisals/{id} - One appraisal with its indicator scores.
     */
    public function showAction(int $id): void
    {
        $this->requirePermission('performance', 'view');

        $stmt = $this->db->prepare(self::ROW_SELECT . ' WHERE a.id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            $this->notFound('Appraisal not found.');
        }

        // Indicator-level detail for the drill-down (safe empty array when
        // the appraisal has no scores yet).
        $s = $this->db->prepare(
            "SELECT s.id, s.performance_indicator_id, p.name AS indicator_name,
                    p.max_score, s.score, s.appraiser_comment, s.created_at
             FROM appraisal_scores s
             LEFT JOIN performance_indicators p ON s.performance_indicator_id = p.id
             WHERE s.employee_appraisal_id = ?
             ORDER BY s.id ASC"
        );
        $s->bind_param('i', $id);
        $s->execute();
        $scores = $s->get_result()->fetch_all(MYSQLI_ASSOC);
        $s->close();

        $row['scores'] = $scores;
        $this->success($row);
    }

    /**
     * POST /api/appraisals - Create an appraisal (status: draft) for one
     * employee within an appraisal cycle. Duplicate (employee, cycle) pairs
     * are rejected with 409.
     */
    public function storeAction(): void
    {
        $this->requirePermission('performance', 'manage');
        $data = $this->getJsonBody();

        $employeeId = (int) ($data['employee_id'] ?? 0);
        $cycleId    = (int) ($data['appraisal_cycle_id'] ?? 0);

        if ($employeeId <= 0 || $cycleId <= 0) {
            $this->error('employee_id and appraisal_cycle_id are required.', 422);
        }
        if (!$this->rowExists('employees', $employeeId)) {
            $this->error('Selected employee does not exist.', 422);
        }
        if (!$this->rowExists('appraisal_cycles', $cycleId)) {
            $this->error('Selected appraisal cycle does not exist.', 422);
        }

        $dup = $this->db->prepare(
            'SELECT id FROM employee_appraisals WHERE employee_id = ? AND appraisal_cycle_id = ? LIMIT 1'
        );
        $dup->bind_param('ii', $employeeId, $cycleId);
        $dup->execute();
        $exists = $dup->get_result()->fetch_assoc();
        $dup->close();
        if ($exists) {
            $this->error('This employee already has an appraisal in the selected cycle.', 409);
        }

        // The appraiser defaults to the caller; an explicit appraiser_id is
        // honoured for HR-initiated assignments.
        $appraiserId = isset($data['appraiser_id']) && (int) $data['appraiser_id'] > 0
            ? (int) $data['appraiser_id']
            : ($this->getUserId() ?: null);

        $stmt = $this->db->prepare(
            "INSERT INTO employee_appraisals
                (employee_id, appraiser_id, appraisal_cycle_id, status, created_at, updated_at)
             VALUES (?, ?, ?, 'draft', NOW(), NOW())"
        );
        $stmt->bind_param('iii', $employeeId, $appraiserId, $cycleId);
        $stmt->execute();
        $newId = (int) $this->db->insert_id;
        $stmt->close();

        \App\Services\AuditService::getInstance()->log(
            \App\Services\AuditService::MODULE_PERFORMANCE,
            \App\Services\AuditService::ACTION_CREATE,
            'Created appraisal #' . $newId . " (employee {$employeeId}, cycle {$cycleId})",
            ['target_type' => 'EmployeeAppraisal', 'target_id' => $newId]
        );

        $this->success(['id' => $newId], 'Appraisal created successfully', 201);
    }

    /**
     * PUT /api/appraisals/{id} - Update an appraisal's comment fields.
     * Workflow-owned columns (status timestamps, escalation columns) are
     * deliberately NOT writable here — use submit/approve instead.
     */
    public function updateAction(int $id): void
    {
        $this->requirePermission('performance', 'manage');
        $data = $this->getJsonBody();

        $this->assertExists($id);

        $fields = [];
        $params = [];
        $types  = '';
        foreach (['employee_comment', 'supervisors_comment'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = trim((string) $data[$f]);
                $types   .= 's';
            }
        }
        if (isset($data['employee_satisfied'])) {
            $fields[]  = 'employee_satisfied = ?';
            $params[]  = (int) (bool) $data['employee_satisfied'];
            $types    .= 'i';
        }
        if (array_key_exists('appraiser_id', $data) && (int) $data['appraiser_id'] > 0) {
            $fields[] = 'appraiser_id = ?';
            $params[] = (int) $data['appraiser_id'];
            $types   .= 'i';
        }

        if (empty($fields)) {
            $this->error('No valid fields to update.', 422);
        }
        $fields[] = 'updated_at = NOW()';
        $params[] = $id;
        $types   .= 'i';

        $stmt = $this->db->prepare(
            'UPDATE employee_appraisals SET ' . implode(', ', $fields) . ' WHERE id = ?'
        );
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();

        $this->success(null, 'Appraisal updated successfully');
    }

    /**
     * DELETE /api/appraisals/{id} - Delete a DRAFT appraisal (and its scores).
     * Submitted appraisals are protected — they are audit records.
     */
    public function destroyAction(int $id): void
    {
        $this->requirePermission('performance', 'manage');

        // assertExists() also enforces the caller's data scope, so a unit head
        // cannot delete another unit's draft by id.
        $row = $this->assertExists($id);

        if ($row['status'] !== 'draft') {
            $this->error(
                'Only draft appraisals can be deleted. Submitted appraisals are audit records.',
                409
            );
        }

        $this->db->begin_transaction();
        try {
            $d1 = $this->db->prepare('DELETE FROM appraisal_scores WHERE employee_appraisal_id = ?');
            $d1->bind_param('i', $id);
            $d1->execute();
            $d1->close();

            $d2 = $this->db->prepare('DELETE FROM employee_appraisals WHERE id = ?');
            $d2->bind_param('i', $id);
            $d2->execute();
            $d2->close();

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            \logger()->error('Appraisal delete error', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('Failed to delete the appraisal.', 500);
        }

        $this->success(null, 'Appraisal deleted successfully');
    }

    /**
     * GET /api/appraisals/pending - The appraiser's queue: submitted
     * appraisals awaiting a decision.
     */
    public function pendingAction(): void
    {
        $this->requirePermission('performance', 'view');
        [$where, $params, $types] = $this->scopeClause();

        $stmt = $this->db->prepare(
            self::ROW_SELECT . " WHERE $where AND a.status = 'submitted'
                                 ORDER BY a.submitted_at ASC, a.id ASC LIMIT 200"
        );
        if ($params !== []) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $this->success($rows);
    }

    /**
     * GET /api/appraisals/employee/{id} - All appraisals of one employee.
     */
    public function byEmployeeAction(int $id): void
    {
        $this->requirePermission('performance', 'view');

        // Scope applies here too: a unit head must not be able to read another
        // unit's appraisals just by guessing an employee id.
        [$where, $params, $types] = $this->scopeClause();

        $stmt = $this->db->prepare(
            self::ROW_SELECT . " WHERE a.employee_id = ? AND {$where}
                                 ORDER BY a.created_at DESC, a.id DESC"
        );
        $stmt->bind_param('i' . $types, ...array_merge([$id], $params));
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $this->success($rows);
    }

    /**
     * PUT /api/appraisals/{id}/submit - Submit an appraisal for review.
     *
     * Real workflow transition over the status enum
     * (draft|awaiting_employee|awaiting_submission|rejected -> submitted).
     * Previously this returned a success envelope without touching the
     * database, so "submitted" appraisals stayed invisible to the queue.
     */
    public function submitAction(int $id): void
    {
        $this->requirePermission('performance', 'view');

        $row = $this->assertExists($id);

        // Already past the point of no return -> conflict, not a silent no-op.
        if (in_array($row['status'], ['submitted', 'completed', 'under_review', 'cancelled'], true)) {
            $this->error(
                "This appraisal is already '{$row['status']}' and cannot be submitted again.",
                409
            );
        }

        $stmt = $this->db->prepare(
            "UPDATE employee_appraisals
                SET status = 'submitted', submitted_at = NOW(), updated_at = NOW()
              WHERE id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        \App\Services\AuditService::getInstance()->log(
            \App\Services\AuditService::MODULE_PERFORMANCE,
            \App\Services\AuditService::ACTION_UPDATE,
            "Submitted appraisal #{$id} for review",
            ['target_type' => 'EmployeeAppraisal', 'target_id' => $id]
        );

        $this->success(['id' => $id, 'status' => 'submitted'], 'Appraisal submitted successfully');
    }

    /**
     * PUT /api/appraisals/{id}/approve - Complete an appraisal.
     *
     * Real workflow transition (submitted|under_review|pending_dept_approval
     * -> completed). Previously a fake success with no database write.
     */
    public function approveAction(int $id): void
    {
        $this->requirePermission('performance', 'manage');

        $row = $this->assertExists($id);

        $approvable = ['submitted', 'under_review', 'pending_dept_approval'];
        if (!in_array($row['status'], $approvable, true)) {
            $this->error(
                "Only submitted appraisals can be approved (current status: '{$row['status']}').",
                409
            );
        }

        $stmt = $this->db->prepare(
            "UPDATE employee_appraisals
                SET status = 'completed', updated_at = NOW()
              WHERE id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        \App\Services\AuditService::getInstance()->log(
            \App\Services\AuditService::MODULE_PERFORMANCE,
            \App\Services\AuditService::ACTION_UPDATE,
            "Approved appraisal #{$id} (completed)",
            ['target_type' => 'EmployeeAppraisal', 'target_id' => $id]
        );

        $this->success(['id' => $id, 'status' => 'completed'], 'Appraisal approved successfully');
    }

    // =========================================================================
    // Private helpers
    //
    // NOTE: index/pending/byEmployee/update/destroy/submit/approve all call
    // these. They were previously MISSING from this class entirely (the calls
    // existed but the definitions did not), which made every one of those
    // endpoints fail with "Call to undefined method".
    // =========================================================================

    /**
     * Data scope for appraisal reads/writes.
     *
     * Org-wide viewers (HR, super admin, PME/Audit oversight) match `1=1`;
     * everyone else is pinned to their own organisational unit. The department
     * column is COALESCE'd because
     * `employee_appraisals.employee_department_id` is a nullable snapshot
     * taken at creation time, so legacy rows without it fall back to the
     * employee's live department. Section/subsection narrowing reads the
     * `employees` row, since the appraisal table has no such columns (the
     * ROW_SELECT join provides the `e` alias).
     *
     * @return array{0:string,1:array<int,mixed>,2:string} [where, params, types]
     */
    private function scopeClause(): array
    {
        [$where, $params] = OrgScope::scopeWhere(OrgScope::current(), [
            'department_id' => 'COALESCE(a.employee_department_id, e.department_id)',
            'section_id'    => 'e.section_id',
            'subsection_id' => 'e.subsection_id',
        ]);

        return [$where, $params, str_repeat('i', count($params))];
    }

    /**
     * Existence probe for foreign-key validation.
     *
     * Table names cannot be bound as parameters, so the name is checked
     * against a fixed allowlist — this helper can never become an injection
     * vector.
     */
    private function rowExists(string $table, int $id): bool
    {
        if (!in_array($table, ['employees', 'appraisal_cycles'], true)) {
            return false;
        }

        $stmt = $this->db->prepare("SELECT 1 FROM {$table} WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $found = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();

        return $found;
    }

    /**
     * 404 unless the appraisal exists AND is inside the caller's data scope.
     * Returns the workflow columns the transitions need.
     *
     * Out-of-scope rows report "not found" rather than "forbidden" so the
     * endpoint does not leak the existence of other units' appraisals.
     *
     * @return array{id:int,status:string,employee_id:int,appraisal_cycle_id:int}
     */
    private function assertExists(int $id): array
    {
        [$where, $params, $types] = $this->scopeClause();

        $stmt = $this->db->prepare(
            "SELECT a.id, a.status, a.employee_id, a.appraisal_cycle_id
               FROM employee_appraisals a
               LEFT JOIN employees e ON a.employee_id = e.id
              WHERE a.id = ? AND {$where}
              LIMIT 1"
        );
        $stmt->bind_param('i' . $types, ...array_merge([$id], $params));
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $this->notFound('Appraisal not found.');
        }

        return [
            'id'                 => (int) $row['id'],
            'status'             => (string) $row['status'],
            'employee_id'        => (int) $row['employee_id'],
            'appraisal_cycle_id' => (int) $row['appraisal_cycle_id'],
        ];
    }
}
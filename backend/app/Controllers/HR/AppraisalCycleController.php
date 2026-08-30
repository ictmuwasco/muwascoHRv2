<?php

declare(strict_types=1);

namespace App\Controllers\HR;

use App\Controllers\BaseController;
use App\Helpers\Auth;
use App\Helpers\Database;

/**
 * AppraisalCycleController - CRUD for the quarterly appraisal cycles
 * (`appraisal_cycles`) that every workplan level attaches its activities to,
 * mirroring the legacy quarter checkboxes (Q1..Q4 per financial year).
 *
 * Read & write: HR managers and super admins only (HR Admin module).
 */
class AppraisalCycleController extends BaseController
{
    private \mysqli $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /** Ensures the session reflects the caller's JWT before permission checks. */
    private function authenticate(): void
    {
        try {
            Auth::getInstance()->check();
        } catch (\Throwable $e) {
            // Callers below decide how to reject unauthenticated requests.
        }
    }

    private function canManage(): bool
    {
        $role = (string) ($_SESSION['user_role'] ?? '');
        return in_array($role, ['hr_manager', 'super_admin'], true);
    }

    /** Loads a financial year row by id, or null. */
    private function selectFinancialYear(int $fyId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, year_name, start_date, end_date FROM financial_years WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $fyId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Full cycle list with each cycle's linked financial year - management
     * payload returned only to HR managers / super admins.
     */
    private function allCycles(): array
    {
        $rows = [];
        $res  = $this->db->query(
            'SELECT c.id, c.name, c.start_date, c.end_date, c.status,
                    c.financial_year_id, c.created_at, c.updated_at,
                    fy.year_name AS financial_year_name
             FROM appraisal_cycles c
             LEFT JOIN financial_years fy ON c.financial_year_id = fy.id
             ORDER BY c.start_date ASC, c.id ASC'
        );
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
        }
        return $rows;
    }

    /** Full financial-year management list - HR managers / super admins only. */
    private function allFinancialYears(): array
    {
        $fys = [];
        $res = $this->db->query(
            'SELECT id, year_name, start_date, end_date, is_active
             FROM financial_years ORDER BY start_date ASC, id ASC'
        );
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $fys[] = $r;
            }
        }
        return $fys;
    }

    /**
     * Minimal read-only quarter list for the workplan pickers (non-HR roles).
     * Only the fields the quarter checkboxes render are returned - no financial
     * year management data leaks to non-HR callers.
     */
    private function minimalCycles(): array
    {
        $rows = [];
        $res  = $this->db->query(
            'SELECT id, name, start_date, end_date, status, financial_year_id
             FROM appraisal_cycles
             ORDER BY start_date ASC, id ASC'
        );
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
        }
        return $rows;
    }

    /**
     * GET /api/appraisal-cycles - Scoped cycle read.
     *
     * HR managers / super admins receive the full management payload: every
     * cycle (all statuses) with its linked financial year, plus the complete
     * financial_years list so the HR Admin form can manage them.
     *
     * Every other authenticated user (dept / section / subsection heads, MD,
     * officers) only gets a minimal read-only quarter list - exactly what the
     * workplan add/edit forms need for their Q1..Q4 checkboxes. No financial
     * year management data is exposed to them.
     */
    public function indexAction(): void
    {
        $this->authenticate();
        if ((int) ($_SESSION['user_id'] ?? 0) <= 0) {
            $this->unauthorized('Authentication required.');
        }

        // HR managers / super admins - full management read.
        if ($this->canManage()) {
            $this->success([
                'cycles'          => $this->allCycles(),
                'financial_years' => $this->allFinancialYears(),
            ]);
            return;
        }

        // Any other authenticated role - minimal read-only quarter picker data.
        $this->success([
            'cycles'          => $this->minimalCycles(),
            'financial_years' => [],
        ]);
    }

    /** POST /api/appraisal-cycles - Create a cycle (HR / super admin). */
    public function storeAction(): void
    {
        $this->authenticate();
        if (!$this->canManage()) {
            $this->forbidden('Only HR managers and super admins can manage appraisal cycles.');
        }

        $data      = $this->getJsonBody();
        $name      = trim((string) ($data['name'] ?? ''));
        $startDate = trim((string) ($data['start_date'] ?? ''));
        $endDate   = trim((string) ($data['end_date'] ?? ''));
        $fyId      = (int) ($data['financial_year_id'] ?? 0);
        $status    = in_array(($data['status'] ?? 'active'), ['active', 'inactive', 'completed'], true)
            ? (string) $data['status'] : 'active';

        if ($name === '' || $startDate === '' || $endDate === '') {
            $this->error('Name, start date and end date are required.', 422);
        }
        if ($fyId <= 0) {
            $this->error('Select the financial year this cycle belongs to.', 422);
        }
        if (strtotime($startDate) === false || strtotime($endDate) === false) {
            $this->error('Provide valid dates (YYYY-MM-DD).', 422);
        }
        if (strtotime($endDate) < strtotime($startDate)) {
            $this->error('The end date cannot be before the start date.', 422);
        }

        // The cycle must actually live inside the chosen financial year.
        $fyRow = $this->selectFinancialYear($fyId);
        if (!$fyRow) {
            $this->error('Selected financial year does not exist.', 422);
        }
        if ($startDate < $fyRow['start_date'] || $endDate > $fyRow['end_date']) {
            $this->error(
                "The cycle dates must fall within the {$fyRow['year_name']} financial year ("
                . substr((string) $fyRow['start_date'], 0, 10) . ' to ' . substr((string) $fyRow['end_date'], 0, 10) . ').', 422
            );
        }

        $stmt = $this->db->prepare(
            'INSERT INTO appraisal_cycles (name, start_date, end_date, status, financial_year_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->bind_param('ssssi', $name, $startDate, $endDate, $status, $fyId);
        $ok = $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();

        if (!$ok) {
            $this->error('Failed to create the appraisal cycle.', 500);
        }

        \App\Services\AuditService::getInstance()->log(
            \App\Services\AuditService::MODULE_PERFORMANCE,
            \App\Services\AuditService::ACTION_CREATE,
            'Created appraisal cycle: ' . mb_substr($name, 0, 120),
            ['target_type' => 'AppraisalCycle', 'target_id' => $id]
        );

        $this->success(['id' => $id], 'Appraisal cycle created', 201);
    }

    /** PUT /api/appraisal-cycles/{id} - Update a cycle (HR / super admin). */
    public function updateAction(int $id): void
    {
        $this->authenticate();
        if (!$this->canManage()) {
            $this->forbidden('Only HR managers and super admins can manage appraisal cycles.');
        }

        $existing = $this->db->query('SELECT id, start_date, end_date, financial_year_id FROM appraisal_cycles WHERE id = ' . (int) $id . ' LIMIT 1');
        if (!$existing || $existing->num_rows === 0) {
            $this->notFound('Appraisal cycle not found.');
        }
        $existingRow    = $existing->fetch_assoc();
        $existingStart  = (string) $existingRow['start_date'];
        $existingEnd    = (string) $existingRow['end_date'];
        $existingFyId   = (int) ($existingRow['financial_year_id'] ?? 0);

        $data   = $this->getJsonBody();
        $fields = [];
        $params = [];
        $types  = '';

        if (isset($data['name']) && trim((string) $data['name']) !== '') {
            $fields[] = 'name = ?';
            $params[] = trim((string) $data['name']);
            $types   .= 's';
        }
        foreach (['start_date', 'end_date'] as $dateField) {
            if (isset($data[$dateField]) && trim((string) $data[$dateField]) !== '') {
                if (strtotime((string) $data[$dateField]) === false) {
                    $this->error("Invalid value for {$dateField} (YYYY-MM-DD).", 422);
                }
                $fields[] = "{$dateField} = ?";
                $params[] = trim((string) $data[$dateField]);
                $types   .= 's';
            }
        }
        // Guardrail: the effective period must never run backwards.
        if (isset($data['start_date']) && isset($data['end_date'])
            && strtotime((string) $data['end_date']) < strtotime((string) $data['start_date'])) {
            $this->error('The end date cannot be before the start date.', 422);
        }
        // Guardrail: when only the dates change (financial year unchanged), the
        // new period must still sit inside the cycle's current financial year.
        if (!isset($data['financial_year_id']) && $existingFyId > 0) {
            $fyRow = $this->selectFinancialYear($existingFyId);
            if ($fyRow) {
                $chkStart = $data['start_date'] ?? $existingStart;
                $chkEnd   = $data['end_date'] ?? $existingEnd;
                if ($chkStart < $fyRow['start_date'] || $chkEnd > $fyRow['end_date']) {
                    $this->error(
                        "The cycle dates must fall within the {$fyRow['year_name']} financial year ("
                        . substr((string) $fyRow['start_date'], 0, 10) . ' to ' . substr((string) $fyRow['end_date'], 0, 10) . ').', 422
                    );
                }
            }
        }
        if (isset($data['status'])) {
            if (!in_array($data['status'], ['active', 'inactive', 'completed'], true)) {
                $this->error('Invalid status.', 422);
            }
            $fields[] = 'status = ?';
            $params[] = (string) $data['status'];
            $types   .= 's';
        }
        // Financial year may be reassigned; validate the cycle dates still live
        // inside whichever financial year ends up selected.
        if (isset($data['financial_year_id'])) {
            $fyId   = (int) $data['financial_year_id'];
            $fyRow  = $this->selectFinancialYear($fyId);
            if (!$fyRow) {
                $this->error('Selected financial year does not exist.', 422);
            }
            // Re-derive the effective start/end (incoming overrides existing).
            $effStart = $data['start_date'] ?? $existingStart;
            $effEnd   = $data['end_date'] ?? $existingEnd;
            if ($effStart < $fyRow['start_date'] || $effEnd > $fyRow['end_date']) {
                $this->error(
                    "The cycle dates must fall within the {$fyRow['year_name']} financial year ("
                    . substr((string) $fyRow['start_date'], 0, 10) . ' to ' . substr((string) $fyRow['end_date'], 0, 10) . ').', 422
                );
            }
            $fields[] = 'financial_year_id = ?';
            $params[] = $fyId;
            $types   .= 'i';
        }

        if (empty($fields)) {
            $this->error('No valid fields to update.', 422);
        }

        $params[] = (int) $id;
        $types   .= 'i';

        $stmt = $this->db->prepare('UPDATE appraisal_cycles SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();

        \App\Services\AuditService::getInstance()->log(
            \App\Services\AuditService::MODULE_PERFORMANCE,
            \App\Services\AuditService::ACTION_UPDATE,
            'Updated appraisal cycle #' . (int) $id,
            ['target_type' => 'AppraisalCycle', 'target_id' => (int) $id]
        );

        $this->success(null, 'Appraisal cycle updated');
    }

    /**
     * DELETE /api/appraisal-cycles/{id} - Delete a cycle.
     *
     * Guardrails:
     *  - the cycle must still exist (404 otherwise);
     *  - the currently running active quarter cannot be deleted (409);
     *  - any workplan activity referencing the cycle in its cycle_ids list
     *    blocks deletion (409) - the cycle should be set to inactive instead.
     */
    public function destroyAction(int $id): void
    {
        $this->authenticate();
        if (!$this->canManage()) {
            $this->forbidden('Only HR managers and super admins can manage appraisal cycles.');
        }

        // Guardrail 1: the cycle must exist before anything else.
        $existing = $this->db->prepare(
            'SELECT id, name, start_date, end_date, status FROM appraisal_cycles WHERE id = ? LIMIT 1'
        );
        $existing->bind_param('i', $id);
        $existing->execute();
        $cycle = $existing->get_result()->fetch_assoc();
        $existing->close();

        if (!$cycle) {
            $this->notFound('Appraisal cycle not found.');
        }
        $cycleName = trim((string) ($cycle['name'] ?? 'Cycle #' . (int) $id));

        // Guardrail 2: never delete the quarter that is currently in progress.
        $today = date('Y-m-d');
        if ((string) $cycle['status'] === 'active'
            && (string) $cycle['start_date'] <= $today
            && $today <= (string) $cycle['end_date']) {
            $this->error(
                "\"{$cycleName}\" is the appraisal quarter currently in progress "
                . '(' . substr((string) $cycle['start_date'], 0, 10) . ' to '
                . substr((string) $cycle['end_date'], 0, 10) . '). '
                . 'Deleting it would remove the quarter heads are scheduling against right now. '
                . 'Wait until the period ends, or set it to "inactive" to hide it from the pickers.',
                409
            );
        }

        // Guardrail 3: refuse while any workplan activity still schedules
        // against this quarter (cycle_ids is a comma-separated list).
        $chk = $this->db->prepare(
            'SELECT COUNT(*) AS c FROM workplan_objectives WHERE FIND_IN_SET(?, cycle_ids)'
        );
        $chk->bind_param('i', $id);
        $chk->execute();
        $inUse = (int) ($chk->get_result()->fetch_assoc()['c'] ?? 0);
        $chk->close();

        if ($inUse > 0) {
            // Pull a few example activities so the message is actionable.
            $examples = [];
            $exSt = $this->db->prepare(
                'SELECT objective FROM workplan_objectives
                 WHERE FIND_IN_SET(?, cycle_ids) AND objective <> ""
                 ORDER BY updated_at DESC LIMIT 3'
            );
            $exSt->bind_param('i', $id);
            $exSt->execute();
            $exRes = $exSt->get_result();
            while ($r = $exRes->fetch_assoc()) {
                $examples[] = '"' . mb_substr(trim((string) $r['objective']), 0, 70) . '"';
            }
            $exSt->close();

            $sample = $examples ? ' - attached to e.g. ' . implode(', ', $examples) : '';
            $this->error(
                "\"{$cycleName}\" is used by {$inUse} workplan activit" . ($inUse === 1 ? 'y' : 'ies')
                . $sample
                . '. Deleting it would remove the quarter these activities are scheduled against. '
                . 'Set it to "inactive" instead, so completed activities keep their quarter history.',
                409
            );
        }

        $stmt = $this->db->prepare('DELETE FROM appraisal_cycles WHERE id = ?');
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            $this->error('Failed to delete the appraisal cycle.', 500);
        }

        \App\Services\AuditService::getInstance()->log(
            \App\Services\AuditService::MODULE_PERFORMANCE,
            \App\Services\AuditService::ACTION_DELETE,
            'Deleted appraisal cycle: ' . $cycleName,
            ['target_type' => 'AppraisalCycle', 'target_id' => (int) $id]
        );

        $this->success(null, 'Appraisal cycle deleted');
    }
}

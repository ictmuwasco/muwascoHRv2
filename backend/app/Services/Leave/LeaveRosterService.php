<?php

declare(strict_types=1);

namespace App\Services\Leave;

use App\Services\AuditService;
use App\Services\FinancialYearService;

/**
 * LeaveRosterService — the leave ROSTER domain (Phase 5 §7).
 *
 * The roster is a PLANNING mechanism, never a leave request:
 *   - scheduling an entry does NOT deduct leave balances,
 *   - does NOT create a leave application,
 *   - does NOT mark anyone absent or on leave.
 * It exists so HR can plan annual-leave coverage across the July→June
 * financial year. Balances are only ever touched by LeaveApprovalService
 * through actual applications.
 *
 * Enforced business rules (new in Phase 5):
 *   - planned months MUST be one of the twelve financial-year months
 *     (July…June) — previously any free-text string was accepted;
 *   - scheduled_year is derived from the financial year's start date and
 *     the July→June mapping (Jan..Jun belong to start_year + 1);
 *   - the target financial year must exist;
 *   - every write is audited (WHO/WHAT/WHEN via AuditService).
 */
class LeaveRosterService
{
    /** The twelve financial-year months in order, July → June. */
    public const FY_MONTHS = [
        'July', 'August', 'September', 'October', 'November', 'December',
        'January', 'February', 'March', 'April', 'May', 'June',
    ];

    /** Months that fall into the calendar year AFTER the FY start (Jan–Jun). */
    private const SECOND_HALF_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June'];

    private \mysqli $db;
    private FinancialYearService $financialYearService;

    public function __construct()
    {
        $this->db = \App\Helpers\Database::getInstance()->getConnection();
        $this->financialYearService = new FinancialYearService();
    }

    /**
     * Which roster month names are valid?
     *
     * @return list<string>
     */
    public function validMonths(): array
    {
        return self::FY_MONTHS;
    }

    /**
     * Validate a planned month against the July→June calendar.
     *
     * @throws InvalidRosterMonthException
     */
    public function assertValidMonth(string $month): void
    {
        if (!in_array($month, self::FY_MONTHS, true)) {
            throw new InvalidRosterMonthException(
                'Invalid scheduled month. Choose one of: ' . implode(', ', self::FY_MONTHS) . '.',
                ['month' => $month, 'allowed' => self::FY_MONTHS]
            );
        }
    }

    /**
     * Map a planned month to its calendar year within the financial year
     * (July…December → FY start year; January…June → start year + 1).
     */
    public function yearForMonth(string $fyStartDate, string $month): int
    {
        $year = (int) date('Y', strtotime($fyStartDate));
        if (in_array($month, self::SECOND_HALF_MONTHS, true)) {
            $year++;
        }
        return $year;
    }

    /**
     * Resolve the financial year for a roster operation. An explicit id is
     * validated; otherwise the centralized current-FY resolver decides.
     *
     * @return array{start_date:string}|null null = no financial years configured
     */
    public function resolveFinancialYear(?int $requestedId): ?array
    {
        $fyId = $requestedId ?: $this->financialYearService->resolveCurrentFinancialYearId();
        if ($fyId <= 0) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT id, start_date, end_date, year_name FROM financial_years WHERE id = ?");
        $stmt->bind_param('i', $fyId);
        $stmt->execute();
        $fy = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $fy ?: null;
    }

    /**
     * Create or update (upsert) an employee's planned leave month for a
     * financial year. The roster holds ONE planned month per employee per
     * financial year (matching the legacy upsert contract).
     *
     * @return array{id:int, scheduled_year:int}
     * @throws InvalidRosterMonthException
     */
    public function schedule(int $employeeId, int $fyId, string $month, string $notes = ''): array
    {
        $this->assertValidMonth($month);

        $fy = $this->resolveFinancialYear($fyId);
        if ($fy === null) {
            throw new \InvalidArgumentException('Invalid financial year');
        }

        $year = $this->yearForMonth($fy['start_date'], $month);

        // Upsert: one planned month per employee per financial year.
        $stmt = $this->db->prepare("SELECT id FROM leave_roster WHERE employee_id = ? AND financial_year_id = ?");
        $stmt->bind_param('ii', $employeeId, $fy['id']);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            $stmt = $this->db->prepare("UPDATE leave_roster SET scheduled_month = ?, scheduled_year = ?, notes = ? WHERE id = ?");
            $stmt->bind_param('sisi', $month, $year, $notes, $existing['id']);
            $stmt->execute();
            $rosterId = (int) $existing['id'];
        } else {
            $stmt = $this->db->prepare("INSERT INTO leave_roster (employee_id, financial_year_id, scheduled_month, scheduled_year, notes) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('iisis', $employeeId, $fy['id'], $month, $year, $notes);
            $stmt->execute();
            $rosterId = (int) $this->db->insert_id;
        }

        $this->audit('roster_scheduled', $rosterId, [
            'employee_id' => $employeeId,
            'financial_year_id' => (int) $fy['id'],
            'scheduled_month' => $month,
            'scheduled_year' => $year,
            'notes' => $notes !== '' ? $notes : null,
            'operation' => $existing ? 'update' : 'create',
        ]);

        return ['id' => $rosterId, 'scheduled_year' => $year];
    }

    /**
     * Update an existing roster entry's planned month/notes.
     *
     * @return array{id:int, scheduled_year:int}
     * @throws InvalidRosterMonthException
     */
    public function update(int $rosterId, string $month, string $notes = ''): array
    {
        $this->assertValidMonth($month);

        // Existence pre-check: mysqli reports *changed* rows by default, so
        // saving identical values must not be misread as "entry not found".
        $chk = $this->db->prepare("SELECT lr.id, lr.employee_id, lr.financial_year_id, fy.start_date FROM leave_roster lr JOIN financial_years fy ON fy.id = lr.financial_year_id WHERE lr.id = ?");
        $chk->bind_param('i', $rosterId);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$existing) {
            throw new \InvalidArgumentException('Roster entry not found');
        }

        $year = $this->yearForMonth($existing['start_date'], $month);

        $stmt = $this->db->prepare("UPDATE leave_roster SET scheduled_month = ?, scheduled_year = ?, notes = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('sisi', $month, $year, $notes, $rosterId);
        $stmt->execute();
        $stmt->close();

        $this->audit('roster_updated', $rosterId, [
            'employee_id' => (int) $existing['employee_id'],
            'financial_year_id' => (int) $existing['financial_year_id'],
            'scheduled_month' => $month,
            'scheduled_year' => $year,
            'notes' => $notes !== '' ? $notes : null,
        ]);

        return ['id' => $rosterId, 'scheduled_year' => $year];
    }

    /**
     * Remove a planned roster entry. Planning-only — no balance/app status
     * side effects exist or are created here.
     */
    public function delete(int $rosterId): void
    {
        $chk = $this->db->prepare("SELECT employee_id, financial_year_id, scheduled_month FROM leave_roster WHERE id = ?");
        $chk->bind_param('i', $rosterId);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        $chk->close();
        if (!$existing) {
            throw new \InvalidArgumentException('Roster entry not found');
        }

        $stmt = $this->db->prepare("DELETE FROM leave_roster WHERE id = ?");
        $stmt->bind_param('i', $rosterId);
        $stmt->execute();
        $stmt->close();

        $this->audit('roster_removed', $rosterId, [
            'employee_id' => (int) $existing['employee_id'],
            'financial_year_id' => (int) $existing['financial_year_id'],
            'scheduled_month' => $existing['scheduled_month'],
        ]);
    }

    /**
     * Roster write audit entry (planning events, MODULE_LEAVE).
     */
    private function audit(string $action, int $rosterId, array $metadata): void
    {
        AuditService::getInstance()->log(
            AuditService::MODULE_LEAVE,
            AuditService::ACTION_UPDATE,
            'Leave roster ' . str_replace('_', ' ', $action) . ': ' . $rosterId,
            [
                'status' => AuditService::STATUS_SUCCESS,
                'target_type' => 'LeaveRoster',
                'target_id' => $rosterId,
                'metadata' => $metadata,
            ]
        );
    }
}

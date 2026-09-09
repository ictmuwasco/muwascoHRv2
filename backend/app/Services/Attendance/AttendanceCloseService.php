<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Services\AuditService;

/**
 * AttendanceCloseService — the SINGLE implementation of the missed
 * clock-out business rule (Phase 5 §12).
 *
 * Closes attendance sessions left open from a previous day:
 *   clock_out        = end of that employee's attendance day (Africa/Nairobi)
 *   status           = 'auto_clocked_out'
 *   auto_clocked_out = 1
 *
 * Consumers:
 *   - backend/cron/auto_clockout.php          (scheduled nightly safety net)
 *   - AttendanceController::autoClockOutAction (manual / ops trigger)
 *   - AttendanceController::dashboardAction    (per-employee lazy reconcile)
 *
 * Previously this logic was duplicated across five locations (cron,
 * AttendanceController ×2, DashboardController, Models\Attendance) with
 * divergent side effects — including a mutating GET dashboard endpoint.
 * All of them now delegate here.
 *
 * Idempotency: the WHERE clause (clock_out IS NULL AND DATE(clock_in) < today)
 * makes repeated runs no-ops. Safe on multi-server deployments.
 */
class AttendanceCloseService
{
    /**
     * Batch-close every previous-day open session (global sweep).
     *
     * @param string|null $today Override "today" (Y-m-d) for tests/replay.
     * @return array{closed:int, employee_ids:list<int>}
     */
    public function closeStaleOpenSessions(?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');

        $open = $this->openSessionsBefore($today);
        if ($open === []) {
            return ['closed' => 0, 'employee_ids' => []];
        }

        $employeeIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['employee_id'],
            $open
        )));

        $closed = $this->closeBatch($today);

        \logger()->info('Auto clock-out completed', [
            'closed' => $closed,
            'employee_ids' => $employeeIds,
        ]);

        // Audit the scheduled close (best-effort; never blocks the sweep).
        $this->auditClosed($closed, $employeeIds);

        return ['closed' => $closed, 'employee_ids' => $employeeIds];
    }

    /**
     * Audit seam for the batch sweep (overridable in tests).
     */
    protected function auditClosed(int $closed, array $employeeIds): void
    {
        AuditService::getInstance()->log(
            AuditService::MODULE_ATTENDANCE,
            AuditService::ACTION_STATUS_CHANGE,
            'Auto clock-out: closed ' . $closed . ' stale open session(s) from previous days',
            [
                'status' => AuditService::STATUS_SUCCESS,
                'target_type' => 'Attendance',
                'metadata' => [
                    'closed' => $closed,
                    'employee_ids' => $employeeIds,
                    'source' => 'attendance_close_service',
                ],
            ]
        );
    }

    /**
     * Lazily close THIS employee's previous-day open session. Runs on every
     * attendance read/write for the employee (the safety net that covers
     * browser-closed / cron-missed cases). Deliberately NOT audited per call
     * to avoid flooding the audit trail — the batch sweep is audited instead.
     */
    public function reconcileEmployee(int $employeeDbId, ?string $today = null): bool
    {
        return $this->closeEmployeeBatch($employeeDbId, $today ?? date('Y-m-d')) > 0;
    }

    // ------------------------------------------------------------------
    // Data-access seams (protected so tests can stub without a database)
    // ------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    protected function openSessionsBefore(string $today): array
    {
        return \db()->fetchAll(
            "SELECT id, employee_id, clock_in
             FROM attendance
             WHERE clock_out IS NULL AND DATE(clock_in) < ?",
            's',
            [$today]
        );
    }

    protected function closeBatch(string $today): int
    {
        $stmt = \db()->query(
            "UPDATE attendance
               SET clock_out = DATE_FORMAT(clock_in, '%Y-%m-%d 23:59:59'),
                   status = 'auto_clocked_out',
                   auto_clocked_out = 1,
                   updated_at = NOW()
             WHERE clock_out IS NULL AND DATE(clock_in) < ?",
            's',
            [$today]
        );
        $affected = $stmt->affected_rows;
        $stmt->close();
        return (int) $affected;
    }

    protected function closeEmployeeBatch(int $employeeDbId, string $today): int
    {
        $stmt = \db()->query(
            "UPDATE attendance
               SET clock_out = DATE_FORMAT(clock_in, '%Y-%m-%d 23:59:59'),
                   status = 'auto_clocked_out',
                   auto_clocked_out = 1,
                   updated_at = NOW()
             WHERE employee_id = ? AND clock_out IS NULL AND DATE(clock_in) < ?",
            'is',
            [$employeeDbId, $today]
        );
        $affected = $stmt->affected_rows;
        $stmt->close();
        return (int) $affected;
    }
}

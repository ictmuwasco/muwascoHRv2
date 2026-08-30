<?php

declare(strict_types=1);

namespace App\Services\AttendanceReport;

use App\Helpers\Database;
use App\Services\AttendanceDashboardService;
use App\Services\CalendarContextService;

/**
 * AttendanceReportService
 *
 * Top-level orchestrator for the Attendance Analytics & Reporting module.
 *
 * It delegates to specialised sub-services for each analytics slice, exactly
 * mirroring the proven LeaveReportService pattern. Crucially it REUSES the
 * existing AttendanceDashboardService for every business rule (present /
 * absent / on-leave / late / auto-clock-out / missing-clock-out, weekends,
 * holidays, approved leave) rather than re-implementing them — so the new
 * report layer can never disagree with the live attendance dashboard.
 *
 * All queries run server-side in MySQL aggregation; the frontend only ever
 * receives aggregated numbers, never raw attendance rows.
 */
final class AttendanceReportService
{
    private AttendanceReportQueryService $query;
    private AttendanceReportAnalyticsService $analytics;
    private AttendanceReportComplianceService $complianceService;
    private AttendanceReportRecordsService $recordsService;
    private AttendanceReportExportService $exportService;
    private Database $db;

    public function __construct(?AttendanceReportQueryService $query = null)
    {
        $this->db       = Database::getInstance();
        $this->query    = $query ?? new AttendanceReportQueryService();
        $this->analytics = new AttendanceReportAnalyticsService(
            $this->query,
            new CalendarContextService(),
            new AttendanceDashboardService()
        );
        // All slices share ONE analytics instance (and therefore ONE query
        // service) so every endpoint agrees on the same calendar + filters.
        $this->complianceService = new AttendanceReportComplianceService($this->analytics);
        $this->recordsService    = new AttendanceReportRecordsService($this->query, $this->analytics);
        $this->exportService     = new AttendanceReportExportService($this->recordsService);
    }

    public function query(): AttendanceReportQueryService
    {
        return $this->query;
    }

    public function analytics(): AttendanceReportAnalyticsService
    {
        return $this->analytics;
    }

    public function compliance(): AttendanceReportComplianceService
    {
        return $this->complianceService;
    }

    public function records(): AttendanceReportRecordsService
    {
        return $this->recordsService;
    }

    public function export(): AttendanceReportExportService
    {
        return $this->exportService;
    }

    /**
     * Ranked lists for the filter dropdowns so the UI never hard-codes data:
     * departments, offices, employee_types, attendance statuses.
     */
    public function options(array $scope): array
    {
        $conn = $this->db->getConnection();

        $departments = [];
        $res = $conn->query('SELECT id, name FROM departments ORDER BY name ASC');
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $departments[] = ['id' => (int) $row['id'], 'name' => $row['name']];
            }
        }

        $offices = [];
        $res = $conn->query('SELECT id, name FROM offices ORDER BY name ASC');
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $offices[] = ['id' => (int) $row['id'], 'name' => $row['name']];
            }
        }

        $employeeTypes = [];
        $res = $conn->query("SELECT DISTINCT employee_type FROM employees WHERE employee_type IS NOT NULL AND employee_type != '' ORDER BY employee_type ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $employeeTypes[] = ['id' => $row['employee_type'], 'name' => $row['employee_type']];
            }
        }

        // Scoped (non-HR) users are pinned to their own department dropdown.
        $deptFilter = isset($scope['department_id']) && $scope['department_id'] !== null
            ? (int) $scope['department_id'] : null;
        if ($deptFilter !== null && !(($scope['is_hr'] ?? false) || ($scope['is_super_admin'] ?? false))) {
            $departments = array_values(array_filter($departments, fn ($d) => $d['id'] === $deptFilter));
        }

        return [
            'departments'     => $departments,
            'offices'         => $offices,
            'employee_types'  => $employeeTypes,
            // Canonical record-status lens keys (recordStatusCondition()
            // understands these plus their long aliases). absent / on_leave
            // are calendar-derived statuses handled by the records service.
            'statuses'        => [
                'present', 'late', 'missing', 'auto', 'absent', 'on_leave',
            ],
        ];
    }
}

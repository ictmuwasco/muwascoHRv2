<?php

declare(strict_types=1);

namespace App\Services\AttendanceReport;

/**
 * AttendanceReportExportService
 *
 * Builds the export payload for the Attendance Analytics & Reporting CSV.
 * The rows come from the SAME aggregateEmployees() pipeline the on-screen
 * employee table uses, so the downloaded report always reflects exactly the
 * filters applied to the report (period, department, office, employee,
 * employee type, record-status lens, search is applied by the controller's
 * filter normalisation and therefore respected too).
 *
 * The CSV itself is streamed by AttendanceReportController::exportAction(),
 * mirroring the proven LeaveReportController::exportAction() layout:
 * metadata block -> summary metrics -> detailed records.
 */
final class AttendanceReportExportService
{
    private AttendanceReportRecordsService $records;

    public function __construct(AttendanceReportRecordsService $records)
    {
        $this->records = $records;
    }

    /**
     * @param array $filters normalised filters
     * @param array $scope   OrgScope::current() result
     *
     * @return array{0:string[],1:array<int,array<int,string>>} [headers, rows]
     */
    public function employeeRows(array $filters, array $scope): array
    {
        $rows    = $this->records->aggregateEmployees($filters, $scope);
        $headers = [
            'Employee Number',
            'Employee Name',
            'Department',
            'Office',
            'Expected Days',
            'Days Present',
            'Days Absent',
            'Days On Leave',
            'Late Days',
            'Auto Clock-Outs',
            'Missing Clock-Outs',
            'Total Hours',
            'Avg Hours/Day',
            'Attendance Rate (%)',
        ];

        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                (string) ($r['emp_no'] ?? ''),
                (string) ($r['name'] ?? ''),
                (string) ($r['department'] ?? ''),
                (string) ($r['office'] ?? ''),
                (string) ($r['expected_days'] ?? 0),
                (string) ($r['days_present'] ?? 0),
                (string) ($r['absent_days'] ?? 0),
                (string) ($r['leave_days'] ?? 0),
                (string) ($r['late_days'] ?? 0),
                (string) ($r['auto_days'] ?? 0),
                (string) ($r['missing_out'] ?? 0),
                (string) ($r['total_hours'] ?? 0),
                (string) ($r['avg_hours'] ?? 0),
                $r['attendance_rate'] === null ? '' : (string) $r['attendance_rate'],
            ];
        }

        return [$headers, $data];
    }
}

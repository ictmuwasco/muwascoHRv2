<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;

/**
 * LeaveReportExportService
 *
 * Streams the filtered leave report as a CSV file. The payload includes a
 * report metadata block (period, generated-on, applied filters), a summary
 * metrics block, then the detailed filtered records. It reuses the SAME
 * filter pipeline as the on-screen report so the export always matches.
 */
final class LeaveReportExportService
{
    public function __construct(
        private LeaveReportQueryService $queryService
    ) {
    }

    /**
     * @return array{string, string} [headers, rows] as associative arrays.
     */
    public function records(array $filters, array $scope): array
    {
        [$where, $types, $params] = $this->queryService->buildWhere($filters, $scope);
        $types = $types ?: null;

        $sql = "
            SELECT e.employee_id                            AS employee_number,
                   CONCAT(COALESCE(e.first_name,''), ' ', COALESCE(e.last_name,'')) AS employee_name,
                   COALESCE(d.name, 'Unassigned')           AS department,
                   lt.name                                  AS leave_type,
                   la.start_date                            AS start_date,
                   la.end_date                              AS end_date,
                   la.days_requested                        AS days,
                   la.status                                AS status,
                   la.applied_at                            AS applied_at,
                   la.approved_at                           AS approved_at,
                   la.rejection_reason                      AS rejection_reason
            FROM leave_applications la
            JOIN employees e ON la.employee_id = e.id
            JOIN leave_types lt ON la.leave_type_id = lt.id
            LEFT JOIN departments d ON e.department_id = d.id
            {$where}
            ORDER BY la.applied_at DESC
        ";

        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return [[], []];
        }
        if ($types) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        $headers = ['Employee Number', 'Employee Name', 'Department', 'Leave Type',
            'Start Date', 'End Date', 'Days', 'Status', 'Applied At', 'Approved At'];

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                $row['employee_number'] ?? '',
                trim($row['employee_name'] ?? ''),
                $row['department'] ?? '',
                $row['leave_type'] ?? '',
                $row['start_date'] ?? '',
                $row['end_date'] ?? '',
                $row['days'] ?? '',
                $this->formatStatus((string) ($row['status'] ?? '')),
                $row['applied_at'] ?? '',
                $row['approved_at'] ?? '',
            ];
        }

        return [$headers, $data];
    }

    private function formatStatus(string $status): string
    {
        $labels = [
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'cancelled' => 'Cancelled',
            'invalidated' => 'Invalidated',
            'pending' => 'Pending',
            'pending_subsection_head' => 'Pending Subsection Head',
            'pending_section_head' => 'Pending Section Head',
            'pending_dept_head' => 'Pending Department Head',
            'pending_managing_director' => 'Pending Managing Director',
            'pending_bod_chair' => 'Pending BOD Chair',
            'pending_manager' => 'Pending Manager',
        ];
        return $labels[$status] ?? ucwords(str_replace('_', ' ', $status));
    }
}
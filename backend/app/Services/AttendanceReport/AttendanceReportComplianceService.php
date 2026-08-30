<?php

declare(strict_types=1);

namespace App\Services\AttendanceReport;

/**
 * AttendanceReportComplianceService
 *
 * Attendance-compliance analytics for the reporting module:
 *
 *      Compliance Rate = Present Days / Expected Working Days x 100
 *
 * Expected working days come from the shared analytics calendar math
 * (weekends and public holidays excluded, hire-date aware, active employees
 * only, approved leave carved out) - this service deliberately owns NO
 * business rules of its own so the compliance figures can never disagree
 * with the KPI summary cards.
 *
 * The per-bucket series reuses trends(): expected per bucket is derived as
 * present + absent + on-leave, which is exactly how the analytics service
 * distributes expected employees across the three outcomes.
 */
final class AttendanceReportComplianceService
{
    private AttendanceReportAnalyticsService $analytics;

    public function __construct(AttendanceReportAnalyticsService $analytics)
    {
        $this->analytics = $analytics;
    }

    /**
     * Headline compliance + per-bucket series + the lowest-attendance
     * buckets that may deserve a follow-up.
     *
     * @return array{
     *   start_date:string,end_date:string,grouping:string,
     *   expected_working_days:int,present_days:int,leave_days:int,absent_days:int,
     *   compliance_rate:?float,
     *   series:array<int,array{label:string,expected:int,present:int,on_leave:int,absent:int,rate:?float}>,
     *   lowest:array<int,array{label:string,rate:float}>
     * }
     */
    public function overview(array $filters, array $scope): array
    {
        $summary = $this->analytics->summary($filters, $scope);
        $trend   = $this->analytics->trends($filters, $scope);

        $series = [];
        foreach (($trend['points'] ?? []) as $point) {
            $present  = (int) ($point['present'] ?? 0);
            $absent   = (int) ($point['absent'] ?? 0);
            $onLeave  = (int) ($point['on_leave'] ?? 0);
            $expected = $present + $absent + $onLeave;

            $series[] = [
                'label'    => (string) ($point['label'] ?? ''),
                'expected' => $expected,
                'present'  => $present,
                'on_leave' => $onLeave,
                'absent'   => $absent,
                'rate'     => $expected > 0 ? round($present / $expected * 100, 1) : null,
            ];
        }

        // Lowest attendance buckets that still had expected employees - an
        // operational follow-up signal, never a performance verdict.
        $lowest = array_values(array_filter(
            $series,
            static fn (array $p): bool => $p['rate'] !== null && $p['expected'] > 0
        ));
        usort($lowest, static fn (array $a, array $b): int => [$a['rate'], $a['label']] <=> [$b['rate'], $b['label']]);
        $lowest = array_map(
            static fn (array $p): array => ['label' => $p['label'], 'rate' => (float) $p['rate']],
            array_slice($lowest, 0, 5)
        );

        return [
            'start_date'            => (string) $summary['start_date'],
            'end_date'              => (string) $summary['end_date'],
            'grouping'              => (string) $summary['grouping'],
            'expected_working_days' => (int) $summary['expected_working_days'],
            'present_days'          => (int) $summary['present_days'],
            'leave_days'            => (int) $summary['leave_days'],
            'absent_days'           => (int) $summary['absent_days'],
            'compliance_rate'       => $summary['compliance_rate'] === null
                ? null
                : (float) $summary['compliance_rate'],
            'series'                => $series,
            'lowest'                => $lowest,
        ];
    }
}

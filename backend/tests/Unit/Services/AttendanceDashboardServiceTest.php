<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AttendanceDashboardService;
use PHPUnit\Framework\TestCase;

final class AttendanceDashboardServiceTest extends TestCase
{
    private AttendanceDashboardService $service;

    protected function setUp(): void
    {
        $this->service = new AttendanceDashboardService();
    }

    public function testClockedInEmployeeOnApprovedLeaveIsPresent(): void
    {
        self::assertSame(
            AttendanceDashboardService::STATUS_PRESENT,
            $this->service->resolveEmployeeStatus([
                'leave_type' => 'Annual Leave',
                'record' => [
                    'status' => 'present',
                    'is_late' => false,
                    'clock_out' => null,
                    'auto_clocked_out' => false,
                ],
                'is_today' => true,
            ])
        );
    }

    public function testLateEmployeeOnApprovedLeaveRemainsLate(): void
    {
        self::assertSame(
            AttendanceDashboardService::STATUS_LATE,
            $this->service->resolveEmployeeStatus([
                'leave_type' => 'Annual Leave',
                'record' => [
                    'status' => 'late',
                    'is_late' => true,
                    'clock_out' => '2025-11-12 17:00:00',
                    'auto_clocked_out' => false,
                ],
                'is_today' => true,
            ])
        );
    }

    public function testEmployeeOnApprovedLeaveWithoutAttendanceRemainsOnLeave(): void
    {
        self::assertSame(
            AttendanceDashboardService::STATUS_ON_LEAVE,
            $this->service->resolveEmployeeStatus([
                'leave_type' => 'Annual Leave',
                'record' => null,
                'is_today' => true,
            ])
        );
    }
}

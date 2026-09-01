<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Attendance;

use PHPUnit\Framework\TestCase;
use App\Services\Attendance\AttendanceCloseService;

/**
 * Unit tests for the single missed-clock-out implementation
 * (Services\Attendance\AttendanceCloseService). Database access is stubbed
 * through the protected seams; no live schema is required.
 *
 * Verifies the Phase 5 §12/§21 idempotency guarantees:
 *  - no write happens when there is nothing to close
 *  - the sweep closes exactly once per stale row
 *  - repeated runs are safe no-ops
 *
 * @covers \App\Services\Attendance\AttendanceCloseService
 */
final class AttendanceCloseServiceTest extends TestCase
{
    /** @var array<string,array<string,mixed>> */
    private array $stubState;

    private object $service;

    protected function setUp(): void
    {
        $this->stubState = [
            'open' => [],
            'calls' => [],
            'closed' => 0,
        ];

        $this->service = new class($this->stubState) extends AttendanceCloseService {
            private array $state;

            public function __construct(array &$state)
            {
                $this->state = &$state;
            }

            protected function openSessionsBefore(string $today): array
            {
                $this->state['calls'][] = 'select';
                return $this->state['open'];
            }

            protected function closeBatch(string $today): int
            {
                $this->state['calls'][] = 'batch';
                $closed = count($this->state['open']);
                $this->state['open'] = []; // the UPDATE consumes the matched rows
                return $closed;
            }

            protected function closeEmployeeBatch(int $employeeDbId, string $today): int
            {
                $this->state['calls'][] = 'employee';
                return count($this->state['open']) > 0 ? 1 : 0;
            }

            protected function auditClosed(int $closed, array $employeeIds): void
            {
                $this->state['calls'][] = 'audit';
            }
        };
    }

    public function testNoWriteWhenNothingToClose(): void
    {
        $result = $this->service->closeStaleOpenSessions();

        $this->assertSame(0, $result['closed']);
        $this->assertSame([], $result['employee_ids']);
        $this->assertSame(['select'], $this->stubState['calls'], 'Batch UPDATE must not run when no rows are open');
    }

    public function testBatchClosesAllStaleSessionsOnce(): void
    {
        $this->stubState['open'] = [
            ['id' => 1, 'employee_id' => 7, 'clock_in' => '2026-08-31 08:05:00'],
            ['id' => 2, 'employee_id' => 9, 'clock_in' => '2026-08-31 08:11:00'],
            ['id' => 3, 'employee_id' => 7, 'clock_in' => '2026-08-30 08:03:00'],
        ];

        $result = $this->service->closeStaleOpenSessions();

        $this->assertSame(3, $result['closed']);
        $this->assertSame([7, 9], $result['employee_ids'], 'Employee ids must be unique');
        $this->assertSame(['select', 'batch', 'audit'], $this->stubState['calls']);
    }

    public function testRepeatedRunsAreIdempotentNoOps(): void
    {
        $this->stubState['open'] = [
            ['id' => 1, 'employee_id' => 7, 'clock_in' => '2026-08-31 08:05:00'],
        ];

        $first = $this->service->closeStaleOpenSessions();
        $second = $this->service->closeStaleOpenSessions();

        $this->assertSame(1, $first['closed']);
        $this->assertSame(0, $second['closed'], 'Second run must find nothing to close');
        $this->assertSame(
            ['select', 'batch', 'audit', 'select'],
            $this->stubState['calls'],
            'Exactly one batch UPDATE must have run across both invocations'
        );
    }

    public function testReconcileEmployeeOnlyTouchesThatEmployee(): void
    {
        $this->stubState['open'] = [
            ['id' => 1, 'employee_id' => 42, 'clock_in' => '2026-08-31 08:05:00'],
        ];
        $this->assertTrue($this->service->reconcileEmployee(42));

        $this->stubState['open'] = [];
        $this->assertFalse($this->service->reconcileEmployee(42));
        $this->assertContains('employee', $this->stubState['calls']);
        $this->assertNotContains('batch', $this->stubState['calls'], 'Per-employee reconcile must not sweep globally');
    }
}

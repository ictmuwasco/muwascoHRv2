<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Leave;

use PHPUnit\Framework\TestCase;
use App\Services\Leave\InvalidRosterMonthException;
use App\Services\Leave\LeaveRosterService;

/**
 * Unit tests for the leave ROSTER planning rules (Phase 5 §7).
 * Covers the pure July→June calendar logic — no database required.
 *
 * @covers \App\Services\Leave\LeaveRosterService
 */
final class LeaveRosterRulesTest extends TestCase
{
    private LeaveRosterService $service;

    protected function setUp(): void
    {
        $this->service = new LeaveRosterService();
    }

    public function testTwelveFinancialYearMonthsInOrder(): void
    {
        $this->assertSame(
            ['July', 'August', 'September', 'October', 'November', 'December',
             'January', 'February', 'March', 'April', 'May', 'June'],
            $this->service->validMonths()
        );
    }

    public function testValidMonthsAreAccepted(): void
    {
        foreach ($this->service->validMonths() as $month) {
            $this->service->assertValidMonth($month);
        }
        $this->addToAssertionCount(1);
    }

    public function testInvalidMonthIsRejected(): void
    {
        try {
            $this->service->assertValidMonth('Sept');
            $this->fail('Expected InvalidRosterMonthException');
        } catch (InvalidRosterMonthException $e) {
            $this->assertStringContainsString('Invalid scheduled month', $e->getMessage());
            $this->assertContains('July', $e->context()['allowed']);
        }
    }

    public function testCaseSensitiveMonthIsRejected(): void
    {
        $this->expectException(InvalidRosterMonthException::class);
        $this->service->assertValidMonth('july');
    }

    public function testJulyToDecemberMapToFyStartYear(): void
    {
        $fyStart = '2026-07-01';
        foreach (['July' => 2026, 'August' => 2026, 'December' => 2026] as $month => $year) {
            $this->assertSame($year, $this->service->yearForMonth($fyStart, $month), $month);
        }
    }

    public function testJanuaryToJuneMapToFyStartYearPlusOne(): void
    {
        $fyStart = '2026-07-01';
        foreach (['January' => 2027, 'March' => 2027, 'June' => 2027] as $month => $year) {
            $this->assertSame($year, $this->service->yearForMonth($fyStart, $month), $month);
        }
    }
}

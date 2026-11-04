<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CalendarContextService;
use PHPUnit\Framework\TestCase;

final class CalendarContextServiceTest extends TestCase
{
    public function testLeaveEndDateIsTheReturnToWorkDate(): void
    {
        $startDate = '2024-10-21';
        $endDate = '2024-11-11';

        self::assertFalse(CalendarContextService::approvedLeaveCoversDate($startDate, $endDate, '2024-10-20'));
        self::assertTrue(CalendarContextService::approvedLeaveCoversDate($startDate, $endDate, '2024-10-21'));
        self::assertTrue(CalendarContextService::approvedLeaveCoversDate($startDate, $endDate, '2024-11-10'));
        self::assertFalse(CalendarContextService::approvedLeaveCoversDate($startDate, $endDate, '2024-11-11'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Meetings;

use PHPUnit\Framework\TestCase;
use App\Services\MeetingRules;

/**
 * Unit tests for the meeting lifecycle rules (Phase 5 §14).
 * Pure logic — no database required.
 *
 * @covers \App\Services\MeetingRules
 */
final class MeetingRulesTest extends TestCase
{
    public function testOnlyLifecycleStatusesAreValid(): void
    {
        foreach (['scheduled', 'ongoing', 'completed', 'cancelled'] as $status) {
            $this->assertTrue(MeetingRules::isValidStatus($status));
        }
        foreach (['draft', 'postponed', '', 'SCHEDULED'] as $status) {
            $this->assertFalse(MeetingRules::isValidStatus($status), "'{$status}' must be invalid");
        }
    }

    public function testRsvpOpenOnlyWhileScheduledOrOngoing(): void
    {
        $this->assertTrue(MeetingRules::canRsvp('scheduled'));
        $this->assertTrue(MeetingRules::canRsvp('ongoing'));

        // completed/cancelled close responses forever (§14)
        $this->assertFalse(MeetingRules::canRsvp('completed'));
        $this->assertFalse(MeetingRules::canRsvp('cancelled'));
    }

    public function testAttendanceStatusWhitelistMatchesSchemaEnum(): void
    {
        foreach (['present', 'absent', 'excused', 'not_marked'] as $status) {
            $this->assertTrue(MeetingRules::isValidAttendanceStatus($status));
        }
        $this->assertFalse(MeetingRules::isValidAttendanceStatus('late'));
        $this->assertFalse(MeetingRules::isValidAttendanceStatus(''));
    }

    public function testResponseStatusWhitelistMatchesSchemaEnum(): void
    {
        foreach (['pending', 'accepted', 'declined', 'tentative'] as $status) {
            $this->assertTrue(MeetingRules::isValidResponseStatus($status));
        }
        $this->assertFalse(MeetingRules::isValidResponseStatus('maybe'));
    }
}

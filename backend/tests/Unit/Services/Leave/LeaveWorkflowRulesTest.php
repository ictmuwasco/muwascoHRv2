<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Leave;

use PHPUnit\Framework\TestCase;
use App\Services\Leave\LeaveWorkflowRules;
use App\Services\Leave\InvalidLeaveTransitionException;

/**
 * Unit tests for the authoritative leave state machine (Phase 5 §6).
 * Pure logic — no database required.
 *
 * @covers \App\Services\Leave\LeaveWorkflowRules
 */
final class LeaveWorkflowRulesTest extends TestCase
{
    public function testAllPendingStagesAreRecognised(): void
    {
        foreach (LeaveWorkflowRules::PENDING_STATUSES as $status) {
            $this->assertTrue(
                LeaveWorkflowRules::isPending($status),
                "{$status} must be pending"
            );
            $this->assertTrue(LeaveWorkflowRules::canDecide($status));
        }
    }

    public function testTerminalStatesAreNotDecidable(): void
    {
        foreach (['approved', 'rejected', 'cancelled', 'invalidated'] as $status) {
            $this->assertFalse(LeaveWorkflowRules::canDecide($status), "{$status} must not be decidable");
        }
    }

    public function testInvalidTransitionsMapToRuleViolations(): void
    {
        // rejected → approved / approved → pending / cancelled → approved are
        // forbidden forever (Phase 5 §6).
        foreach (['approved', 'rejected', 'cancelled', 'invalidated'] as $terminal) {
            $this->assertFalse(
                LeaveWorkflowRules::canDecide($terminal),
                "Re-deciding a {$terminal} application is forbidden"
            );
        }
    }

    public function testInvalidateAllowedFromPendingAndApprovedOnly(): void
    {
        foreach (LeaveWorkflowRules::PENDING_STATUSES as $status) {
            $this->assertTrue(LeaveWorkflowRules::canInvalidate($status));
        }
        $this->assertTrue(
            LeaveWorkflowRules::canInvalidate('approved'),
            'Approved → invalidated is the sanctioned reversal path (balances restored)'
        );

        $this->assertFalse(LeaveWorkflowRules::canInvalidate('rejected'));
        $this->assertFalse(LeaveWorkflowRules::canInvalidate('cancelled'));
        $this->assertFalse(LeaveWorkflowRules::canInvalidate('invalidated'));
    }

    public function testViolationMessagesAreActionAndStatusSpecific(): void
    {
        $this->assertSame(
            'This application is already approved and cannot be approved.',
            LeaveWorkflowRules::transitionViolationMessage('approve', 'approved')
        );
        $this->assertSame(
            'This application is already approved and cannot be rejected.',
            LeaveWorkflowRules::transitionViolationMessage('reject', 'approved')
        );
        $this->assertSame(
            'This application is already cancelled and cannot be invalidated.',
            LeaveWorkflowRules::transitionViolationMessage('invalidate', 'cancelled')
        );
    }

    public function testExceptionCarriesContext(): void
    {
        try {
            throw new InvalidLeaveTransitionException('boom', ['current_status' => 'approved']);
        } catch (InvalidLeaveTransitionException $e) {
            $this->assertInstanceOf(\App\Services\Leave\LeaveException::class, $e);
            $this->assertSame(['current_status' => 'approved'], $e->context());
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Leave;

/**
 * LeaveWorkflowRules — the single authoritative leave state machine
 * (Phase 5 §6).
 *
 * Leave application lifecycle:
 *
 *   applied → pending_subsection_head → pending_section_head →
 *   pending_dept_head → pending_managing_director → pending_bod_chair →
 *   (any pending_* also routed via pending_hr / pending_hr_manager /
 *    pending_manager depending on the applicant's role)
 *
 *   pending_*  → approved   (final stage reached; balances are updated)
 *   pending_*  → rejected   (decision; balances untouched)
 *   pending_*  → cancelled  (applicant only; balances untouched)
 *   pending_*  → invalidated
 *   approved   → invalidated (formal HR reversal; balances are RESTORED)
 *
 * Forbidden forever: rejected → approved, approved → pending, cancelled →
 * approved, approved → rejected, cancelled → rejected, re-approval of any
 * terminal state. Enforced here and consumed by LeaveApprovalService so the
 * balance ledger can never be applied twice (Phase 5 §21 idempotency).
 */
final class LeaveWorkflowRules
{
    /** Statuses from which an approve/reject decision is still possible. */
    public const PENDING_STATUSES = [
        'pending_subsection_head',
        'pending_section_head',
        'pending_dept_head',
        'pending_managing_director',
        'pending_hr',
        'pending_hr_manager',
        'pending_bod_chair',
        'pending_manager',
    ];

    public const STATUS_APPROVED    = 'approved';
    public const STATUS_REJECTED    = 'rejected';
    public const STATUS_CANCELLED   = 'cancelled';
    public const STATUS_INVALIDATED = 'invalidated';

    /**
     * Is the application still awaiting a decision at some workflow stage?
     */
    public static function isPending(string $status): bool
    {
        return in_array($status, self::PENDING_STATUSES, true);
    }

    /**
     * May an approve/reject decision be taken on an application with this
     * status? Only pending applications are decidable.
     */
    public static function canDecide(string $status): bool
    {
        return self::isPending($status);
    }

    /**
     * May the application be invalidated (formal reversal)?
     * Allowed from any pending stage, and from approved — the one sanctioned
     * path that restores deducted balances. Terminal rejected/cancelled
     * states are final and can never be invalidated.
     */
    public static function canInvalidate(string $status): bool
    {
        return self::isPending($status) || $status === self::STATUS_APPROVED;
    }

    /**
     * Human-readable rejection reason for an invalid transition.
     */
    public static function transitionViolationMessage(string $action, string $status): string
    {
        $verb = match ($action) {
            'approve' => 'approved',
            'reject' => 'rejected',
            'invalidate' => 'invalidated',
            'cancel' => 'cancelled',
            default => $action . 'ed',
        };

        return sprintf(
            'This application is already %s and cannot be %s.',
            $status,
            $verb
        );
    }

    /**
     * Assert an approve/reject decision is allowed, or throw.
     *
     * @throws InvalidLeaveTransitionException
     */
    public static function assertCanDecide(string $status, string $action = 'approve'): void
    {
        if (!self::canDecide($status)) {
            throw new InvalidLeaveTransitionException(
                self::transitionViolationMessage($action, $status),
                ['current_status' => $status, 'action' => $action]
            );
        }
    }

    /**
     * Assert invalidation (formal reversal) is allowed, or throw.
     *
     * @throws InvalidLeaveTransitionException
     */
    public static function assertCanInvalidate(string $status): void
    {
        if (!self::canInvalidate($status)) {
            throw new InvalidLeaveTransitionException(
                self::transitionViolationMessage('invalidate', $status),
                ['current_status' => $status, 'action' => 'invalidate']
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Helpers\Database;
use App\Services\LeaveWorkflowService;

/**
 * LeaveNotificationService
 *
 * Every leave-related notification, in one place. The business services
 * (LeaveApplicationService, LeaveApprovalService) call these methods AFTER
 * committing; none of them send anything themselves.
 *
 * Two rules this class exists to enforce:
 *
 *  1. Notifications never break a business operation. A leave application that
 *     saves correctly but fails to email the approver is a far smaller problem
 *     than a leave application that rolls back because a mail server hiccuped,
 *     so every public method swallows and logs its own failures.
 *
 *  2. Dispatch happens AFTER commit, never inside the transaction. A queued row
 *     is a durable claim on a dedupe key: written inside the transaction, a
 *     rollback would leave the key free but the message already rendered - and
 *     worse, any partial write would suppress a legitimate retry as a
 *     "duplicate" for a decision that never happened.
 */
class LeaveNotificationService
{
    /** Human labels for the workflow stages, used in message copy. */
    private const STAGE_LABELS = [
        'pending_subsection_head'   => 'Sub-section Head',
        'pending_section_head'      => 'Section Head',
        'pending_dept_head'         => 'Department Head',
        'pending_managing_director' => 'Managing Director',
        'pending_bod_chair'         => 'Board Chair',
        'pending_hr'                => 'HR',
        'pending_hr_manager'        => 'HR Manager',
    ];

    private Database $db;
    private NotificationDispatcher $dispatcher;
    private LeaveWorkflowService $workflow;

    public function __construct(
        ?NotificationDispatcher $dispatcher = null,
        ?LeaveWorkflowService $workflow = null
    ) {
        $this->db = Database::getInstance();
        $this->dispatcher = $dispatcher ?? new NotificationDispatcher();
        $this->workflow = $workflow ?? new LeaveWorkflowService();
    }

    /**
     * Load the application row with the joins needed for message copy.
     *
     * @return array<string,mixed>|null
     */
    public function findApplication(int $applicationId): ?array
    {
        return $this->db->fetchOne(
            'SELECT la.*,
                    e.first_name, e.last_name,
                    lt.name AS leave_type_name
             FROM leave_applications la
             LEFT JOIN employees e ON e.id = la.employee_id
             LEFT JOIN leave_types lt ON lt.id = la.leave_type_id
             WHERE la.id = ?
             LIMIT 1',
            'i',
            [$applicationId]
        );
    }

    /**
     * A new application is waiting for the first approver.
     */
    public function notifyApplied(array $application): void
    {
        $this->safely('leave.applied', function () use ($application) {
            $approverIds = $this->resolveApprovers($application);
            if ($approverIds === []) {
                return;
            }

            $d = $this->describe($application);
            $this->sendToMany(
                $approverIds,
                NotificationDispatcher::TYPE_LEAVE_APPLIED,
                // One notification per application, not per approver: an approver
                // who is also the delegate must not receive it twice.
                'leave:' . (int) $application['id'] . ':applied',
                'Leave application awaiting your approval',
                sprintf(
                    "%s has applied for %s (%s).\n\nIt is now waiting for your decision at the %s stage.",
                    $d['employee_name'],
                    strtolower($d['leave_type']),
                    $d['period'],
                    $this->stageLabel((string) $application['status'])
                ),
                '/leave/approvals'
            );
        });
    }

    /**
     * An approver advanced the application to the next stage (not final).
     *
     * Two audiences: the applicant sees progress, and the NEXT approver needs to
     * know it is now their turn.
     */
    public function notifyStageAdvanced(array $application, string $previousStatus, int $actorUserId): void
    {
        $this->safely('leave.stage_advanced', function () use ($application, $previousStatus, $actorUserId) {
            $status = (string) $application['status'];
            $d = $this->describe($application);

            $this->sendToApplicant(
                $application,
                NotificationDispatcher::TYPE_LEAVE_STAGE_ADVANCED,
                'leave:' . (int) $application['id'] . ':' . $status,
                'Your leave application moved to the next approver',
                sprintf(
                    "%s approved your %s application (%s). It is now with the %s.",
                    $this->actorName($actorUserId),
                    strtolower($d['leave_type']),
                    $d['period'],
                    $this->stageLabel($status)
                )
            );

            $this->sendToMany(
                $this->resolveApprovers($application),
                NotificationDispatcher::TYPE_LEAVE_STAGE_ADVANCED,
                'leave:' . (int) $application['id'] . ':' . $status . ':incoming',
                'A leave application is now waiting for your approval',
                sprintf(
                    "%s's %s application (%s) has reached the %s stage and needs your decision.",
                    $d['employee_name'],
                    strtolower($d['leave_type']),
                    $d['period'],
                    $this->stageLabel($status)
                ),
                '/leave/approvals'
            );
        });
    }

    /**
     * Fully approved. The applicant is the only audience - there is no next stage.
     */
    public function notifyFullyApproved(array $application, int $actorUserId): void
    {
        $this->safely('leave.approved', function () use ($application, $actorUserId) {
            $d = $this->describe($application);
            $this->sendToApplicant(
                $application,
                NotificationDispatcher::TYPE_LEAVE_APPROVED,
                'leave:' . (int) $application['id'] . ':approved',
                'Your leave application was approved',
                sprintf(
                    "%s approved your %s application (%s) — %s day(s).",
                    $this->actorName($actorUserId),
                    strtolower($d['leave_type']),
                    $d['period'],
                    $d['days']
                )
            );
        });
    }

    /**
     * Rejected. The reason is included because "rejected" on its own is useless
     * to the applicant.
     */
    public function notifyRejected(array $application, string $reason, int $actorUserId): void
    {
        $this->safely('leave.rejected', function () use ($application, $reason, $actorUserId) {
            $d = $this->describe($application);
            $this->sendToApplicant(
                $application,
                NotificationDispatcher::TYPE_LEAVE_REJECTED,
                'leave:' . (int) $application['id'] . ':rejected',
                'Your leave application was rejected',
                sprintf(
                    "%s rejected your %s application (%s).\n\nReason: %s",
                    $this->actorName($actorUserId),
                    strtolower($d['leave_type']),
                    $d['period'],
                    trim($reason) !== '' ? trim($reason) : 'No reason was given.'
                )
            );
        });
    }

    /**
     * Cancelled by the applicant, or invalidated by HR.
     *
     * @param string $action 'cancelled' or 'invalidated'
     */
    public function notifyWithdrawn(array $application, string $action): void
    {
        $this->safely('leave.' . $action, function () use ($application, $action) {
            $isInvalidated = $action === 'invalidated';
            $d = $this->describe($application);
            $this->sendToApplicant(
                $application,
                $isInvalidated
                    ? NotificationDispatcher::TYPE_LEAVE_REJECTED
                    : NotificationDispatcher::TYPE_LEAVE_STAGE_ADVANCED,
                'leave:' . (int) $application['id'] . ':' . $action,
                $isInvalidated
                    ? 'Your leave application was invalidated'
                    : 'Your leave application was cancelled',
                sprintf(
                    'Your %s application (%s) was %s.',
                    strtolower($d['leave_type']),
                    $d['period'],
                    $isInvalidated ? 'invalidated by HR' : 'cancelled'
                )
            );
        });
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Who must act on this application right now?
     *
     * getApproverUserIds() is the existing, authoritative chain resolver, so it
     * stays authoritative. Its employee→user lookup only follows the
     * users.employee_id = staff-number linkage, though, so an approver whose
     * user row stores an employees.id instead would be missed. The dispatcher's
     * own resolver understands BOTH linkages, so the manager employee ids are
     * re-resolved directly and the two sets merged.
     *
     * @return list<int>
     */
    private function resolveApprovers(array $application): array
    {
        $status = (string) ($application['status'] ?? '');
        $employeeId = (int) ($application['employee_id'] ?? 0);

        $ids = [];
        foreach ($this->workflow->getApproverUserIds($status, $this->workflow->getManagers($employeeId)) as $id) {
            $ids[(int) $id] = true;
        }

        $managers = $this->workflow->getManagers($employeeId);
        $managerKeys = [
            'subsection_head_emp_id' => 'pending_subsection_head',
            'section_head_emp_id'    => 'pending_section_head',
            'dept_head_emp_id'       => 'pending_dept_head',
            'md_emp_id'              => 'pending_managing_director',
        ];
        foreach ($managerKeys as $key => $forStatus) {
            if ($forStatus !== $status || empty($managers[$key])) {
                continue;
            }
            $r = $this->dispatcher->resolveRecipientByEmployeeId((int) $managers[$key]);
            if ($r !== null) {
                $ids[$r['user_id']] = true;
            }
        }

        // The applicant must never be asked to approve their own request.
        $applicantId = (int) ($application['applied_by_user_id'] ?? 0);
        if ($applicantId > 0) {
            unset($ids[$applicantId]);
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * @param list<int> $userIds
     */
    private function sendToMany(
        array $userIds,
        string $type,
        string $dedupeKey,
        string $title,
        string $body,
        string $link
    ): void {
        foreach ($this->dispatcher->resolveRecipientsByUserIds($userIds) as $userId => $recipient) {
            $this->dispatcher->dispatch(
                $userId,
                $recipient['email'],
                $recipient['phone'],
                $type,
                [NotificationDispatcher::CHANNEL_IN_APP, NotificationDispatcher::CHANNEL_EMAIL],
                $dedupeKey,
                ['title' => $title, 'body' => $body, 'link' => $link, 'type' => 'info']
            );
        }
    }

    private function sendToApplicant(
        array $application,
        string $type,
        string $dedupeKey,
        string $title,
        string $body
    ): void {
        $userId = (int) ($application['applied_by_user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }
        $recipient = $this->dispatcher->resolveRecipientByUserId($userId);
        if ($recipient === null) {
            return;
        }
        $this->dispatcher->dispatch(
            $userId,
            $recipient['email'],
            $recipient['phone'],
            $type,
            [NotificationDispatcher::CHANNEL_IN_APP, NotificationDispatcher::CHANNEL_EMAIL],
            $dedupeKey,
            ['title' => $title, 'body' => $body, 'link' => '/leave/my-applications', 'type' => 'info']
        );
    }

    /**
     * The display facts every message needs.
     *
     * @return array{employee_name:string, leave_type:string, period:string, days:string}
     */
    private function describe(array $application): array
    {
        $name = trim(($application['first_name'] ?? '') . ' ' . ($application['last_name'] ?? ''));
        if ($name === '') {
            $name = 'An employee';
        }

        $start = (string) ($application['start_date'] ?? '');
        $end = (string) ($application['end_date'] ?? '');
        $period = '';
        if ($start !== '') {
            $period = $start === $end
                ? date('d M Y', strtotime($start))
                : date('d M Y', strtotime($start)) . ' – ' . date('d M Y', strtotime($end));
        }

        return [
            'employee_name' => $name,
            // Lowercase, because every caller renders it mid-sentence
            // ("applied for %s"), and the real type names are capitalised
            // ("Paternity Leave") and get strtolower()'d anyway. The fallback
            // is lowercase so the same phrasing still reads correctly when the
            // leave_types join is missing.
            'leave_type'     => (string) ($application['leave_type_name'] ?? 'leave'),
            'period'         => $period !== '' ? $period : 'unspecified dates',
            'days'           => (string) ($application['days_requested'] ?? '0'),
        ];
    }

    private function actorName(int $userId): string
    {
        if ($userId <= 0) {
            return 'An approver';
        }
        $row = $this->db->fetchOne(
            "SELECT COALESCE(NULLIF(CONCAT(e.first_name, ' ', e.last_name), ''), u.email, 'An approver') AS name
             FROM users u
             LEFT JOIN employees e
               ON e.id = CAST(NULLIF(u.employee_id, '') AS UNSIGNED)
               OR e.employee_id = u.employee_id
             WHERE u.id = ?
             LIMIT 1",
            'i',
            [$userId]
        );
        return trim((string) ($row['name'] ?? 'An approver'));
    }

    private function stageLabel(string $status): string
    {
        return self::STAGE_LABELS[$status] ?? ucwords(str_replace('_', ' ', $status));
    }

    /**
     * Run a notification routine so it can never break its caller.
     *
     * @param callable():void $fn
     */
    private function safely(string $context, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            \logger()->warning('Leave notification failed (business operation unaffected)', [
                'context' => $context,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}

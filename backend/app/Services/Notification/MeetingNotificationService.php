<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Helpers\Database;

/**
 * MeetingNotificationService
 *
 * Meeting invitations, RSVPs and cancellations.
 *
 * Meeting notifications differ from the leave/appraisal ones in that there is
 * usually a ROSTER: one meeting, many invitees, each needing their own
 * notification. The dedupe key therefore includes the RECIPIENT's identity -
 * without it the first invitee would consume the key and every remaining
 * invitee would be silently dropped as "already queued".
 *
 *     meeting:42:invited:380
 *     meeting:42:rsvp_accepted:415
 *     meeting:42:cancelled:380
 */
class MeetingNotificationService
{
    private Database $db;
    private NotificationDispatcher $dispatcher;

    public function __construct(?NotificationDispatcher $dispatcher = null)
    {
        $this->db = Database::getInstance();
        $this->dispatcher = $dispatcher ?? new NotificationDispatcher();
    }

    /**
     * Notify every invitee that a meeting has been scheduled.
     */
    public function notifyInvitations(int $meetingId): void
    {
        $this->safely('meeting.invited', function () use ($meetingId) {
            $meeting = $this->findMeeting($meetingId);
            if ($meeting === null) {
                return;
            }

            foreach ($this->invitees($meetingId) as $invitee) {
                $recipient = $this->dispatcher->resolveRecipientByEmployeeId($invitee['employee_id']);
                if ($recipient === null) {
                    continue;
                }
                $this->dispatcher->dispatch(
                    $recipient['user_id'],
                    $recipient['email'],
                    $recipient['phone'],
                    NotificationDispatcher::TYPE_MEETING_INVITATION,
                    [NotificationDispatcher::CHANNEL_IN_APP, NotificationDispatcher::CHANNEL_EMAIL],
                    // Recipient-scoped: one notification per person, not per meeting.
                    "meeting:{$meetingId}:invited:{$recipient['user_id']}",
                    [
                        'title' => 'You are invited to a meeting',
                        'body'  => sprintf(
                            "You have been invited to \"%s\".\n\n%s",
                            $meeting['title'],
                            $this->when($meeting)
                        ),
                        'link'  => '/meetings/' . $meetingId,
                        'type'  => 'info',
                    ]
                );
            }
        });
    }

    /**
     * Tell the meeting owner how an invitee responded.
     *
     * @param string $response 'accepted' | 'declined' | 'tentative'
     */
    public function notifyRsvp(int $meetingId, int $employeeId, string $response): void
    {
        $this->safely('meeting.rsvp', function () use ($meetingId, $employeeId, $response) {
            $meeting = $this->findMeeting($meetingId);
            if ($meeting === null) {
                return;
            }
            $ownerUserId = (int) ($meeting['created_by'] ?? 0);
            if ($ownerUserId <= 0 || $ownerUserId === $this->userIdForEmployee($employeeId)) {
                return;
            }

            $owner = $this->dispatcher->resolveRecipientByUserId($ownerUserId);
            if ($owner === null) {
                return;
            }

            $label = match ($response) {
                'accepted'  => 'accepted your invitation',
                'declined'  => 'declined your invitation',
                'tentative' => 'is unsure about your invitation',
                default     => 'responded to your invitation',
            };

            $this->dispatcher->dispatch(
                $owner['user_id'],
                $owner['email'],
                $owner['phone'],
                NotificationDispatcher::TYPE_MEETING_INVITATION,
                [NotificationDispatcher::CHANNEL_IN_APP, NotificationDispatcher::CHANNEL_EMAIL],
                "meeting:{$meetingId}:rsvp_{$response}:{$employeeId}",
                [
                    'title' => 'Meeting RSVP update',
                    'body'  => sprintf(
                        "%s %s to \"%s\".\n\n%s",
                        $this->employeeName($employeeId),
                        $label,
                        $meeting['title'],
                        $this->when($meeting)
                    ),
                    'link'  => '/meetings/' . $meetingId,
                    'type'  => $response === 'declined' ? 'warning' : 'info',
                ]
            );
        });
    }

    /**
     * Tell every invitee the meeting is cancelled.
     */
    public function notifyCancellation(int $meetingId): void
    {
        $this->safely('meeting.cancelled', function () use ($meetingId) {
            $meeting = $this->findMeeting($meetingId);
            if ($meeting === null) {
                return;
            }
            foreach ($this->invitees($meetingId) as $invitee) {
                $recipient = $this->dispatcher->resolveRecipientByEmployeeId($invitee['employee_id']);
                if ($recipient === null) {
                    continue;
                }
                $this->dispatcher->dispatch(
                    $recipient['user_id'],
                    $recipient['email'],
                    $recipient['phone'],
                    NotificationDispatcher::TYPE_MEETING_INVITATION,
                    [NotificationDispatcher::CHANNEL_IN_APP, NotificationDispatcher::CHANNEL_EMAIL],
                    "meeting:{$meetingId}:cancelled:{$recipient['user_id']}",
                    [
                        'title' => 'Meeting cancelled',
                        'body'  => sprintf(
                            "\"%s\" scheduled for %s has been cancelled.",
                            $meeting['title'],
                            $this->when($meeting)
                        ),
                        'link'  => '/meetings',
                        'type'  => 'warning',
                    ]
                );
            }
        });
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findMeeting(int $meetingId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM meetings WHERE id = ? LIMIT 1', 'i', [$meetingId]);
    }

    /**
     * Employee ids invited to a meeting.
     *
     * @return list<array{employee_id:int}>
     */
    private function invitees(int $meetingId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT employee_id FROM meeting_invitations WHERE meeting_id = ?',
            'i',
            [$meetingId]
        );
        return array_map(static fn (array $r): array => ['employee_id' => (int) $r['employee_id']], $rows);
    }

    /**
     * A readable "when and where" line.
     *
     * @param array<string,mixed> $meeting
     */
    private function when(array $meeting): string
    {
        $date = (string) ($meeting['meeting_date'] ?? '');
        $start = trim((string) ($meeting['start_time'] ?? ''));
        $end = trim((string) ($meeting['end_time'] ?? ''));
        $location = trim((string) ($meeting['location'] ?? ''));

        $parts = [];
        if ($date !== '') {
            $parts[] = date('l, d F Y', strtotime($date));
        }
        if ($start !== '') {
            $parts[] = ($end !== '' ? $start . '–' . $end : $start);
        }
        $line = implode(' at ', array_filter($parts));

        return $location !== '' ? $line . "\nLocation: " . $location : $line;
    }

    private function employeeName(int $employeeId): string
    {
        $row = $this->db->fetchOne(
            "SELECT COALESCE(NULLIF(CONCAT(first_name, ' ', last_name), ''), email, 'An employee') AS name
             FROM employees WHERE id = ? LIMIT 1",
            'i',
            [$employeeId]
        );
        return trim((string) ($row['name'] ?? 'An employee'));
    }

    private function userIdForEmployee(int $employeeId): int
    {
        $r = $this->dispatcher->resolveRecipientByEmployeeId($employeeId);
        return $r === null ? 0 : $r['user_id'];
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
            \logger()->warning('Meeting notification failed (business operation unaffected)', [
                'context' => $context,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}



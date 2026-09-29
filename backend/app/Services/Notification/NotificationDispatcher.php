<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Helpers\AppTime;
use App\Helpers\Database;
use App\Repositories\NotificationLogRepository;

/**
 * NotificationDispatcher
 *
 * THE single entry point for EVENT notifications (leave, appraisal, meetings)
 * and the enqueuing half of the scheduled ones.
 *
 * It deliberately does NOT send. It resolves the recipient, applies policy,
 * claims a dedupe row in notification_logs and returns. Delivery belongs to
 * cron/notification_worker.php, which is what keeps an SMTP timeout or a dead
 * SMS gateway from sitting inside a user's "submit leave request" response.
 *
 * TWO PROBLEMS IT EXISTS TO FIX:
 *
 *  1. Dedupe identity. A daily reminder can be identified by (date, type,
 *     stage), but an EVENT cannot: two leave approvals for the same person on
 *     the same day are different notifications. Callers pass an explicit
 *     dedupeKey and the unique (user_id, dedupe_key) index does the rest.
 *
 *  2. Recipient identity. users.employee_id is a varchar that may hold either
 *     employees.id or a staff number, and the legacy NotificationService
 *     helpers pass an employees.id straight into sendInApp(), which expects a
 *     users.id. resolveRecipientByEmployeeId() is the ONE place that mapping is
 *     decided, so no call site can get it wrong again.
 */
class NotificationDispatcher
{
    /** Canonical notification types. Used for filtering, stats and the worker. */
    public const TYPE_LEAVE_APPLIED           = 'leave_applied';
    public const TYPE_LEAVE_SUBMITTED         = 'leave_submitted';
    public const TYPE_LEAVE_STAGE_ADVANCED    = 'leave_stage_advanced';
    public const TYPE_LEAVE_REJECTED          = 'leave_rejected';
    public const TYPE_LEAVE_APPROVED          = 'leave_approved';
    public const TYPE_APPRAISAL_STATUS        = 'appraisal_status';
    public const TYPE_MEETING_INVITATION      = 'meeting_invitation';
    public const TYPE_FINANCIAL_YEAR_CLOSING  = 'financial_year_closing';
    public const TYPE_LEAVE_ROSTER_MONTHLY    = 'leave_roster_monthly';

    public const CHANNEL_IN_APP = 'in_app';
    public const CHANNEL_EMAIL  = 'email';
    public const CHANNEL_SMS    = 'sms';
    public const CHANNEL_PUSH   = 'web_push';

    private Database $db;
    private NotificationLogRepository $logs;

    public function __construct(?NotificationLogRepository $logs = null)
    {
        $this->db = Database::getInstance();
        $this->logs = $logs ?? new NotificationLogRepository();
    }

    /**
     * Queue a notification for one recipient.
     *
     * @param array{title:string, body:string, link?:?string, type?:string} $payload
     * @param array<int,string> $channels
     * @return array{queued:array<string,int>, skipped:array<string,string>, recipient:?array}
     */
    public function dispatch(
        ?int $userId,
        ?string $email,
        ?string $phone,
        string $type,
        array $channels,
        string $dedupeKey,
        array $payload,
        ?int $employeeId = null
    ): array {
        $result = ['queued' => [], 'skipped' => [], 'recipient' => null];

        if ($userId === null || $userId <= 0) {
            $result['skipped']['*'] = 'No linked user account';
            return $result;
        }

        $result['recipient'] = ['user_id' => $userId, 'email' => $email, 'phone' => $phone];

        foreach ($channels as $channel) {
            // The channel is part of the dedupe key, so re-queuing the same
            // event never re-queues one channel but re-enables another.
            $channelKey = $dedupeKey . '|' . $channel;

            if ($skipReason = $this->channelBlockedReason($channel, $userId, $email, $phone)) {
                // Inserted as 'skipped', NOT 'pending': a pending row would be
                // picked up by the worker and delivered, silently reversing the
                // very policy decision made on the line above.
                //
                // The row id is captured and the reason written back, because a
                // skipped row with a NULL failure_reason is indistinguishable
                // from "no address on file" when someone later asks why they got
                // no email. The distinction matters: "you switched it off" and
                // "we cannot reach you" are different problems.
                $skippedId = $this->logs->claimFor(
                    $userId,
                    $employeeId,
                    $type,
                    $channel,
                    'skipped',
                    $channelKey,
                    null,
                    $payload,
                    'skipped'
                );
                if ($skippedId !== null) {
                    $this->logs->markSkipped($skippedId, $skipReason);
                }
                $result['skipped'][$channel] = $skipReason;
                continue;
            }

            $rowId = $this->logs->claimFor(
                $userId,
                $employeeId,
                $type,
                $channel,
                'queued',
                $channelKey,
                null,
                $payload
            );

            if ($rowId === null) {
                // Already queued or already delivered. This is the entire point
                // of the dedupe key and must stay silent, not an error.
                $result['skipped'][$channel] = 'Already queued';
                continue;
            }

            $result['queued'][$channel] = $rowId;
        }

        // The in-app row is the one thing written synchronously, because it is a
        // database insert rather than a network call and the existing
        // Header/Dashboard readers query the `notifications` table directly.
        // Doing it here means the bell updates as soon as the request returns.
        //
        // The queued in_app log row is then marked sent. Leaving it pending
        // would let the worker re-insert the same notification on its next run
        // and the employee would see every notification twice.
        if (isset($result['queued'][self::CHANNEL_IN_APP])) {
            $this->writeInApp($userId, $payload);
            $this->logs->markSent((int) $result['queued'][self::CHANNEL_IN_APP], 'in_app:sync');
        }

        return $result;
    }

    /**
     * Insert the row the in-app notification list reads.
     *
     * Wrapped so a failure can never take down the business operation that
     * triggered it: a missed bell is an annoyance, a failed leave application
     * is not.
     */
    private function writeInApp(int $userId, array $payload): void
    {
        try {
            $this->db->insert('notifications', [
                'user_id'    => $userId,
                'title'      => (string) ($payload['title'] ?? 'Notification'),
                'message'    => (string) ($payload['body'] ?? ''),
                'type'       => (string) ($payload['type'] ?? 'info'),
                'category'   => 'general',
                'action_url' => $payload['link'] ?? null,
                'is_read'    => 0,
                'is_sent'    => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            \logger()->warning('In-app notification could not be written', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }


    /**
     * Why this channel cannot be delivered to this person, or null if it can.
     *
     * Returning the REASON rather than a bool is what makes the audit trail
     * useful. "Recipient switched email off" and "no email address on file" both
     * produce a skipped row, but they are different problems with different
     * fixes, and a NULL failure_reason made them indistinguishable in the log.
     *
     * Preferences are checked for EVERY channel, email included: the
     * notification_preferences table defaults email_enabled to 0, so without
     * this check every new email trigger would silently no-op for every user.
     *
     * @return string|null
     */
    private function channelBlockedReason(string $channel, int $userId, ?string $email, ?string $phone): ?string
    {
        switch ($channel) {
            case self::CHANNEL_IN_APP:
                // Always available: the in-app row is written to our own database.
                return null;

            case self::CHANNEL_EMAIL:
                if ($email === null || $email === '') {
                    return 'No email address on file for this account';
                }
                if (!$this->prefEnabled($userId, 'email_enabled')) {
                    return 'Recipient has email notifications switched off';
                }
                return null;

            case self::CHANNEL_SMS:
                if ($phone === null || $phone === '') {
                    return 'No phone number on file for this employee';
                }
                if (!$this->prefEnabled($userId, 'sms_enabled')) {
                    return 'Recipient has SMS notifications switched off';
                }
                return null;

            case self::CHANNEL_PUSH:
                if (!$this->prefEnabled($userId, 'push_enabled')) {
                    return 'Recipient has push notifications switched off';
                }
                return null;

            default:
                return 'Unknown channel: ' . $channel;
        }
    }

    /**
     * Read one preference, defaulting to ON when no row exists.
     *
     * A row is optional by design ("defaults are organisation-managed"), and an
     * absent row means the employee never expressed a choice - which is not the
     * same as having opted out.
     */
    private function prefEnabled(int $userId, string $column): bool
    {
        // Whitelisted because the column is interpolated into SQL; these four
        // are the only values that can ever reach it.
        $allowed = ['push_enabled', 'sms_enabled', 'email_enabled', 'reminders_mandated'];
        if (!in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException("Unknown preference column: {$column}");
        }

        $value = $this->db->fetchValue(
            "SELECT `{$column}` FROM notification_preferences WHERE user_id = ? LIMIT 1",
            'i',
            [$userId]
        );

        return $value === null ? true : (int) $value === 1;
    }

    /** Today's org-timezone date, the business date for event rows. */
    public function today(): string
    {
        return AppTime::today();
    }

    /**
     * Resolve an employees.id to the user that can receive notifications.
     *
     * The ONE place the users.employee_id ambiguity is handled. That column may
     * hold an employees.id primary key OR a human staff number depending on how
     * the row was created, and the employee's own email is the most reliable
     * linkage of all - so both linkages are tried before giving up.
     *
     * @return array{user_id:int, email:?string, phone:?string, employee_id:int}|null
     */
    public function resolveRecipientByEmployeeId(int $employeeId): ?array
    {
        if ($employeeId <= 0) {
            return null;
        }

        $row = $this->db->fetchOne(
            'SELECT e.id AS employee_id, e.email AS employee_email, e.phone AS employee_phone,
                    u.id AS user_id, u.email AS user_email
             FROM employees e
             JOIN users u
               ON u.is_active = 1
              AND (u.employee_id = CAST(e.id AS CHAR) OR u.employee_id = e.employee_id)
             WHERE e.id = ?
             LIMIT 1',
            'i',
            [$employeeId]
        );

        // Fall back to matching the account email to the employee's email: the
        // linkage that survives when users.employee_id holds a staff number.
        if (!$row) {
            $row = $this->db->fetchOne(
                'SELECT e.id AS employee_id, e.email AS employee_email, e.phone AS employee_phone,
                        u.id AS user_id, u.email AS user_email
                 FROM employees e
                 JOIN users u ON LOWER(u.email) = LOWER(e.email) AND u.is_active = 1
                 WHERE e.id = ?
                 LIMIT 1',
                'i',
                [$employeeId]
            );
        }

        if (!$row) {
            return null;
        }

        return [
            'user_id'     => (int) $row['user_id'],
            // The ACCOUNT email is the deliverable one; an employee's personal
            // address may differ and may not even be populated.
            'email'       => $this->firstNonEmpty($row['user_email'], $row['employee_email']),
            'phone'       => $this->firstNonEmpty($row['employee_phone']),
            'employee_id' => (int) $row['employee_id'],
        ];
    }

    /**
     * Resolve the recipient for a user we already know the id of.
     *
     * @return array{user_id:int, email:?string, phone:?string, employee_id:?int}|null
     */
    public function resolveRecipientByUserId(int $userId): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT u.id AS user_id, u.email AS user_email,
                    e.id AS employee_id_resolved, e.phone AS employee_phone, e.email AS employee_email
             FROM users u
             LEFT JOIN employees e
               ON e.id = CAST(NULLIF(u.employee_id, '') AS UNSIGNED)
               OR e.employee_id = u.employee_id
             WHERE u.id = ? AND u.is_active = 1
             LIMIT 1",
            'i',
            [$userId]
        );

        if (!$row) {
            return null;
        }

        return [
            'user_id'     => (int) $row['user_id'],
            'email'       => $this->firstNonEmpty($row['user_email'], $row['employee_email']),
            'phone'       => $this->firstNonEmpty($row['employee_phone']),
            'employee_id' => $row['employee_id_resolved'] !== null ? (int) $row['employee_id_resolved'] : null,
        ];
    }

    /**
     * Resolve several users at once, dropping those who cannot be reached.
     *
     * @param  array<int,int|string> $userIds
     * @return array<int,array{user_id:int, email:?string, phone:?string, employee_id:?int}>
     */
    public function resolveRecipientsByUserIds(array $userIds): array
    {
        $out = [];
        foreach (array_unique(array_filter(array_map('intval', $userIds))) as $userId) {
            $r = $this->resolveRecipientByUserId($userId);
            if ($r !== null) {
                $out[$userId] = $r;
            }
        }
        return $out;
    }

    private function firstNonEmpty(...$values): ?string
    {
        foreach ($values as $v) {
            if ($v !== null && trim((string) $v) !== '') {
                return trim((string) $v);
            }
        }
        return null;
    }
}

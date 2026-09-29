<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Helpers\AppTime;
use App\Helpers\Database;

/**
 * ScheduledNotificationService
 *
 * The two SCHEDULED event notifications - the ones a cron job decides to send
 * rather than a user action triggering them:
 *
 *  1. financial_year_closing  - a financial year is about to end, so leave must
 *     be planned and unspent balances reviewed.
 *  2. leave_roster_monthly    - an employee's planned roster month is next
 *     month, and they are (or plan to be) away.
 *
 * Dedupe keys are SCOPED BY PERIOD, which is what makes these safe to run every
 * day: the key is consumed by the first run that finds someone to notify, and
 * every later run is a no-op.
 *
 *     financial_year:39:closing
 *     leave_roster:2026-10:418
 */
class ScheduledNotificationService
{
    /**
     * Days before the financial year ends that HR is warned.
     *
     * A month of lead time: long enough to plan cover and chase unspent leave,
     * short enough to still be actionable.
     */
    public const FY_WARNING_DAYS = 30;

    /** Roles that administer financial years and leave. */
    private const ADMIN_ROLES = ['hr_manager', 'super_admin', 'managing_director'];

    private Database $db;
    private NotificationDispatcher $dispatcher;

    /**
     * When true, nothing is written: every dispatch is evaluated and counted
     * exactly as in a live run, but no queue row, dedupe key or in-app
     * notification is created. The cron passes this through from --dry-run.
     */
    private bool $dryRun = false;

    public function __construct(?NotificationDispatcher $dispatcher = null, bool $dryRun = false)
    {
        $this->db = Database::getInstance();
        $this->dispatcher = $dispatcher ?? new NotificationDispatcher();
        $this->dryRun = $dryRun;
    }

    /**
     * Is this instance reporting without writing?
     */
    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * Warn administrators about a financial year that is closing soon.
     *
     * @return array<string,mixed> A summary for the cron log / verification.
     */
    public function queueFinancialYearClosingAlerts(): array
    {
        $summary = ['fiscal_years_checked' => 0, 'alerts_sent' => 0, 'details' => []];

        $years = $this->db->fetchAll(
            'SELECT id, year_name, start_date, end_date FROM financial_years ORDER BY end_date'
        );

        foreach ($years as $fy) {
            $summary['fiscal_years_checked']++;

            $daysLeft = $this->daysUntil((string) $fy['end_date']);
            // Already ended, or outside the warning window: nothing to say.
            if ($daysLeft === null || $daysLeft < 0 || $daysLeft > self::FY_WARNING_DAYS) {
                continue;
            }

            $title = $daysLeft === 0
                ? sprintf('Financial year %s ends today', $fy['year_name'])
                : sprintf('Financial year %s closes in %d day(s)', $fy['year_name'], $daysLeft);

            $body = sprintf(
                "The financial year %s (%s to %s) %s.\n\n"
                . "Unspent leave allowances do not roll over automatically. Review outstanding "
                . "balances and roster the remaining months before the year closes.",
                $fy['year_name'],
                date('j M Y', strtotime((string) $fy['start_date'])),
                date('j M Y', strtotime((string) $fy['end_date'])),
                $daysLeft === 0 ? 'closes today' : 'is closing'
            );

            $recipients = $this->adminRecipients();
            foreach ($recipients as $recipient) {
                $this->send(
                    $recipient,
                    NotificationDispatcher::TYPE_FINANCIAL_YEAR_CLOSING,
                    'financial_year:' . (int) $fy['id'] . ':closing',
                    ['title' => $title, 'body' => $body, 'link' => '/hr-admin/financial-year', 'type' => 'warning']
                );
                $summary['alerts_sent']++;
            }

            $summary['details'][] = [
                'financial_year' => $fy['year_name'],
                'ends'           => $fy['end_date'],
                'days_left'      => $daysLeft,
                'recipients'     => count($recipients),
            ];
        }

        return $summary;
    }

    /**
     * Remind employees whose planned roster month starts next month.
     *
     * Both populations are reminded, with different wording: employees who
     * already have APPROVED leave in that window get a "confirm arrangements"
     * note, while employees who are only on the ROSTER (a plan, never a
     * booking - see LeaveRosterService) get a "no application yet" note.
     * Reminding only the approved group would silently skip the people most
     * likely to have forgotten to apply.
     *
     * @param string|null $monthOverride 'YYYY-MM'; defaults to next month.
     * @return array<string,mixed>
     */
    public function queueMonthlyRosterReminders(?string $monthOverride = null): array
    {
        $month = $monthOverride ?? $this->nextMonth();
        [$monthStart, $monthEnd] = $this->monthBounds($month);
        $monthName = date('F', strtotime($monthStart));
        $year = (int) date('Y', strtotime($monthStart));

        $summary = [
            'month'          => $month,
            'roster_found'   => 0,
            'approved_found' => 0,
            'reminders_sent' => 0,
        ];

        // `leave_roster.scheduled_year` is NULL for every row in the current
        // dataset, so it cannot be used to select the target month. The
        // authoritative mapping is the financial year the entry belongs to
        // plus the July->June rule (LeaveRosterService::yearForMonth): July to
        // December fall in the FY start year, January to June in the next.
        $secondHalf = ['January', 'February', 'March', 'April', 'May', 'June'];
        $isSecondHalf = in_array($monthName, $secondHalf, true);
        $fyYear = $year - ($isSecondHalf ? 1 : 0);

        $roster = $this->db->fetchAll(
            "SELECT r.employee_id
             FROM leave_roster r
             JOIN financial_years fy ON fy.id = r.financial_year_id
             WHERE r.scheduled_month = ? AND YEAR(fy.start_date) = ?
             ORDER BY r.employee_id",
            'si',
            [$monthName, $fyYear]
        );
        $summary['roster_found'] = count($roster);

        foreach ($roster as $entry) {
            $employeeId = (int) $entry['employee_id'];

            $approved = $this->db->fetchOne(
                "SELECT MIN(start_date) AS first_day, MAX(end_date) AS last_day, COUNT(*) AS n
                 FROM leave_applications
                 WHERE employee_id = ?
                   AND status = 'approved'
                   AND start_date <= ? AND end_date >= ?",
                'iss',
                [$employeeId, $monthEnd, $monthStart]
            );
            $hasApproved = $approved !== null && (int) ($approved['n'] ?? 0) > 0;
            $summary['approved_found'] += $hasApproved ? 1 : 0;

            $recipient = $this->dispatcher->resolveRecipientByEmployeeId($employeeId);
            if ($recipient === null) {
                continue;
            }

            $body = $hasApproved
                ? sprintf(
                    "Your planned leave month is %s %d, and you have approved leave from %s to %s.\n\n"
                    . "Please confirm arrangements with your delegate before the month begins.",
                    $monthName,
                    $year,
                    date('j M Y', strtotime((string) $approved['first_day'])),
                    date('j M Y', strtotime((string) $approved['last_day']))
                )
                : sprintf(
                    "Your planned leave month on the roster is %s %d.\n\n"
                    . "You do not have an approved leave application for that month yet. "
                    . "Submit one if you intend to be away.",
                    $monthName,
                    $year
                );

            $this->send(
                $recipient,
                NotificationDispatcher::TYPE_LEAVE_ROSTER_MONTHLY,
                // Period-scoped: one reminder per employee per roster month.
                "leave_roster:{$month}:{$employeeId}",
                [
                    'title' => sprintf('Your planned leave month is %s %d', $monthName, $year),
                    'body'  => $body,
                    'link'  => '/leave/my-applications',
                    'type'  => $hasApproved ? 'info' : 'warning',
                ]
            );
            $summary['reminders_sent']++;
        }

        return $summary;
    }


    // ---------------------------------------------------------------- helpers

    /**
     * Active users who administer financial years / leave.
     *
     * @return array<int,array{user_id:int, email:?string, phone:?string}>
     */
    private function adminRecipients(): array
    {
        $ph = implode(',', array_fill(0, count(self::ADMIN_ROLES), '?'));
        return array_map(
            static fn (array $r): array => [
                'user_id' => (int) $r['id'],
                'email'   => $r['email'] ?? null,
                'phone'   => $r['phone'] ?? null,
            ],
            $this->db->fetchAll(
                "SELECT id, email FROM users
                 WHERE is_active = 1 AND role IN ({$ph})",
                str_repeat('s', count(self::ADMIN_ROLES)),
                self::ADMIN_ROLES
            )
        );
    }

    /**
     * Whole days from today until $date (negative once it has passed).
     *
     * Both sides are normalised to midnight in the org timezone so a run at
     * 23:59 and a run at 00:01 the next morning cannot disagree about whether
     * the final day has arrived.
     */
    private function daysUntil(string $date): ?int
    {
        $target = strtotime($date . ' 00:00:00');
        if ($target === false) {
            return null;
        }
        $today = strtotime(AppTime::today() . ' 00:00:00');
        return (int) round(($target - $today) / 86400);
    }

    /**
     * The month AFTER the current org-timezone month, as 'YYYY-MM'.
     */
    private function nextMonth(): string
    {
        return AppTime::now()->modify('first day of next month')->format('Y-m');
    }

    /**
     * @return array{0:string,1:string} First and last calendar day, inclusive.
     */
    private function monthBounds(string $month): array
    {
        $start = new \DateTimeImmutable($month . '-01');
        $end = $start->modify('last day of this month');
        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    /**
     * Dispatch, unless this is a dry run.
     *
     * Routed through ONE method so --dry-run is honoured by every send path. An
     * earlier version gated only the printed counts, so a "dry run" still wrote
     * queue rows and consumed dedupe keys - which then suppressed the real run's
     * notifications for that period entirely.
     *
     * @param array{user_id:int, email:?string, phone:?string} $recipient
     * @param array{title:string, body:string, link?:?string, type?:string} $payload
     */
    private function send(array $recipient, string $type, string $dedupeKey, array $payload): void
    {
        if ($this->dryRun) {
            return;
        }

        $this->dispatcher->dispatch(
            $recipient['user_id'],
            $recipient['email'],
            $recipient['phone'],
            $type,
            [NotificationDispatcher::CHANNEL_IN_APP, NotificationDispatcher::CHANNEL_EMAIL],
            $dedupeKey,
            $payload
        );
    }
}


<?php

declare(strict_types=1);

/**
 * Scheduled notification cron (financial-year closing + monthly leave roster).
 *
 * Queues notifications; it does not deliver them. Delivery is the notification
 * worker's job (cron/notification_worker.php), so a slow SMTP server can never
 * make this job fail part-way and lose the run.
 *
 * Both jobs are safe to run every day: their dedupe keys are scoped to the
 * period, so after the first successful run every later run is a no-op.
 *
 * Task Scheduler (recommended: daily, but a more frequent run is harmless):
 *   php backend/cron/scheduled_notifications.php
 *
 *   --task=fy        financial-year closing alerts only
 *   --task=roster    monthly roster reminders only
 *   --month=YYYY-MM  override the roster month (defaults to next month)
 *   --dry-run        report what WOULD be queued, queue nothing
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Helpers\AppTime;
use App\Services\Notification\ScheduledNotificationService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$options  = getopt('', ['task::', 'month::', 'dry-run']);
$task     = (string) ($options['task'] ?? 'all');
$month    = isset($options['month']) ? (string) $options['month'] : null;
$dryRun   = array_key_exists('dry-run', $options);

$service = new ScheduledNotificationService(null, $dryRun);

printf(
    "Scheduled Notifications | %s | %s\n",
    AppTime::now()->format('Y-m-d H:i:s T'),
    $dryRun ? 'DRY RUN (nothing queued)' : 'LIVE'
);

$runFy = in_array($task, ['all', 'fy'], true);
$runRoster = in_array($task, ['all', 'roster'], true);

if (!$runFy && !$runRoster) {
    fwrite(STDERR, "Unknown --task '{$task}'. Use fy, roster or all.\n");
    exit(1);
}

if ($runFy) {
    $summary = $service->queueFinancialYearClosingAlerts();
    printf(
        "  financial year: %d checked, %d alert(s) queued%s\n",
        $summary['fiscal_years_checked'],
        $dryRun ? 0 : $summary['alerts_sent'],
        $dryRun ? ' (dry run)' : ''
    );
    foreach ($summary['details'] as $d) {
        printf(
            "    %s ends %s (%d day(s) left) -> %d admin(s)\n",
            $d['financial_year'],
            $d['ends'],
            $d['days_left'],
            $d['recipients']
        );
    }
}

if ($runRoster) {
    $summary = $service->queueMonthlyRosterReminders($month);
    printf(
        "  roster %s: %d rostered, %d with approved leave, %d reminder(s) queued%s\n",
        $summary['month'],
        $summary['roster_found'],
        $summary['approved_found'],
        $dryRun ? 0 : $summary['reminders_sent'],
        $dryRun ? ' (dry run)' : ''
    );
}

if ($dryRun) {
    echo "Dry run complete - nothing was queued.\n";
    exit(0);
}

echo "Done. Run notification_worker.php to deliver.\n";

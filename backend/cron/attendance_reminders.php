<?php

declare(strict_types=1);

/**
 * Attendance Reminder Scheduler (CLI).
 *
 * Designed to run EVERY 5 MINUTES via Windows Task Scheduler; it is
 * fully idempotent - notification_logs' UNIQUE key makes duplicate
 * runs harmless no-ops, so "when did it run?" never matters.
 *
 * Stages (all env-configurable, see ReminderSettings):
 *   reminder_1   ATTENDANCE_REMINDER_TIME (default 08:00)  -> Web Push
 *   sms_fallback reminder_1 + ATTENDANCE_SMS_FALLBACK_DELAY_MINUTES
 *                (default +15)                             -> SMS
 *
 * Before EVERY sms_fallback send the employee's attendance is
 * re-checked live: clocking in between the push and the fallback can
 * only ever cancel the SMS, never cause it.
 *
 * Windows Task Scheduler:
 *   Program : C:\xampp\php\php.exe
 *   Args    : C:\xampp\htdocs\hrdemo\backend\cron\attendance_reminders.php
 *   Start in: C:\xampp\htdocs\hrdemo\backend\cron
 *   Trigger : Daily, repeat every 5 minutes, indefinite duration
 *
 * Manual runs:
 *   php backend/cron/attendance_reminders.php                 # process due stages
 *   php backend/cron/attendance_reminders.php --dry-run       # report only
 *   php backend/cron/attendance_reminders.php --employee=12   # single employee
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Helpers\AppTime;
use App\Repositories\NotificationLogRepository;
use App\Services\AttendanceReminderEligibilityService;
use App\Services\Notification\NotificationRouter;
use App\Services\ReminderSettings;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// ---------------------------------------------------------------- args
$options = getopt('', ['dry-run', 'stage::', 'date::', 'employee::']);
$dryRun     = array_key_exists('dry-run', $options);
$date       = isset($options['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $options['date'])
    ? (string) $options['date']
    : AppTime::today();
$employeeId = isset($options['employee']) ? (int) $options['employee'] : null;

$settings     = new ReminderSettings();
$eligibility  = new AttendanceReminderEligibilityService();
$logs         = new NotificationLogRepository();
$router       = new NotificationRouter(
    $eligibility,
    new \App\Services\CalendarContextService(),
    $logs,
    new \App\Repositories\NotificationPreferenceRepository(),
    null,
    null,
    $settings
);

/** @return array<string,bool> which stages are due at this minute */
function dueStages(ReminderSettings $settings, string $date): array
{
    $minutes = AppTime::minutesSinceMidnight();
    // A --date override implies replay mode: treat all stages as due.
    if ($date !== AppTime::today()) {
        return [
            ReminderSettings::STAGE_REMINDER_1   => true,
            ReminderSettings::STAGE_SMS_FALLBACK => true,
            ReminderSettings::STAGE_REMINDER_2   => $settings->secondReminderEnabled(),
        ];
    }

    return [
        ReminderSettings::STAGE_REMINDER_1 =>
            $settings->pushEnabled() && $minutes >= $settings->reminderMinutes(),

        ReminderSettings::STAGE_SMS_FALLBACK =>
            $settings->smsEnabled() && $settings->smsFallbackEnabled()
            && $minutes >= $settings->reminderMinutes() + $settings->smsFallbackDelayMinutes(),

        ReminderSettings::STAGE_REMINDER_2 =>
            $settings->secondReminderEnabled()
            && $minutes >= $settings->reminderMinutes() + $settings->secondReminderMinutes(),
    ];
}

try {
    // ---------------- housekeeping (cheap, every run) ------------------
    $reaped = $logs->reapStalePending(15);

    // ------------------------------------------------ load & evaluate ---
    $candidates = $eligibility->getCandidates($date);
    if ($employeeId !== null) {
        $candidates = array_values(array_filter(
            $candidates,
            static fn (array $c): bool => (int) $c['employee_id'] === $employeeId
        ));
    }

    $results   = $eligibility->evaluateBatch($candidates, $date);
    $eligible  = [];
    foreach ($candidates as $candidate) {
        $id = (int) $candidate['employee_id'];
        if (($results[$id] ?? null)?->eligible) {
            $eligible[$id] = $candidate;
        }
    }

    $stagesDue  = dueStages($settings, $date);
    $summary = [
        'date'          => $date,
        'candidates'    => count($candidates),
        'eligible'      => count($eligible),
        'skipped_by'    => [],
        'stages_due'    => array_keys(array_filter($stagesDue)),
        'push_sent'     => 0,
        'push_other'    => 0,
        'sms_sent'      => 0,
        'sms_skipped'   => 0,
        'sms_failed'    => 0,
        'sms_retried'   => 0,
        'reaped_stale'  => $reaped,
        'dry_run'       => $dryRun,
    ];
    foreach ($results as $result) {
        if (!$result->eligible) {
            $summary['skipped_by'][$result->reason] = ($summary['skipped_by'][$result->reason] ?? 0) + 1;
        }
    }

    echo '[' . date('Y-m-d H:i:s') . "] attendance_reminders date={$date} candidates={$summary['candidates']} "
        . "eligible={$summary['eligible']} stages=" . implode(',', $summary['stages_due'])
        . ($dryRun ? " [DRY RUN]" : '') . PHP_EOL;

    if ($dryRun) {
        foreach ($summary['skipped_by'] as $reason => $count) {
            echo "  skipped {$reason}: {$count}" . PHP_EOL;
        }
        exit(0);
    }

    // ------------------------------------------------- reminder_1 push ---
    if ($stagesDue[ReminderSettings::STAGE_REMINDER_1]) {
        foreach ($eligible as $candidate) {
            $outcome = $router->routeReminder($candidate, $date);
            if ($outcome['status'] === 'sent' || $outcome['status'] === 'duplicate') {
                // duplicate = already sent on an earlier tick: still success
                if ($outcome['status'] === 'sent') {
                    $summary['push_sent']++;
                }
            } elseif ($outcome['status'] === 'failed' || $outcome['status'] === 'failed_retryable') {
                $summary['push_other']++;
            } else {
                $summary['push_other']++;
            }
        }
        echo "  reminder_1 push sent={$summary['push_sent']} other={$summary['push_other']}" . PHP_EOL;
    }

    // --------------------------------------------- optional second push --
    if ($stagesDue[ReminderSettings::STAGE_REMINDER_2]) {
        $second = 0;
        foreach ($eligible as $candidate) {
            $outcome = $router->routeReminder($candidate, $date, ReminderSettings::STAGE_REMINDER_2);
            if ($outcome['status'] === 'sent') {
                $second++;
            }
        }
        echo "  reminder_2 push sent={$second}" . PHP_EOL;
    }

    // ------------------------------------------------ sms_fallback stage -
    if ($stagesDue[ReminderSettings::STAGE_SMS_FALLBACK]) {
        foreach ($eligible as $candidate) {
            $userId = (int) ($candidate['user_id'] ?? 0);

            // Was today's primary push actually delivered? (works across
            // cron ticks - state lives in notification_logs, not memory)
            $pushRow   = $logs->findFor($userId, $date, 'web_push', ReminderSettings::STAGE_REMINDER_1);
            $pushOk    = $pushRow !== null && $pushRow['status'] === 'sent';

            $outcome = $router->routeSmsFallback($candidate, $date, !$pushOk);

            if ($outcome['status'] === 'sent') {
                $summary['sms_sent']++;
            } elseif (str_starts_with($outcome['status'], 'failed')) {
                $summary['sms_failed']++;
            } else {
                $summary['sms_skipped']++;
            }
        }
        echo "  sms_fallback sent={$summary['sms_sent']} skipped={$summary['sms_skipped']} failed={$summary['sms_failed']}" . PHP_EOL;

        // ---------------- temporary-failure retries within attempt budget -
        foreach ($logs->findRetryable(ReminderSettings::STAGE_SMS_FALLBACK, $date, $settings->maxSmsAttempts()) as $row) {
            $candidate = null;
            foreach ($candidates as $c) {
                if ((int) $c['employee_id'] === (int) $row['employee_id']) {
                    $candidate = $c;
                    break;
                }
            }
            if ($candidate === null) {
                continue; // employee left the candidate set (deactivated etc.)
            }
            $logs->markRetrying((int) $row['id']);
            $outcome = $router->routeSmsRetry((int) $row['id'], $candidate, $date);
            if ($outcome['status'] === 'sent') {
                $summary['sms_retried']++;
            }
        }
    }

    echo '[' . date('Y-m-d H:i:s') . '] done: '
        . "push={$summary['push_sent']} sms={$summary['sms_sent']} "
        . "skipped=" . json_encode($summary['skipped_by']) . PHP_EOL;
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] FATAL: ' . $e->getMessage() . PHP_EOL
        . $e->getTraceAsString() . PHP_EOL);
    \logger()->error('attendance_reminders crashed', ['error' => $e->getMessage()]);
    exit(1);
}

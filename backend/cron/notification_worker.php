<?php

declare(strict_types=1);

/**
 * Notification Worker (CLI).
 *
 * Drains notification_logs rows that NotificationDispatcher queued and hands
 * each to a channel. This is the process that keeps a slow SMTP server or a
 * dead SMS gateway from sitting inside somebody's "submit leave request".
 *
 * The database IS the queue (docs/NOTIFICATIONS.md §3): dispatchers INSERT a
 * pending row, the unique (user_id, dedupe_key) index makes enqueuing
 * idempotent, and this worker is the only thing that delivers.
 *
 * Windows Task Scheduler:
 *   Program : C:\xampp\php\php.exe
 *   Args    : C:\xampp\htdocs\hrdemo\backend\cron\notification_worker.php
 *   Start in: C:\xampp\htdocs\hrdemo\backend\cron
 *   Trigger : Daily, repeat every 1 minute, indefinite duration
 *
 * Manual runs:
 *   php backend/cron/notification_worker.php                  # drain due rows
 *   php backend/cron/notification_worker.php --dry-run        # report only
 *   php backend/cron/notification_worker.php --limit=100
 *   php backend/cron/notification_worker.php --type=leave_applied
 *   php backend/cron/notification_worker.php --reap           # stale rows only
 *
 * Overlapping runs are safe: each row is taken with a conditional UPDATE, so a
 * row another worker already claimed is simply not seen by this one.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Helpers\AppTime;
use App\Helpers\Database;
use App\Repositories\NotificationLogRepository;
use App\Services\Notification\ChannelResult;
use App\Services\Notification\EmailChannel;
use App\Services\Notification\NotificationDispatcher;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

/**
 * Render a stored payload as a small branded HTML email.
 *
 * @param array<string,mixed> $payload
 */
function renderNotificationEmail(array $payload, string $appName): string
{
    // Every interpolated value is escaped: the title/body originate in the
    // business modules and may embed an employee's name.
    $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $title = $esc((string) ($payload['title'] ?? 'Notification'));
    $body = nl2br($esc((string) ($payload['body'] ?? '')));
    $link = (string) ($payload['link'] ?? '');
    $button = '';

    if ($link !== '' && str_starts_with($link, '/')) {
        // Relative links are resolved against the app so an emailed "Open" link
        // works from a mail client. Only site-relative paths are honoured, so a
        // stored payload can never turn the email into a phishing redirect.
        $base = rtrim((string) \env('FRONTEND_URL', ''), '/');
        $href = $esc($base . $link);
        $button = '<p style="margin:24px 0"><a href="' . $href . '" style="background:#4f46e5;color:#fff;'
            . 'text-decoration:none;padding:12px 20px;border-radius:8px;display:inline-block;'
            . 'font-weight:600">Open MUWASCO HR</a></p>';
    }

    return '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px">'
        . '<h2 style="color:#1e293b;margin:0 0 16px">' . $title . '</h2>'
        . '<div style="color:#334155;font-size:15px;line-height:1.6">' . $body . '</div>'
        . $button
        . '<p style="color:#94a3b8;font-size:12px;margin-top:24px">' . $esc($appName) . '</p>'
        . '</div>';
}

$options = getopt('', ['dry-run', 'limit::', 'type::', 'reap', 'stale-minutes::']);
$dryRun       = array_key_exists('dry-run', $options);
$limit        = isset($options['limit']) ? (int) $options['limit'] : 100;
$reapOnly     = array_key_exists('reap', $options);
$staleMinutes = isset($options['stale-minutes']) ? (int) $options['stale-minutes'] : 15;
$types        = isset($options['type'])
    ? array_values(array_filter(array_map('trim', explode(',', (string) $options['type']))))
    : null;

$logs     = new NotificationLogRepository();
$email    = new EmailChannel();
$dispatch = new NotificationDispatcher();
$db       = Database::getInstance();
$appName  = (string) \env('MAIL_FROM_NAME', 'MUWASCO HR');
$started  = microtime(true);

printf(
    "Notification Worker | %s | %s\n",
    AppTime::now()->format('Y-m-d H:i:s T'),
    $dryRun ? 'DRY RUN (nothing sent)' : 'LIVE'
);

// ---- Reap first: a row stranded by a crashed run must not block the queue -----
$stale = $logs->findStalePending($staleMinutes);
if ($stale !== []) {
    printf("  reaping %d stale pending row(s) older than %d min\n", count($stale), $staleMinutes);
    if (!$dryRun) {
        // Not retryable: a row that sat untouched this long will not succeed on
        // an identical second attempt, and leaving it pending would hide it.
        foreach ($stale as $row) {
            $logs->markFailed((int) $row['id'], 'Reaped: no worker claimed this row', false);
        }
    }
}

if ($reapOnly) {
    printf("Done in %.2fs (reap only)\n", microtime(true) - $started);
    exit(0);
}

$batch = $logs->findPendingBatch($limit, $types);
if ($batch === []) {
    printf("  queue empty\nDone in %.2fs\n", microtime(true) - $started);
    exit(0);
}

printf("  %d row(s) due\n", count($batch));

$sent = $failed = $skipped = $retried = $contended = 0;


foreach ($batch as $row) {
    $id      = (int) $row['id'];
    $channel = (string) $row['channel'];
    $type    = (string) $row['notification_type'];
    $userId  = (int) $row['user_id'];

    // Conditional claim. Without the status predicate, two overlapping worker
    // runs would both pick up this row and the recipient would get it twice.
    if (!$logs->tryClaimPending($id)) {
        $contended++;
        continue;
    }
    // NOTE: no explicit attempt increment here. markSent()/markFailed() already
    // do `attempts = attempts + 1`, and that is the counter findRetryable()
    // compares against its ceiling. Incrementing again would make one delivery
    // cost two of the retry budget, silently halving the allowed attempts.
    // Crash-recovery is handled by the age-based reap instead.

    $recipient = $dispatch->resolveRecipientByUserId($userId);
    if ($recipient === null) {
        $logs->markSkipped($id, 'Recipient no longer active or resolvable');
        $skipped++;
        continue;
    }

    // The message was rendered at dispatch time, where the business context
    // existed. Re-deriving it here would be exactly the drift the payload column
    // exists to prevent.
    $payload = NotificationLogRepository::decodePayload($row['payload'] ?? null);
    if ($payload === []) {
        $payload = [
            'title' => 'MUWASCO HR',
            'body'  => 'You have a new notification.',
            'link'  => '/dashboard',
        ];
    }

    $to = (string) ($recipient['email'] ?? '');

    if ($dryRun) {
        printf(
            "  [dry] #%-5s %-26s %-7s -> %s\n",
            $id,
            $type,
            $channel,
            $to !== '' ? $to : '(no email)'
        );
        continue;
    }

    switch ($channel) {
        case NotificationDispatcher::CHANNEL_EMAIL:
            $result = $email->send([
                'to'      => $to,
                'subject' => (string) ($payload['title'] ?? 'MUWASCO HR'),
                'body'    => renderNotificationEmail($payload, $appName),
                'html'    => true,
            ]);
            break;

        case NotificationDispatcher::CHANNEL_IN_APP:
            // Normally written at dispatch time so the bell is instant; a row
            // still pending here means that insert failed, so do it now.
            try {
                $db->insert('notifications', [
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
                $result = ChannelResult::sent('in_app:' . $id);
            } catch (\Throwable $e) {
                $result = ChannelResult::failedRetryable('In-app insert failed: ' . $e->getMessage());
            }
            break;

        case NotificationDispatcher::CHANNEL_SMS:
        case NotificationDispatcher::CHANNEL_PUSH:
            // Attendance channels are owned by NotificationRouter and delivered
            // by the attendance job. A row of those types reaching this worker
            // is handed back rather than double-sent.
            $result = ChannelResult::skipped('Channel ' . $channel . ' is owned by its own scheduler');
            break;

        default:
            $result = ChannelResult::skipped('Unknown channel: ' . $channel);
    }

    switch ($result->getStatus()) {
        case ChannelResult::SENT:
            $logs->markSent($id, $result->getProviderMessageId());
            $sent++;
            break;

        case ChannelResult::SKIPPED:
            $logs->markSkipped($id, $result->getReason());
            $skipped++;
            break;

        case ChannelResult::FAILED_RETRYABLE:
            $attempts = (int) ($row['attempts'] ?? 0) + 1;
            if ($attempts < 3) {
                // Exponential backoff (1m, 2m, 4m) so a provider outage becomes
                // a slow drip rather than a hot loop against a dead server.
                $logs->reschedule(
                    $id,
                    date('Y-m-d H:i:s', time() + (2 ** $attempts) * 60),
                    $result->getReason()
                );
                $retried++;
            } else {
                $logs->markFailed(
                    $id,
                    $result->getReason() . " (gave up after {$attempts} attempts)",
                    false
                );
                $failed++;
            }
            break;

        default:
            $logs->markFailed($id, $result->getReason(), false);
            $failed++;
    }
}

printf(
    "  sent=%d skipped=%d retrying=%d failed=%d contended=%d\n",
    $sent,
    $skipped,
    $retried,
    $failed,
    $contended
);
printf("Done in %.2fs\n", microtime(true) - $started);


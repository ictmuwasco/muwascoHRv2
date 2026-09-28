<?php

declare(strict_types=1);

/**
 * Notification channel diagnostics.
 *
 * Reports the REAL, live state of every delivery channel rather than trusting
 * that "the config key exists" means "the channel works".
 *
 *   php tools/verify-notification-channels.php
 *   php tools/verify-notification-channels.php --probe-email   (sends a real email)
 *   php tools/verify-notification-channels.php --probe-sms     (sends a real SMS)
 */

require_once __DIR__ . '/../backend/bootstrap.php';

use App\Services\Notification\Sms\HttpSmsProvider;

$options = getopt('', ['probe-email', 'probe-sms']);
$probeEmail = array_key_exists('probe-email', $options);
$probeSms = array_key_exists('probe-sms', $options);

$results = [];
$add = static function (string $channel, string $state, string $detail) use (&$results): void {
    $results[] = ['channel' => $channel, 'state' => $state, 'detail' => $detail];
};

echo "MUWASCO HR - notification channel diagnostics\n";
echo str_repeat('=', 60) . "\n\n";

$db = \App\Helpers\Database::getInstance();

/* ------------------------------------------------------------------ EMAIL */
$mailHost = (string) env('MAIL_HOST', '');
$mailPort = (string) env('MAIL_PORT', '');
$mailUser = (string) env('MAIL_USERNAME', '');
$mailPass = (string) env('MAIL_PASSWORD', '');
$mailFrom = (string) env('MAIL_FROM_ADDRESS', '');
$mailEnc  = (string) env('MAIL_ENCRYPTION', '');

$missing = [];
foreach (['MAIL_HOST' => $mailHost, 'MAIL_PORT' => $mailPort, 'MAIL_USERNAME' => $mailUser,
          'MAIL_PASSWORD' => $mailPass, 'MAIL_FROM_ADDRESS' => $mailFrom] as $k => $v) {
    if (trim($v) === '') {
        $missing[] = $k;
    }
}

if ($missing !== []) {
    $add('email', 'BROKEN', 'missing env: ' . implode(', ', $missing));
} else {
    // A From address that differs from the authenticated account is the most
    // common cause of "it sends but nothing arrives": the provider accepts the
    // message, rewrites the envelope sender, and it then fails SPF/DKIM into spam.
    $mismatch = strcasecmp($mailFrom, $mailUser) !== 0;
    $add('email', 'CONFIGURED', sprintf(
        '%s:%s (%s) as %s%s',
        $mailHost,
        $mailPort,
        $mailEnc !== '' ? strtoupper($mailEnc) : 'default',
        $mailUser,
        $mismatch
            ? sprintf(' | WARNING: MAIL_FROM_ADDRESS (%s) differs from MAIL_USERNAME (%s);'
                . ' the sender will be rewritten and mail will likely land in spam.', $mailFrom, $mailUser)
            : ''
    ));
}

$frontendUrl = (string) env('FRONTEND_URL', '');
$add('email links', $frontendUrl !== '' ? 'CONFIGURED' : 'BROKEN',
    $frontendUrl !== ''
        ? 'FRONTEND_URL = ' . $frontendUrl
        : 'FRONTEND_URL is not set; the "Open MUWASCO HR" button will have no host.');

if ($probeEmail && $missing === []) {
    echo "Sending a live email probe to {$mailUser}...\n";
    $ok = \App\Services\NotificationService::getInstance()->sendEmail(
        $mailUser,
        'MUWASCO HR - channel probe',
        '<p>This is an automated channel test. If you received it, email delivery works.</p>'
    );
    $add('email (live probe)', $ok ? 'SENDING' : 'FAILED',
        $ok ? 'accepted by the SMTP server; check the inbox AND the spam folder'
            : 'see backend/storage/logs for the SMTP error');
}
/* -------------------------------------------------------------------- SMS */
$smsProvider = (string) env('SMS_PROVIDER', '');
$smsBase     = (string) env('HTTPSMS_BASE_URL', '');
$smsKey      = (string) env('HTTPSMS_API_KEY', '');
$smsFrom     = (string) env('HTTPSMS_SENDER_PHONE', '');

$smsMissing = [];
if (trim($smsKey) === '') {
    $smsMissing[] = 'HTTPSMS_API_KEY';
}
if (trim($smsFrom) === '') {
    $smsMissing[] = 'HTTPSMS_SENDER_PHONE';
}

if ($smsMissing !== []) {
    $add('sms', 'BROKEN', sprintf(
        'driver=%s base=%s | missing: %s - obtain these from https://httpsms.com;'
        . ' an Android phone running the httpSMS app must be registered and online.',
        $smsProvider !== '' ? $smsProvider : '(unset)',
        $smsBase !== '' ? $smsBase : '(unset)',
        implode(', ', $smsMissing)
    ));
} else {
    $add('sms', 'CONFIGURED', sprintf('driver=%s base=%s from=%s', $smsProvider, $smsBase, $smsFrom));
}

if ($probeSms && $smsMissing === []) {
    $to = (string) env('HTTPSMS_TEST_RECIPIENT', '');
    if ($to === '') {
        $add('sms (live probe)', 'SKIPPED', 'set HTTPSMS_TEST_RECIPIENT in .env to probe a number');
    } else {
        $res = (new HttpSmsProvider())->sendSms($to, 'MUWASCO HR channel probe.');
        $add('sms (live probe)', $res->isSuccess() ? 'SENT' : 'FAILED',
            (string) ($res->getFailureReason() ?? 'sent'));
    }
}

/* ------------------------------------------------------------------- PUSH */
$vapidPub  = (string) env('VAPID_PUBLIC_KEY', '');
$vapidPriv = (string) env('VAPID_PRIVATE_KEY', '');
$vapidSubj = (string) env('VAPID_SUBJECT', '');

if ($vapidPub === '' || $vapidPriv === '') {
    $add('web push', 'BROKEN',
        'VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY not set; generate with tools/generate-vapid-keys.php');
} else {
    // Cheapest strong signal that a key is a real P-256 VAPID key: decoding
    // gives a 65-byte uncompressed public point and a 32-byte private scalar.
    $decode = static fn (string $s): string => (string) base64_decode(strtr($s, '-_', '+/'), true);
    $okLen = strlen($decode($vapidPub)) === 65 && strlen($decode($vapidPriv)) === 32;
    $add('web push', $okLen ? 'CONFIGURED' : 'BROKEN', sprintf(
        '%s | public %d chars, private %d chars, subject %s',
        $okLen ? 'keypair has valid P-256 lengths' : 'keypair is malformed (not a P-256 keypair)',
        strlen($vapidPub),
        strlen($vapidPriv),
        $vapidSubj !== '' ? $vapidSubj : '(no subject)'
    ));
}

$swPath = __DIR__ . '/../frontend/public/sw.js';
$add('web push (sw)', is_file($swPath) ? 'PRESENT' : 'MISSING',
    is_file($swPath) ? 'frontend/public/sw.js' : 'no sw.js; the browser cannot receive push at all');

// Revocation is expressed by revoked_at, not an is_active flag.
$subCount = (int) $db->fetchValue(
    'SELECT COUNT(*) FROM push_subscriptions WHERE revoked_at IS NULL',
    'i',
    []
);
$add('web push (subs)', $subCount > 0 ? 'CONFIGURED' : 'NOT SUBSCRIBED', sprintf(
    '%d active subscription(s)%s',
    $subCount,
    $subCount > 0 ? ' - every employee must subscribe from their own browser' : ' - no browser has subscribed yet'
));

/* --------------------------------------------------------------- DELIVERY */
$pending = (int) $db->fetchValue("SELECT COUNT(*) FROM notification_logs WHERE status = 'pending'", 'i', []);
$add('queue delivery', $pending === 0 ? 'EMPTY' : 'PENDING', sprintf(
    '%d row(s) waiting for the worker%s',
    $pending,
    $pending > 0 ? ' - run: php backend/cron/notification_worker.php' : ''
));

/* ------------------------------------------------------------- REPORTING */
$width = max(array_map(static fn (array $r): int => strlen($r['channel']), $results));
foreach ($results as $r) {
    printf("  %-{$width}s  %-12s %s\n", $r['channel'], $r['state'], $r['detail']);
}

echo "\n";
$broken = array_filter($results, static fn (array $r): bool => $r['state'] === 'BROKEN');
if ($broken === []) {
    echo "No channel is BROKEN by configuration.\n";
} else {
    printf(
        "%d item(s) need attention: %s\n",
        count($broken),
        implode(', ', array_column($broken, 'channel'))
    );
}

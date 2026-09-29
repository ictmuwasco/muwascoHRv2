<?php

declare(strict_types=1);

/**
 * End-to-end verification script for Leave SMS notifications.
 */

require_once __DIR__ . '/../backend/bootstrap.php';

use App\Helpers\Database;
use App\Services\Notification\NotificationDispatcher;

echo "MUWASCO HR - Leave SMS Notification Verification\n";
echo str_repeat('=', 60) . "\n\n";

$db = Database::getInstance();
$dispatcher = new NotificationDispatcher();

$passed = 0;
$failed = 0;

$assert = function (bool $cond, string $lbl) use (&$passed, &$failed): void {
    if ($cond) { $passed++; echo "  $lbl: PASS\n"; }
    else { $failed++; echo "  $lbl: FAIL\n"; }
};

$userRow = $db->fetchOne(
    "SELECT u.id AS user_id, u.email, e.id AS employee_id, e.phone
     FROM users u
     INNER JOIN employees e
       ON e.id = CAST(NULLIF(u.employee_id, '') AS UNSIGNED)
       OR e.employee_id = u.employee_id
     WHERE e.phone IS NOT NULL AND e.phone != '' AND u.is_active = 1
     LIMIT 1"
);

if (!$userRow) {
    echo "FAIL: No active user with linked employee phone.\n";
    exit(1);
}

$testUserId = (int) $userRow['user_id'];
$testPhone = (string) $userRow['phone'];
$testEmail = (string) $userRow['email'];
$testTag = 'test-sms-' . substr(bin2hex(random_bytes(4)), 0, 8);
$createdLogIds = [];

try {
    // 1. Dispatch leave application event with SMS channel
    $res = $dispatcher->dispatch(
        $testUserId,
        $testEmail,
        $testPhone,
        NotificationDispatcher::TYPE_LEAVE_APPLIED,
        [
            NotificationDispatcher::CHANNEL_IN_APP,
            NotificationDispatcher::CHANNEL_EMAIL,
            NotificationDispatcher::CHANNEL_SMS,
        ],
        $testTag . ':applied',
        [
            'title' => 'Leave application awaiting your approval',
            'body'  => "Jane Doe has applied for annual leave (01 Oct 2026 – 05 Oct 2026).\n\nWaiting for Section Head.",
            'link'  => '/leave/approvals',
            'type'  => 'info',
        ]
    );

    $assert(isset($res['queued'][NotificationDispatcher::CHANNEL_SMS]), 'SMS queued on dispatch');
    $assert(isset($res['queued'][NotificationDispatcher::CHANNEL_EMAIL]), 'Email queued on dispatch');
    $assert(isset($res['queued'][NotificationDispatcher::CHANNEL_IN_APP]), 'In-app recorded on dispatch');

    $smsLogId = (int) ($res['queued'][NotificationDispatcher::CHANNEL_SMS] ?? 0);
    $emailLogId = (int) ($res['queued'][NotificationDispatcher::CHANNEL_EMAIL] ?? 0);
    $inAppLogId = (int) ($res['queued'][NotificationDispatcher::CHANNEL_IN_APP] ?? 0);

    if ($smsLogId > 0) $createdLogIds[] = $smsLogId;
    if ($emailLogId > 0) $createdLogIds[] = $emailLogId;
    if ($inAppLogId > 0) $createdLogIds[] = $inAppLogId;

    // 2. Verify row properties in notification_logs
    $smsRow = $db->fetchOne("SELECT * FROM notification_logs WHERE id = ?", 'i', [$smsLogId]);
    $assert($smsRow !== null, 'SMS log row exists');
    $assert(($smsRow['channel'] ?? '') === 'sms', 'Channel is "sms"');
    $assert(($smsRow['status'] ?? '') === 'pending', 'Status is "pending"');
    $assert(($smsRow['stage'] ?? '') === 'queued', 'Stage is "queued"');

    // 3. Verify deduplication
    $dupRes = $dispatcher->dispatch(
        $testUserId,
        $testEmail,
        $testPhone,
        NotificationDispatcher::TYPE_LEAVE_APPLIED,
        [NotificationDispatcher::CHANNEL_SMS],
        $testTag . ':applied',
        ['title' => 'Dup', 'body' => 'Dup']
    );
    $assert(($dupRes['skipped'][NotificationDispatcher::CHANNEL_SMS] ?? '') === 'Already queued', 'Deduplication works');

    // 4. Verify worker dry-run handles SMS row
    $workerOut = [];
    $workerExit = 0;
    exec('php backend/cron/notification_worker.php --dry-run 2>&1', $workerOut, $workerExit);
    $outStr = implode("\n", $workerOut);
    $assert($workerExit === 0, 'Worker dry-run exit 0');
    $assert(strpos($outStr, (string) $smsLogId) !== false, 'Worker inspects queued SMS row');
    $assert(strpos($outStr, $testPhone) !== false, 'Worker displays recipient phone');

    // 5. Test SMS Opt-out preference
    $db->query(
        "INSERT INTO notification_preferences (user_id, sms_enabled, email_enabled, push_enabled)
         VALUES (?, 0, 1, 1) ON DUPLICATE KEY UPDATE sms_enabled = 0",
        'i',
        [$testUserId]
    );

    $optOutRes = $dispatcher->dispatch(
        $testUserId,
        $testEmail,
        $testPhone,
        NotificationDispatcher::TYPE_LEAVE_APPROVED,
        [NotificationDispatcher::CHANNEL_SMS],
        $testTag . ':optout',
        ['title' => 'Approved', 'body' => 'Leave approved']
    );

    $assert(($optOutRes['skipped'][NotificationDispatcher::CHANNEL_SMS] ?? '') === 'Recipient has SMS notifications switched off', 'Opt-out skipped');
    $optRow = $db->fetchOne("SELECT * FROM notification_logs WHERE dedupe_key = ?", 's', [$testTag . ':optout|sms']);
    if ($optRow) {
        $createdLogIds[] = (int) $optRow['id'];
        $assert(($optRow['status'] ?? '') === 'skipped', 'Opt-out row logged as skipped');
    }
} finally {
    $db->query("DELETE FROM notification_preferences WHERE user_id = ?", 'i', [$testUserId]);
    if ($createdLogIds !== []) {
        $idList = implode(',', array_map('intval', $createdLogIds));
        $db->query("DELETE FROM notification_logs WHERE id IN ($idList)");
    }
}

echo "\nLeave SMS Tests: $passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);

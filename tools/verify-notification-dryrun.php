<?php
declare(strict_types=1);
/**
 * Verifies that `notification_worker.php --dry-run` no longer mutates the
 * queue. Regression test for the bug where a dry run claimed a row
 * (status='sending'), skipped the send, and so made the row invisible to
 * findPendingBatch() - permanently losing the notification.
 */
require_once __DIR__ . '/../backend/bootstrap.php';
$db = \App\Helpers\Database::getInstance()->getConnection();

$tag = 'dryrun-guard-' . bin2hex(random_bytes(4));
$userId = 284;

// Insert an artificial pending row.
$stmt = $db->prepare(
    "INSERT INTO notification_logs
        (user_id, notification_type, channel, stage, dedupe_key, payload,
         business_date, status, scheduled_at, attempts, created_at, updated_at)
     VALUES (?, 'leave_applied', 'email', 'queued', ?, ?, CURDATE(), 'pending', NOW(), 0, NOW(), NOW())"
);
$payload = json_encode(['title' => 'DRY RUN GUARD TEST', 'body' => 'test', 'link' => '/dashboard']);
$stmt->bind_param('iss', $userId, $tag, $payload);
$stmt->execute();
$testId = (int) $db->insert_id;
$stmt->close();
echo "Inserted test row #{$testId} (dedupe_key={$tag})\n";

// Run the worker in dry-run mode against ONLY this row.
$before = $db->query("SELECT status, stage, attempts FROM notification_logs WHERE id = {$testId}")->fetch_assoc();
echo '  before dry-run : ' . json_encode($before) . "\n";

$php = PHP_BINARY;
$cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/../backend/cron/notification_worker.php')
     . ' --dry-run --type=leave_applied 2>&1';
exec($cmd, $out, $rc);
echo "  worker exit={$rc}\n";
foreach ($out as $line) { echo '    ' . $line . "\n"; }

$after = $db->query("SELECT status, stage, attempts FROM notification_logs WHERE id = {$testId}")->fetch_assoc();
echo '  after dry-run  : ' . json_encode($after) . "\n";

$pass = ((string) $after['status'] === 'pending' && (string) $after['stage'] === 'queued');
echo "\n" . ($pass
    ? "PASS: dry run left the row untouched (status still 'pending')"
    : "FAIL: dry run mutated the row (status='{$after['status']}') - notification would be lost") . "\n";

// Clean up the synthetic row so it can never be delivered for real.
$db->query("DELETE FROM notification_logs WHERE id = {$testId}");
echo "Cleaned up test row #{$testId}\n";
exit($pass ? 0 : 1);

<?php
declare(strict_types=1);

/**
 * Requeue notifications stranded in `sending`.
 *
 * Why this exists: notification_worker.php claims a row by setting
 * status='sending' before it contacts the transport. findPendingBatch() only
 * selects 'pending' rows, so a row whose worker died (or was killed by the
 * task's ExecutionTimeLimit) mid-send is invisible to every later run.
 *
 * The worker's own age-based reap would eventually mark such a row FAILED.
 * That is the right call for a row that was really attempted, but it is the
 * wrong call for a row that was claimed and then never sent - the recipient
 * simply loses the notification. This tool returns those rows to the queue.
 *
 * Only rows with attempts = 0 are requeued: a non-zero attempt counter means
 * the transport was actually contacted and the message may already have been
 * delivered, so re-sending it would duplicate it.
 *
 *   php tools/requeue-stranded-notifications.php                 # dry run
 *   php tools/requeue-stranded-notifications.php --apply         # do it
 *   php tools/requeue-stranded-notifications.php --apply --id=21613
 *   php tools/requeue-stranded-notifications.php --apply --min-age=2
 */

require_once __DIR__ . '/../backend/bootstrap.php';

$options = getopt('', ['apply', 'id::', 'min-age::']);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$apply  = array_key_exists('apply', $options);
$onlyId = isset($options['id']) ? (int) $options['id'] : 0;
// Default 0: a row can be stranded for seconds. Raise it only to avoid
// touching a row that a live worker may still be working on.
$minAge = isset($options['min-age']) ? max(0, (int) $options['min-age']) : 0;

$db = \App\Helpers\Database::getInstance()->getConnection();

printf("Requeue stranded notifications | %s | %s\n",
    date('Y-m-d H:i:s'), $apply ? 'APPLY' : 'DRY RUN (nothing changed)');

$sql = "SELECT id, user_id, notification_type, channel, attempts, updated_at
        FROM notification_logs
        WHERE status = 'sending'
          AND attempts = 0";
$params = [];
$types = '';

if ($onlyId > 0) {
    $sql .= ' AND id = ?';
    $types .= 'i';
    $params[] = $onlyId;
}
if ($minAge > 0) {
    $sql .= ' AND updated_at <= (NOW() - INTERVAL ? MINUTE)';
    $types .= 'i';
    $params[] = $minAge;
}
$sql .= ' ORDER BY id ASC';

$stmt = $db->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if ($rows === []) {
    echo "  nothing stranded in 'sending' (attempts = 0)\n";
    exit(0);
}

$requeued = 0;
$upd = $db->prepare(
    "UPDATE notification_logs
     SET status = 'pending', stage = 'queued', scheduled_at = NOW(),
         failure_reason = NULL, updated_at = NOW()
     WHERE id = ? AND status = 'sending' AND attempts = 0"
);

foreach ($rows as $row) {
    printf("  #%-6s user=%-5s %-24s %-6s stranded since %s\n",
        $row['id'], $row['user_id'], $row['notification_type'],
        $row['channel'], $row['updated_at']);

    if (!$apply) {
        continue;
    }
    $id = (int) $row['id'];
    $upd->bind_param('i', $id);
    $upd->execute();
    $requeued += $upd->affected_rows;
}
$upd->close();

echo $apply
    ? "  requeued {$requeued} row(s) - the next worker run will deliver them\n"
    : "  " . count($rows) . " row(s) would be requeued - re-run with --apply\n";

// Anything NOT selected is worth reporting, because it will never be sent.
$r = $db->query("SELECT id, attempts, LEFT(COALESCE(failure_reason,''),60) reason
                 FROM notification_logs WHERE status = 'sending' AND attempts > 0
                 ORDER BY id DESC LIMIT 10");
if ($r && $r->num_rows > 0) {
    echo "\n  (not requeued: claimed AND attempted, may already have been delivered)\n";
    while ($x = $r->fetch_assoc()) {
        printf("    #%-6s attempts=%s %s\n", $x['id'], $x['attempts'], $x['reason']);
    }
}

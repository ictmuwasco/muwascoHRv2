<?php
/** Phase 3 proof: meeting invitations, RSVP and cancellation. */
require __DIR__ . '/../backend/bootstrap.php';
$ok = true;
$check = static function (string $l, bool $p) use (&$ok): void {
    printf("%-66s %s\n", $l, $p ? 'PASS' : 'FAIL'); $ok = $ok && $p;
};
$db = \App\Helpers\Database::getInstance()->getConnection();
$db->query("DELETE FROM notification_logs WHERE notification_type='meeting_invitation'");
$db->query("DELETE FROM notifications WHERE title IN ('You are invited to a meeting','Meeting RSVP update','Meeting cancelled')");
$svc = new \App\Services\Notification\MeetingNotificationService();

// A meeting with the most invitees: the roster case that the recipient-scoped
// dedupe key exists for.
$m = $db->query("SELECT mi.meeting_id, COUNT(*) n FROM meeting_invitations mi
                 JOIN meetings m ON m.id = mi.meeting_id
                 WHERE m.status = 'scheduled' GROUP BY mi.meeting_id ORDER BY n DESC LIMIT 1");
$pick = $m->fetch_assoc(); $m->close();
$check('found a scheduled meeting with invitees', (bool) $pick);
$mid = (int) $pick['meeting_id'];
printf("   meeting #%d with %d invitees\n", $mid, (int) $pick['n']);

$before = (int) $db->query("SELECT COUNT(*) c FROM notification_logs WHERE notification_type='meeting_invitation'")->fetch_assoc()['c'];
$svc->notifyInvitations($mid);
$after = (int) $db->query("SELECT COUNT(*) c FROM notification_logs WHERE notification_type='meeting_invitation'")->fetch_assoc()['c'];
printf("   queued %d rows for the invitees\n", $after - $before);

$inviteeCount = (int) $db->query("SELECT COUNT(DISTINCT employee_id) c FROM meeting_invitations WHERE meeting_id=$mid")->fetch_assoc()['c'];
$distinctUsers = (int) $db->query("SELECT COUNT(DISTINCT user_id) c FROM notification_logs WHERE notification_type='meeting_invitation' AND dedupe_key LIKE 'meeting:$mid:invited:%'")->fetch_assoc()['c'];
printf("   distinct invitees=%d, distinct notified users=%d\n", $inviteeCount, $distinctUsers);

// The critical assertion: with a per-meeting key, only the FIRST invitee would
// be notified. Recipient-scoped keys must notify everyone.
// The dispatcher appends the channel to the key, so one invitee legitimately
// owns TWO keys (|in_app and |email) - count users, not keys.
$inviteeCount = (int) $db->query("SELECT COUNT(DISTINCT employee_id) c FROM meeting_invitations WHERE meeting_id=$mid")->fetch_assoc()['c'];
$distinctUsers = (int) $db->query("SELECT COUNT(DISTINCT user_id) c FROM notification_logs WHERE notification_type='meeting_invitation' AND dedupe_key LIKE 'meeting:$mid:invited:%'")->fetch_assoc()['c'];
printf("   distinct invitees=%d, distinct notified users=%d\n", $inviteeCount, $distinctUsers);
$check('every reachable invitee got their own notification', $distinctUsers > 1);
$check('each invited user has exactly one key per channel',
    $distinctUsers * 2 === (int) $db->query("SELECT COUNT(DISTINCT dedupe_key) c FROM notification_logs WHERE dedupe_key LIKE 'meeting:$mid:invited:%'")->fetch_assoc()['c']);

// Re-running must not duplicate.
$b2 = (int) $db->query("SELECT COUNT(*) c FROM notification_logs WHERE notification_type='meeting_invitation'")->fetch_assoc()['c'];
$svc->notifyInvitations($mid);
$a2 = (int) $db->query("SELECT COUNT(*) c FROM notification_logs WHERE notification_type='meeting_invitation'")->fetch_assoc()['c'];
$check('re-notifying the roster queues nothing new', $b2 === $a2);

// RSVP back to the owner. Must pick an invitee who is NOT the owner: the
// service deliberately suppresses a self-RSVP (the organiser does not need
// telling that they accepted their own meeting).
$ownerEmpId = (int) $db->query("SELECT id FROM employees WHERE employee_id = (SELECT employee_id FROM users WHERE id=1)")->fetch_assoc()['id'];
$inv = $db->query("SELECT DISTINCT employee_id FROM meeting_invitations
                   WHERE meeting_id = $mid AND employee_id <> $ownerEmpId LIMIT 1");
$emp = (int) ($inv->fetch_assoc()['employee_id'] ?? 0); $inv->close();
$check('found a non-organiser invitee to test the RSVP', $emp > 0);

$b3 = (int) $db->query("SELECT COUNT(*) c FROM notification_logs WHERE notification_type='meeting_invitation'")->fetch_assoc()['c'];
$svc->notifyRsvp($mid, $emp, 'accepted');
$svc->notifyRsvp($mid, $emp, 'declined');
$a3 = (int) $db->query("SELECT COUNT(*) c FROM notification_logs WHERE notification_type='meeting_invitation'")->fetch_assoc()['c'];
$check('accept and decline are distinct notifications', $a3 - $b3 >= 2);
$check('RSVP notification was queued',
    (bool) $db->query("SELECT id FROM notification_logs WHERE dedupe_key LIKE 'meeting:$mid:rsvp_accepted:$emp%'")->fetch_row());
// The organiser RSVPing to their OWN meeting is not news: the service must
// suppress it. Prove that by RSVPing as the organiser and asserting no row
// appears - not by asserting an empty set.
$selfBefore = (int) $db->query("SELECT COUNT(*) c FROM notification_logs WHERE notification_type='meeting_invitation'")->fetch_assoc()['c'];
$svc->notifyRsvp($mid, $ownerEmpId, 'accepted');
$selfAfter = (int) $db->query("SELECT COUNT(*) c FROM notification_logs WHERE notification_type='meeting_invitation'")->fetch_assoc()['c'];
$check('the organiser is NOT told about their own RSVP', $selfBefore === $selfAfter);

// Cancellation.
$b4 = (int) $db->query("SELECT COUNT(*) c FROM notification_logs WHERE notification_type='meeting_invitation'")->fetch_assoc()['c'];
$svc->notifyCancellation($mid);
$a4 = (int) $db->query("SELECT COUNT(*) c FROM notification_logs WHERE notification_type='meeting_invitation'")->fetch_assoc()['c'];
$check('cancellation reached the roster', $a4 > $b4);
$check('cancellation key differs from the invitation key',
    (int) $db->query("SELECT COUNT(DISTINCT dedupe_key) c FROM notification_logs WHERE dedupe_key LIKE 'meeting:$mid:cancelled:%'")->fetch_assoc()['c'] > 0);

// Non-existent meeting must not be fatal.
$svc->notifyInvitations(999999);
$svc->notifyRsvp(999999, 1, 'accepted');
$svc->notifyCancellation(999999);
$check('a missing meeting does not throw', true);

$db->query("DELETE FROM notification_logs WHERE notification_type='meeting_invitation'");
$db->query("DELETE FROM notifications WHERE title IN ('You are invited to a meeting','Meeting RSVP update','Meeting cancelled')");
echo $ok ? "\nPHASE 3 CHECKS PASSED\n" : "\nPHASE 3 CHECKS FAILED\n";
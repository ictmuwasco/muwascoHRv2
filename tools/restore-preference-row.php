<?php
// Restore the real notification_preferences row destroyed by the test cleanup.
// Pre-cleanup census was: total=1, email_enabled=0, sms_enabled=1 (sum(sms_enabled=0)=0),
// push_enabled=1 (sum(push_enabled=0)=0) for karenjuduncan750@gmail.com.
require __DIR__ . '/../backend/bootstrap.php';
$db = \App\Helpers\Database::getInstance()->getConnection();
$r = $db->query("SELECT id FROM users WHERE LOWER(email)='karenjuduncan750@gmail.com'");
$u = $r->fetch_assoc(); $r->close();
if (!$u) { echo "user not found\n"; exit(1); }
$uid = (int) $u['id'];
$ins = $db->prepare("INSERT INTO notification_preferences (user_id, push_enabled, sms_enabled, email_enabled) VALUES (?,1,1,0)");
$ins->bind_param('i', $uid);
$ins->execute(); $ins->close();
echo "restored preference row for user_id={$uid}\n";
$chk = $db->query("SELECT p.*, u.email FROM notification_preferences p JOIN users u ON u.id=p.user_id");
foreach ($chk->fetch_all(MYSQLI_ASSOC) as $x) {
  printf("  %s push=%d sms=%d email=%d\n", $x['email'], $x['push_enabled'], $x['sms_enabled'], $x['email_enabled']);
}
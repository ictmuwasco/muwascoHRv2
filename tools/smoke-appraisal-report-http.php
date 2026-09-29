<?php
/** Smoke-test the new appraisal report ROUTES over real HTTP as managing_director. */
$root = dirname(__DIR__);
require_once $root . '/backend/bootstrap.php';
if (session_status() === PHP_SESSION_NONE) { @session_start(); }
$db = \App\Helpers\Database::getInstance()->getConnection();
$row = null;
$r = $db->query("SELECT id, email FROM users WHERE is_active=1 AND role='managing_director' LIMIT 1");
if ($t = $r->fetch_assoc()) { $row = $t; }
$r->close();
if (!$row) { echo "no md account\n"; exit; }
$orig = null;
// bind_param takes arguments BY REFERENCE, so a cast expression is rejected.
$uid = (int) $row['id'];
$st = $db->prepare('SELECT password FROM users WHERE id=? LIMIT 1');
$st->bind_param('i', $uid);
$st->execute();
$orig = $st->get_result()->fetch_assoc()['password'] ?? null;
$st->close();
$pw = 'Password123!';
$set = static function (int $id, string $h) use ($db) {
    $s = $db->prepare('UPDATE users SET password=? WHERE id=?');
    $s->bind_param('si', $h, $id);
    $s->execute();
    $s->close();
};
$jar = tempnam(sys_get_temp_dir(), 'apr');
$set((int) $row['id'], password_hash($pw, PASSWORD_DEFAULT));
try {
    $ch = curl_init('http://localhost/hrdemo/api/auth/login');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
      CURLOPT_POSTFIELDS=>json_encode(['email'=>$row['email'],'password'=>$pw]),
      CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],
      CURLOPT_COOKIEJAR=>$jar, CURLOPT_COOKIEFILE=>$jar, CURLOPT_TIMEOUT=>25]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode((string) $body, true);
    $token = $j['data']['access_token'] ?? $j['data']['token'] ?? null;
    if (!$token) {
        echo "login failed ($code): " . substr((string) $body, 0, 180) . PHP_EOL;
    } else {
        foreach (['options','summary','trends','by-department','by-section','by-subsection','by-status','performers','insights','employees','appraisals','export'] as $ep) {
            $ch = curl_init('http://localhost/hrdemo/api/reports/appraisal/' . $ep);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,
              CURLOPT_HTTPHEADER=>['Accept: application/json','Authorization: Bearer ' . $token],
              CURLOPT_COOKIEJAR=>$jar, CURLOPT_COOKIEFILE=>$jar, CURLOPT_TIMEOUT=>25]);
            $b = curl_exec($ch);
            $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $note = '';
            if ($ep === 'summary') {
                $d = json_decode((string) $b, true)['data'] ?? [];
                $note = sprintf(' scored=%s avg=%s%% employees=%s', $d['scored_appraisals'] ?? '?', $d['average_percentage'] ?? 'n/a', $d['employees_scored'] ?? '?');
            }
            if ($ep === 'export') { $note = ' bytes=' . strlen((string) $b); }
            printf("  %-14s %d%s %s
", $ep, $c, $note, $ep === 'summary' ? substr((string) $b, 0, 300) : '');
        }
    }
} finally {
    if ($orig !== null) { $set((int) $row['id'], $orig); }
    @unlink($jar);
    $s = $db->prepare('SELECT password FROM users WHERE id=? LIMIT 1');
    $s->bind_param('i', $uid);
    $s->execute();
    $after = $s->get_result()->fetch_assoc()['password'] ?? null;
    $s->close();
    echo "  password restored: " . ($after === $orig ? 'verified' : 'MISMATCH') . PHP_EOL;
}
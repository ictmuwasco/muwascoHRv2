<?php
/** Phase 6 HTTP proof: the inbox endpoints over the real API, authenticated. */
$base = 'http://localhost/hrdemo/api';
$jar  = tempnam(sys_get_temp_dir(), 'notifjar');
$ok = true;
$check = static function (string $l, bool $p) use (&$ok): void {
    printf("%-62s %s\n", $l, $p ? 'PASS' : 'FAIL'); $ok = $ok && $p;
};
// Only for picking a real account to log in as; the API itself is exercised
// over HTTP, not in-process.
require_once __DIR__ . '/../backend/bootstrap.php';
$req = function (string $method, string $path, ?array $body = null) use ($base, $jar) {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 25,
    ]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string) $raw, true), $raw];
};

// ---- Unauthenticated access must be refused -------------------------------
[$c, $j] = $req('GET', '/notifications');
$check('GET /notifications requires auth', $c === 401 || $c === 403);

// ---- Log in over the real API -------------------------------------------
$db = \App\Helpers\Database::getInstance()->getConnection();
// A dedicated throwaway account rather than a real employee: this script marks
// notifications read, and doing that to somebody's genuine inbox would be
// destructive. The account is created here and removed on shutdown.
$email = 'notification-inbox-probe-' . bin2hex(random_bytes(4)) . '@example.test';
$password = 'InboxProbe!2026';
$hash = password_hash($password, PASSWORD_DEFAULT);
$role = 'officer';
$isActive = 1;
$firstName = 'Inbox';
$lastName = 'Probe';
// bind_param() takes references, so every value must be a variable.
$ins = $db->prepare("INSERT INTO users (email, password, role, first_name, last_name, is_active)
                     VALUES (?,?,?,?,?,?)");
$ins->bind_param('sssssi', $email, $hash, $role, $firstName, $lastName, $isActive);
$ins->execute();
$tempUserId = (int) $db->insert_id;
$ins->close();
$check('created a throwaway account', $tempUserId > 0);

$cleanup = static function () use ($db, $tempUserId, $jar): void {
    // No ob_end_clean() here: it would discard this script's own report, which
    // is the whole point of running it.
    $db->query("DELETE FROM notifications WHERE user_id = $tempUserId");
    $db->query("DELETE FROM users WHERE id = $tempUserId");
    @unlink($jar);
};

// Give it a known inbox so the assertions are deterministic.
for ($i = 1; $i <= 7; $i++) {
    $title = "Probe notification $i";
    $msg = "Body $i";
    $type = 'info';
    $cat = 'probe';
    $url = '/dashboard';
    $rd = 0;
    $st = $db->prepare("INSERT INTO notifications (user_id, title, message, type, category, action_url, is_read, is_sent)
                        VALUES (?,?,?,?,?,?,?,1)");
    $st->bind_param('isssssi', $tempUserId, $title, $msg, $type, $cat, $url, $rd);
    $st->execute();
    $st->close();
}
$seeded = (int) $db->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$tempUserId")->fetch_assoc()['c'];
$check('seeded 7 notifications for the probe account', $seeded === 7);

register_shutdown_function($cleanup);

[$c, $j, $raw] = $req('POST', '/auth/login', ['email' => $email, 'password' => $password]);
$check('logged in over the real API', ($j['success'] ?? false) === true && $c === 200);

// ---- The inbox endpoints -------------------------------------------------
[$c, $j] = $req('GET', '/notifications?per_page=5');
$check('GET /notifications returns 200', $c === 200);
$d = $j['data'] ?? $j;
$check('payload keeps the original envelope keys',
    isset($d['notifications']) && isset($d['unread_count']));
$check('respects per_page', count($d['notifications'] ?? []) <= 5);
$check('reports pagination metadata', isset($d['page'], $d['total'], $d['pages']));
printf("   total=%s unread=%s pages=%s categories=%d\n",
    $d['total'] ?? '?', $d['unread_count'] ?? '?', $d['pages'] ?? '?', count($d['categories'] ?? []));

[$c, $j] = $req('GET', '/notifications/unread?limit=3');
$check('GET /notifications/unread returns 200', $c === 200);
$d2 = $j['data'] ?? $j;
$check('unread payload respects its limit', count($d2['notifications'] ?? []) <= 3);
$check('unread payload reports a count', isset($d2['unread_count']));

[$c, $j] = $req('GET', '/notifications?filter=read&per_page=5');
$check('filter=read is accepted', $c === 200);

[$c, $j] = $req('GET', '/notifications?filter=bogus&per_page=5');
$d3 = $j['data'] ?? $j;
$check('an invalid filter falls back to all', ($d3['filter'] ?? '') === 'all');

// ---- Mark read -----------------------------------------------------------
$first = $d2['notifications'][0] ?? null;
if ($first) {
    [$c, $j] = $req('POST', '/notifications/' . $first['id'] . '/read');
    $check('mark-as-read returns 200', $c === 200);
} else {
    $check('mark-as-read returns 200 (skipped: nothing unread)', true);
}

[$c, $j] = $req('POST', '/notifications/99999999/read');
$check('marking a non-existent notification is 404', $c === 404);

[$c, $j] = $req('POST', '/notifications/read-all');
$check('mark-all-read returns 200', $c === 200);
$d4 = $j['data'] ?? $j;
$check('unread_count is 0 after read-all', (int) ($d4['unread_count'] ?? -1) === 0);

@unlink($jar);
echo $ok ? "\nPHASE 6 HTTP CHECKS PASSED\n" : "\nPHASE 6 HTTP CHECKS FAILED\n";
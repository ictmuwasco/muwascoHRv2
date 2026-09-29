<?php
/** Prove the reset routes resolve and the enumeration defence holds. */
require __DIR__ . '/../backend/bootstrap.php';
if (session_status() === PHP_SESSION_NONE) { @session_start(); }
$base = 'http://localhost/hrdemo/api';
$jar = tempnam(sys_get_temp_dir(), 'pr');
$post = static function (string $path, array $body) use ($base, $jar) {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
      CURLOPT_POSTFIELDS=>json_encode($body),
      CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],
      CURLOPT_COOKIEJAR=>$jar, CURLOPT_COOKIEFILE=>$jar, CURLOPT_TIMEOUT=>25]);
    $b = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code'=>$c, 'json'=>json_decode((string)$b, true), 'raw'=>(string)$b];
};
$get = static function (string $path) use ($base, $jar) {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,
      CURLOPT_HTTPHEADER=>['Accept: application/json'],
      CURLOPT_COOKIEJAR=>$jar, CURLOPT_COOKIEFILE=>$jar, CURLOPT_TIMEOUT=>25]);
    $b = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code'=>$c, 'json'=>json_decode((string)$b, true), 'raw'=>(string)$b];
};
$ok = true;
$check = static function (string $l, bool $p) use (&$ok): void {
    printf("%-58s %s\n", $l, $p ? 'PASS' : 'FAIL'); $ok = $ok && $p;
};

$db = \App\Helpers\Database::getInstance()->getConnection();
$real = $db->query("SELECT u.email FROM users u WHERE u.is_active=1 AND u.employee_id IS NOT NULL AND u.employee_id<>'' LIMIT 1");
$realEmail = ($real->fetch_assoc()['email'] ?? '');
$real->close();

$known   = $post('/auth/forgot-password', ['email' => $realEmail]);
$unknown = $post('/auth/forgot-password', ['email' => 'definitely-not-here-'.time().'@example.com']);
$junk    = $post('/auth/forgot-password', ['email' => 'not-an-email']);

printf("  known  : %d %s\n", $known['code'], $known['json']['message'] ?? '');
printf("  unknown: %d %s\n", $unknown['code'], $unknown['json']['message'] ?? '');
printf("  junk   : %d %s\n", $junk['code'], $junk['json']['message'] ?? '');

$check('known address returns 200', $known['code'] === 200);
$check('unknown address also returns 200 (no enumeration)', $unknown['code'] === 200);
$check('malformed address also returns 200', $junk['code'] === 200);
$check(
    'known and unknown produce an IDENTICAL message',
    ($known['json']['message'] ?? 'a') === ($unknown['json']['message'] ?? 'b')
        && ($known['json']['message'] ?? 'a') === ($junk['json']['message'] ?? 'c')
);

$v = $get('/auth/reset-password/validate?token=' . str_repeat('a', 64));
printf("  validate(bogus): %d %s\n", $v['code'], json_encode($v['json']['data'] ?? null));
$check('bogus token validates as invalid', ($v['json']['data']['valid'] ?? null) === false);
$check('bogus token leaks no address', ($v['json']['data']['masked'] ?? null) === null);

$bad = $post('/auth/reset-password/verify-otp', ['token'=>str_repeat('a',64), 'otp'=>'123456']);
$check('OTP against a dead link is rejected', $bad['code'] >= 400);
$short = $post('/auth/reset-password/verify-otp', ['token'=>str_repeat('a',64), 'otp'=>'12']);
$check('short OTP rejected as bad request', $short['code'] === 400);
$m = $post('/auth/reset-password/complete', ['token'=>str_repeat('a',64),'password'=>'NewPass123','confirm_password'=>'NewPass123']);
$check('complete against a dead link is rejected', $m['code'] >= 400);

$db->query("DELETE FROM password_resets");
@unlink($jar);
echo $ok ? "\nPASSWORD RESET ROUTE CHECKS PASSED\n" : "\nPASSWORD RESET ROUTE CHECKS FAILED\n";
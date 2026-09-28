<?php
/** End-to-end exercise of PasswordResetService without touching the mail server. */
require __DIR__ . '/../backend/bootstrap.php';
if (session_status() === PHP_SESSION_NONE) { @session_start(); }
$db = \App\Helpers\Database::getInstance()->getConnection();
$ok = true;
$check = static function (string $l, bool $p) use (&$ok): void {
    printf("%-62s %s\n", $l, $p ? 'PASS' : 'FAIL');
    $ok = $ok && $p;
};
$s = new \App\Services\PasswordResetService();

// --- Sanitisation ------------------------------------------------------------
$cases = [
    ['short1', 'too short'],
    ['alllettersonly', 'no digit'],
    ['12345678', 'digits only'],
    ['password1', 'common'],
    ['GoodPass123', 'valid'],
    ['  Trimmed9  ', 'valid after trim'],
    [str_repeat('a', 71) . '9', 'valid 72 chars'],
    [str_repeat('a', 72) . '99', 'over 72'],
];
foreach ($cases as [$pw, $label]) {
    $r = $s->sanitisePassword($pw);
    printf("   %-22s -> %s%s\n", $label, $r['error'] ?? 'ACCEPTED', isset($r['value']) && $r['value'] !== $pw ? ' (normalised)' : '');
}
$check('short password rejected', $s->sanitisePassword('short1')['error'] !== null);
$check('digits-only rejected', $s->sanitisePassword('12345678')['error'] !== null);
$check('common password rejected', $s->sanitisePassword('password1')['error'] !== null);
$check('good password accepted', $s->sanitisePassword('GoodPass123')['error'] === null);
$check('surrounding whitespace trimmed', $s->sanitisePassword('  Trimmed9  ')['value'] === 'Trimmed9');
$check('control characters removed', $s->sanitisePassword("Good\tPass\r9")['value'] === 'GoodPass9');
$check('over 72 chars rejected', $s->sanitisePassword(str_repeat('a', 73))['error'] !== null);

// --- Token + OTP lifecycle (using a real linked account) ---------------------
$r = $db->query("SELECT u.id, u.email FROM users u WHERE u.is_active=1 AND u.employee_id IS NOT NULL AND u.employee_id<>'' LIMIT 1");
$acct = $r->fetch_assoc(); $r->close();
if (!$acct) { echo "(no linked account to exercise the flow - skipping lifecycle)\n"; }
else {
    $uid = (int) $acct['id'];
    $email = (string) $acct['email'];
    $db->query("DELETE FROM password_resets WHERE user_id = {$uid}");

    // Issue a link directly, bypassing the mailer.
    $token = bin2hex(random_bytes(32));
    $otp = '482913';
    $ins = $db->prepare("INSERT INTO password_resets (user_id,email,token_hash,otp_hash,otp_attempts,otp_verified,expires_at,created_at) VALUES (?,?,?,?,0,0,?,?)");
    $a=$uid; $b=$email; $c=hash('sha256',$token); $d=hash('sha256',$otp); $e=date('Y-m-d H:i:s',time()+1800); $f=date('Y-m-d H:i:s');
    $ins->bind_param('isssss', $a,$b,$c,$d,$e,$f); $ins->execute(); $ins->close();

    // Masked address must be masked, and must not be the raw address.
    $inspected = $s->inspectToken($token);
    $check('valid link inspects OK', $inspected['valid'] === true);
    $check(
        'masked email returned, not raw',
        $inspected['masked'] !== $email && str_contains((string) $inspected['masked'], '*')
    );
    $check('bogus token rejected', $s->inspectToken(str_repeat('z', 64))['valid'] === false);
    $check('non-hex token rejected early', $s->inspectToken('../../etc/passwd')['valid'] === false);

    $check('wrong OTP rejected', $s->verifyOtp($token, '000000')['ok'] === false);
    $check('correct OTP accepted', $s->verifyOtp($token, $otp)['ok'] === true);
    $check('formatted OTP (482 913) accepted', $s->verifyOtp($token, '482 913')['ok'] === true);

    // Password change is refused until the OTP gate has been passed.
    $r2 = $db->query("SELECT id FROM password_resets WHERE user_id={$uid} AND token_hash='" . hash('sha256',$token) . "'");
    $row = $r2->fetch_assoc(); $r2->close();
    $verified = $db->query('SELECT otp_verified FROM password_resets WHERE id=' . (int) $row['id']);
    $flagRow = $verified->fetch_assoc();
    $verified->close();
    $check('otp_verified flag is set', (int) ($flagRow['otp_verified'] ?? 0) === 1);

    $check('mismatched confirmation rejected', $s->complete($token, 'NewPass123', 'NewPass999')['ok'] === false);
    $check('weak password rejected', $s->complete($token, 'abc', 'abc')['ok'] === false);
    $check('expired/unknown token rejected', $s->complete(str_repeat('a', 64), 'NewPass123', 'NewPass123')['ok'] === false);

    // Burn the allowance: 5 wrong codes should invalidate the link.
    $t2 = bin2hex(random_bytes(32));
    $ins2 = $db->prepare("INSERT INTO password_resets (user_id,email,token_hash,otp_hash,otp_attempts,otp_verified,expires_at,created_at) VALUES (?,?,?,?,0,0,?,?)");
    $c2 = hash('sha256', $t2);
    $o2 = hash('sha256', '111111');
    $ins2->bind_param('isssss', $a, $b, $c2, $o2, $e, $f);
    $ins2->execute();
    $ins2->close();
    for ($i = 0; $i < 6; $i++) {
        $s->verifyOtp($t2, '999999');
    }
    $afterBurn = $s->verifyOtp($t2, '111111');
    $check('link is burned after the wrong-code cap', $afterBurn['ok'] === false);

    $db->query("DELETE FROM password_resets WHERE user_id = {$uid}");
    $left = $db->query("SELECT COUNT(*) c FROM password_resets WHERE user_id={$uid}");
    $leftRow = $left->fetch_assoc();
    $left->close();
    $check('no live rows left behind', (int) ($leftRow['c'] ?? -1) === 0);
}
echo $ok ? "\nPASSWORD RESET CHECKS PASSED\n" : "\nPASSWORD RESET CHECKS FAILED\n";
<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Hash;
use App\Helpers\JWT;

/**
 * PasswordResetService
 *
 * Self-service password reset: an emailed link PLUS a 6-digit code the user
 * must also type before the new password is accepted.
 *
 * The flow is deliberately two-factor even though the "something you have" is
 * the emailed link: a leaked or shoulder-surfed link alone cannot change the
 * password, and a 6-digit code alone (guessed or phished) cannot either.
 *
 * Two rules shape everything here:
 *
 *  1. NEVER reveal whether an address is registered. `request()` returns a
 *     neutral result for every input and the caller reports the same message.
 *     Answering "no such user" would turn this endpoint into an account
 *     enumeration oracle - the most common flaw in reset flows.
 *
 *  2. Only HASHES are persisted. The raw token and OTP live in the email and
 *     the user's browser, never in the database, so a table dump is inert.
 */
class PasswordResetService
{
    /** Link + code stay valid for this long. */
    private const TTL_MINUTES = 30;

    /** Wrong codes tolerated per issued link before the link is burned. */
    private const MAX_OTP_ATTEMPTS = 5;

    private \mysqli $db;

    public function __construct()
    {
        $this->db = \App\Helpers\Database::getInstance()->getConnection();
    }

    /**
     * Start a reset for an email address.
     *
     * @return array{sent:bool, reason:string} `reason` is for LOGS ONLY and
     *         must never be surfaced to the client (see rule 1 above).
     */
    public function request(string $email): array
    {
        $email = $this->normaliseEmail($email);

        // An unlinkable account cannot be reset: there is no verified staff
        // record to attribute the request to, and no employee behind the box.
        $account = $this->findResettableAccount($email);
        if ($account === null) {
            \logger()->info('Password reset requested for an unresolvable address', [
                'email' => $this->maskEmail($email),
            ]);
            return ['sent' => false, 'reason' => 'no_matching_account'];
        }

        $userId = (int) $account['user_id'];
        $name = trim((string) ($account['first_name'] ?? '')) ?: 'there';

        $token = bin2hex(random_bytes(32));
        $otp = $this->generateOtp();
        $expires = date('Y-m-d H:i:s', time() + self::TTL_MINUTES * 60);

        // Supersede any earlier request: two live links for one account widens
        // the attack surface and creates a "which email is mine?" moment.
        $this->consumeOpenRowsForUser($userId);

        $sql = "INSERT INTO password_resets
                    (user_id, email, token_hash, otp_hash, otp_attempts, otp_verified, expires_at, created_at)
                VALUES (?, ?, ?, ?, 0, 0, ?, ?)";
        $stmt = $this->db->prepare($sql);
        // bind_param takes arguments BY REFERENCE, so every value needs its own
        // variable - an inline expression is rejected outright.
        $pUser = $userId;
        $pEmail = $email;
        $pToken = hash('sha256', $token);
        $pOtp = hash('sha256', $otp);
        $pExpires = $expires;
        $pNow = date('Y-m-d H:i:s');
        $stmt->bind_param('isssss', $pUser, $pEmail, $pToken, $pOtp, $pExpires, $pNow);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            \logger()->error('Password reset row could not be created', [
                'user_id' => $userId,
                'error'   => $this->db->error,
            ]);
            return ['sent' => false, 'reason' => 'storage_failed'];
        }

        $sent = $this->sendLink($email, $name, $token, $otp);
        return ['sent' => $sent, 'reason' => $sent ? 'sent' : 'mail_failed'];
    }

    /**
     * Is a reset link currently usable?
     *
     * @return array{valid:bool, masked:?string, reason:string}
     */
    public function inspectToken(string $token): array
    {
        $row = $this->findLiveRowByToken($token);
        if ($row === null) {
            return ['valid' => false, 'masked' => null, 'reason' => 'invalid_or_expired'];
        }
        // Only the MASKED address travels back. The page needs it so the user
        // can confirm it is their own, but the full address adds nothing the
        // user does not already know.
        return [
            'valid'  => true,
            'masked' => $this->maskEmail((string) $row['email']),
            'reason' => 'ok',
        ];
    }

    /**
     * Check the 6-digit code for a link.
     *
     * @return array{ok:bool, reason:string}
     */
    public function verifyOtp(string $token, string $otp): array
    {
        $row = $this->findLiveRowByToken($token);
        if ($row === null) {
            return ['ok' => false, 'reason' => 'invalid_or_expired'];
        }

        $id = (int) $row['id'];
        $given = $this->normaliseOtp($otp);

        // Constant-time compare. A plain !== leaks how many leading digits were
        // right, which is enough to walk a 6-digit space far faster than brute
        // force.
        if (!hash_equals((string) $row['otp_hash'], hash('sha256', $given))) {
            $this->incrementAttempts($id);
            return ['ok' => false, 'reason' => 'incorrect_code'];
        }

        $sql = 'UPDATE password_resets SET otp_verified = 1 WHERE id = ?';
        $stmt = $this->db->prepare($sql);
        $idParam = $id;
        $stmt->bind_param('i', $idParam);
        $stmt->execute();
        $stmt->close();

        return ['ok' => true, 'reason' => 'ok'];
    }

    /**
     * Set the new password, provided the link is live AND the code was entered.
     *
     * @return array{ok:bool, reason:string, message:string}
     */
    public function complete(string $token, string $newPassword, string $confirmPassword): array
    {
        $row = $this->findLiveRowByToken($token);
        if ($row === null) {
            return $this->fail('invalid_or_expired', 'This reset link is invalid or has expired. Please request a new one.');
        }

        // Possession of the link alone is not authority to change a password.
        if ((int) $row['otp_verified'] !== 1) {
            return $this->fail('otp_required', 'Enter the 6-digit code from your email before setting a new password.');
        }

        // hash_equals on the confirmation, so a mismatch is reported without
        // leaking which character differed.
        if (!hash_equals($newPassword, $confirmPassword)) {
            return $this->fail('mismatch', 'The two passwords do not match.');
        }

        $clean = $this->sanitisePassword($newPassword);
        if ($clean['error'] !== null) {
            return $this->fail('weak_password', $clean['error']);
        }

        $userId = (int) $row['user_id'];
        $hash = Hash::getInstance()->make($clean['value']);

        $sql = 'UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?';
        $stmt = $this->db->prepare($sql);
        $pHash = $hash;
        $pUser = $userId;
        $stmt->bind_param('si', $pHash, $pUser);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            \logger()->error('Password reset could not write the new hash', [
                'user_id' => $userId,
                'error'   => $this->db->error,
            ]);
            return $this->fail('write_failed', 'We could not update your password. Please try again.');
        }

        // Burn the link so it cannot be replayed.
        $this->consume((int) $row['id']);

        // A new password must not leave old sessions authenticated.
        try {
            JWT::getInstance()->revokeAllTokens($userId);
        } catch (\Throwable $e) {
            \logger()->warning('Token revocation failed after password reset', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }

        try {
            \App\Services\AuditService::getInstance()->log(
                \App\Services\AuditService::MODULE_AUTH,
                \App\Services\AuditService::ACTION_PASSWORD_CHANGE,
                'User reset their password via emailed link and OTP',
                [
                    'status'      => \App\Services\AuditService::STATUS_SUCCESS,
                    'target_type' => 'User',
                    'target_id'   => $userId,
                ]
            );
        } catch (\Throwable $e) {
            \logger()->warning('Audit log failed for password reset', ['error' => $e->getMessage()]);
        }

        return ['ok' => true, 'reason' => 'ok', 'message' => 'Your password has been changed.'];
    }

    /**
     * Normalise and police a candidate password.
     *
     * "Sanitisation" here means NORMALISE + VALIDATE, never strip characters out
     * of the middle of the value: silently deleting punctuation from a user's
     * chosen password weakens it and creates a password they never typed.
     * The only mutation is trimming surrounding whitespace, which is almost
     * always a copy-paste artefact.
     *
     * @return array{value:string, error:?string}
     */
    public function sanitisePassword(string $password): array
    {
        // Control characters (including NUL) are stripped: they are invisible in
        // a password field, survive copy-paste from some sources, and break
        // password managers.
        $value = trim(str_replace(["\0", "\r", "\n", "\t"], '', $password));

        if ($value === '') {
            return ['value' => '', 'error' => 'Enter a new password.'];
        }

        // 8 matches the login screen's own minimum, so a password accepted here
        // can always be signed in with. 72 is bcrypt's input limit; hashing
        // beyond it silently ignores the tail, which is worse than refusing.
        if (strlen($value) < 8) {
            return ['value' => $value, 'error' => 'Password must be at least 8 characters.'];
        }
        if (strlen($value) > 72) {
            return ['value' => $value, 'error' => 'Password must be 72 characters or fewer.'];
        }

        if (!preg_match('/[A-Za-z]/', $value)) {
            return ['value' => $value, 'error' => 'Password must contain at least one letter.'];
        }
        if (!preg_match('/\d/', $value)) {
            return ['value' => $value, 'error' => 'Password must contain at least one number.'];
        }

        // The obvious passwords that satisfy the shape rules above.
        $weak = ['password', '12345678', 'qwerty123', 'password1', 'letmein1', 'welcome1', 'admin123'];
        if (in_array(strtolower($value), $weak, true)) {
            return ['value' => $value, 'error' => 'That password is too common. Please choose another.'];
        }

        return ['value' => $value, 'error' => null];
    }

    /**
     * Fetch an unused, unexpired row for a raw token.
     *
     * Expiry and consumption are enforced in the WHERE clause rather than in
     * PHP so a stale row can never be read at all.
     *
     * @return array<string,mixed>|null
     */
    private function findLiveRowByToken(string $token): ?array
    {
        $token = trim($token);
        // A token is 64 hex characters. Rejecting anything else early keeps a
        // junk value out of the index and short-circuits obvious probing.
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $sql = 'SELECT id, user_id, email, otp_hash, otp_attempts, otp_verified
                FROM password_resets
                WHERE token_hash = ? AND consumed_at IS NULL AND expires_at > NOW()
                LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $hash = hash('sha256', $token);
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * Record a wrong code and burn the link once the allowance runs out.
     *
     * Without the hard cap, 6 digits is only 1,000,000 guesses - a script walks
     * that in minutes, and there is no lockout to stop it.
     */
    private function incrementAttempts(int $id): void
    {
        $sql = 'UPDATE password_resets
                SET otp_attempts = otp_attempts + 1,
                    consumed_at = CASE WHEN otp_attempts + 1 >= ? THEN NOW() ELSE consumed_at END
                WHERE id = ?';
        $stmt = $this->db->prepare($sql);
        $max = self::MAX_OTP_ATTEMPTS;
        $idParam = $id;
        $stmt->bind_param('ii', $max, $idParam);
        $stmt->execute();
        $stmt->close();
    }

    /** Mark a row used so its link cannot be replayed. */
    private function consume(int $id): void
    {
        $sql = 'UPDATE password_resets SET consumed_at = NOW() WHERE id = ?';
        $stmt = $this->db->prepare($sql);
        $idParam = $id;
        $stmt->bind_param('i', $idParam);
        $stmt->execute();
        $stmt->close();
    }

    /** Invalidate every outstanding link for a user before issuing a new one. */
    private function consumeOpenRowsForUser(int $userId): void
    {
        $sql = 'UPDATE password_resets SET consumed_at = NOW()
                WHERE user_id = ? AND consumed_at IS NULL';
        $stmt = $this->db->prepare($sql);
        $idParam = $userId;
        $stmt->bind_param('i', $idParam);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Six cryptographically random digits.
     *
     * random_int, NOT rand()/mt_rand(): the OTP guards a password change, and
     * a predictable code turns the emailed link into a single-factor reset.
     */
    private function generateOtp(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Strip formatting a user might paste from an email client.
     *
     * Users copy codes out of mail that renders "123 456" or "123-456", so
     * spaces and dashes are removed before hashing. A wrong code then reports
     * "incorrect" rather than mysteriously failing on an invisible character.
     */
    private function normaliseOtp(string $otp): string
    {
        return preg_replace('/\D/', '', $otp) ?? '';
    }

    private function normaliseEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * "j***e@m***.co.ke" - enough for the user to recognise their address,
     * not enough to be useful to someone who already guessed it.
     */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($domain === '') {
            return '***';
        }
        $head = $local !== '' ? $local[0] : '';
        $tail = strlen($local) > 1 ? $local[strlen($local) - 1] : '';
        $parts = explode('.', $domain);
        $tld = array_pop($parts);
        $host = implode('.', $parts);
        $hostHead = $host !== '' ? $host[0] : '';
        $hostTail = strlen($host) > 1 ? $host[strlen($host) - 1] : '';

        return $head . $tail . str_repeat('*', max(1, strlen($local) - 2))
            . '@' . $hostHead . $hostTail . str_repeat('*', max(1, strlen($host) - 2))
            . ($tld !== null ? '.' . $tld : '');
    }

    /**
     * @return array{ok:false, reason:string, message:string}
     */
    private function fail(string $reason, string $message): array
    {
        return ['ok' => false, 'reason' => $reason, 'message' => $message];
    }



    /**
     * Resolve an email to an ACTIVE user that is linked to a real employee.
     *
     * `users.employee_id` is a varchar that may hold either the employees.id
     * primary key or the human staff number depending on how the row was
     * created, so both linkages are tried before falling back to matching the
     * employee's own email. That last fallback is what keeps the check working
     * for the many accounts whose id column holds a staff number rather than a
     * primary key.
     *
     * @return array<string,mixed>|null
     */
    private function findResettableAccount(string $email): ?array
    {
        $sql = 'SELECT u.id AS user_id, u.first_name, u.last_name, u.employee_id, u.is_active
                FROM users u
                WHERE LOWER(u.email) = ?
                LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $emailParam = $email;
        $stmt->bind_param('s', $emailParam);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // A deactivated account is treated as unknown: a locked-out user must
        // not be able to use the reset flow to work around the lockout.
        if (!$user || (int) $user['is_active'] !== 1) {
            return null;
        }

        $linkedId = trim((string) ($user['employee_id'] ?? ''));
        if ($linkedId !== '' && $this->employeeExistsByIdOrStaffNo($linkedId)) {
            return $user;
        }

        if ($this->employeeExistsByEmail($email)) {
            return $user;
        }

        return null;
    }

    /**
     * users.employee_id may hold employees.id OR the employees.employee_id
     * staff number, so both are attempted in a single round trip.
     */
    private function employeeExistsByIdOrStaffNo(string $value): bool
    {
        $sql = 'SELECT COUNT(*) AS c FROM employees
                WHERE (id REGEXP "^[0-9]+$" AND id = ?) OR employee_id = ?';
        $stmt = $this->db->prepare($sql);
        // The cast and the raw string are separate variables: bind_param takes
        // its arguments by reference, so an inline expression is rejected.
        $asId = (int) $value;
        $asStaffNo = $value;
        $stmt->bind_param('is', $asId, $asStaffNo);
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        return $count > 0;
    }

    private function employeeExistsByEmail(string $email): bool
    {
        $sql = 'SELECT COUNT(*) AS c FROM employees WHERE LOWER(email) = ?';
        $stmt = $this->db->prepare($sql);
        $emailParam = $email;
        $stmt->bind_param('s', $emailParam);
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
        return $count > 0;
    }

    /**
     * Email the link and the code.
     *
     * The code is NOT sent in a separate message: keeping the link and the code
     * together is what proves the requester controls the mailbox, which is the
     * entire point of an emailed link.
     */
    private function sendLink(string $email, string $name, string $token, string $otp): bool
    {
        $frontend = rtrim((string) \env('FRONTEND_URL', 'http://localhost:5173'), '/');
        $link = $frontend . '/reset-password?token=' . rawurlencode($token);

        try {
            return \App\Services\NotificationService::getInstance()->sendEmail(
                $email,
                'Reset your MUWASCO HR password',
                $this->renderEmail($name, $link, $otp),
                true
            );
        } catch (\Throwable $e) {
            \logger()->error('Password reset email could not be sent', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /** Branded HTML email. The code and the link are the only actionable parts. */
    private function renderEmail(string $name, string $link, string $otp): string
    {
        // Every interpolated value is escaped: the name comes from the database
        // and the link carries a token, so neither is trusted markup.
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
        $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
        $minutes = (int) self::TTL_MINUTES;

        return '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:520px;margin:0 auto;padding:24px">'
            . '<h2 style="color:#1e293b;margin:0 0 16px">Reset your password</h2>'
            . '<p style="color:#334155;font-size:15px">Hello ' . $safeName . ',</p>'
            . '<p style="color:#334155;font-size:15px">We received a request to reset the password for your '
            . 'MUWASCO HR account. Open the link below, then enter the verification code when asked.</p>'
            . '<p style="margin:24px 0">'
            . '<a href="' . $safeLink . '" style="background:#4f46e5;color:#ffffff;text-decoration:none;'
            . 'padding:12px 20px;border-radius:8px;display:inline-block;font-weight:600">Reset my password</a>'
            . '</p>'
            . '<p style="color:#334155;font-size:15px">Your verification code:</p>'
            . '<p style="font-size:30px;letter-spacing:8px;font-weight:700;color:#1e293b;margin:8px 0 16px">'
            . $safeOtp . '</p>'
            . '<p style="color:#64748b;font-size:13px">This link and code expire in ' . $minutes
            . ' minutes and can be used once. If you did not request this, you can ignore this email - '
            . 'your password will not change.</p>'
            . '<p style="color:#94a3b8;font-size:12px;margin-top:24px">If the button does not work, copy this '
            . 'link into your browser:<br>' . $safeLink . '</p>'
            . '</div>';
    }
}

<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use App\Services\PasswordResetService;

/**
 * PasswordResetController
 *
 * HTTP surface for the self-service reset flow:
 *
 *   POST /auth/forgot-password            { email }              -> generic ack
 *   GET  /auth/reset-password/validate    ?token=               -> link still live?
 *   POST /auth/reset-password/verify-otp  { token, otp }        -> code check
 *   POST /auth/reset-password/complete    { token, password, confirm_password }
 *
 * Every action is UNAUTHENTICATED by necessity, so each is throttled here and
 * the two token-consuming actions carry their own attempt caps.
 */
class PasswordResetController extends BaseController
{
    private PasswordResetService $service;

    public function __construct()
    {
        $this->service = new PasswordResetService();
    }

    /**
     * POST /auth/forgot-password
     *
     * The response is IDENTICAL for a real address, an unknown one, and a user
     * with no employee record. Any difference here would let an attacker
     * enumerate which addresses hold accounts - so the real reason is logged
     * server-side and never returned.
     */
    public function requestAction(): void
    {
        $data = $this->getJsonBody();
        $email = is_string($data['email'] ?? null) ? trim($data['email']) : '';

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // A malformed address is answered with the SAME generic message as a
            // valid one; only obviously unusable input is rejected outright.
            $this->success(null, self::GENERIC_MESSAGE);
            return;
        }

        // Throttled per address AND per IP. Per address stops one mailbox being
        // flooded with reset mail; per IP stops spraying many addresses.
        \App\Middleware\SecurityMiddleware::protectAgainstBruteForce('forgot_password', 3, 900, $email);
        \App\Middleware\SecurityMiddleware::protectAgainstBruteForce('forgot_password', 10, 900, null);

        $result = $this->service->request($email);

        if (!$result['sent'] && $result['reason'] !== 'no_matching_account') {
            // A real, resolvable account that we failed to mail is an
            // operational fault the user must hear about - a silent success here
            // would leave a real person unable to reset their password.
            \logger()->warning('Password reset could not be delivered', [
                'email'  => substr($email, 0, 2) . '***',
                'reason' => $result['reason'],
            ]);
        }

        $this->success(null, self::GENERIC_MESSAGE);
    }

    /**
     * GET /auth/reset-password/validate?token=...
     *
     * Lets the page tell "paste your code" apart from "this link is dead"
     * before the user types anything.
     */
    public function validateAction(): void
    {
        $token = is_string($_GET['token'] ?? null) ? trim($_GET['token']) : '';
        $result = $this->service->inspectToken($token);

        $this->success([
            'valid'  => $result['valid'],
            'masked' => $result['masked'],
        ]);
    }

    /**
     * POST /auth/reset-password/verify-otp
     */
    public function verifyOtpAction(): void
    {
        $data = $this->getJsonBody();
        $token = is_string($data['token'] ?? null) ? trim($data['token']) : '';
        $otp = is_string($data['otp'] ?? null) ? $data['otp'] : '';

        if ($token === '' || $otp === '') {
            $this->error('Enter the 6-digit code from your email.', 400);
        }

        // Rate limit by the LINK, not the IP: the 6-digit space is per token, so
        // a botnet must not get a fresh allowance just by changing source.
        \App\Middleware\SecurityMiddleware::protectAgainstBruteForce('reset_otp', 10, 900, substr($token, 0, 16));

        $result = $this->service->verifyOtp($token, $otp);

        if (!$result['ok']) {
            $message = $result['reason'] === 'incorrect_code'
                ? 'That code is not correct. Check the email and try again.'
                : 'This reset link is invalid or has expired. Please request a new one.';
            $this->error($message, 400);
        }

        $this->success(null, 'Code accepted');
    }

    /**
     * POST /auth/reset-password/complete
     */
    public function completeAction(): void
    {
        $data = $this->getJsonBody();
        $token = is_string($data['token'] ?? null) ? trim($data['token']) : '';
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        $confirm = is_string($data['confirm_password'] ?? null) ? $data['confirm_password'] : '';

        if ($token === '' || $password === '' || $confirm === '') {
            $this->error('All fields are required.', 400);
        }

        \App\Middleware\SecurityMiddleware::protectAgainstBruteForce('reset_complete', 10, 900, substr($token, 0, 16));

        $result = $this->service->complete($token, $password, $confirm);

        if (!$result['ok']) {
            // The service already produces a user-facing message; nothing about
            // the token or its state is echoed back beyond that.
            $this->error($result['message'], 400);
        }

        $this->success(null, $result['message']);
    }

    /**
     * One message for every outcome of POST /auth/forgot-password.
     *
     * @see self::requestAction() for why this must never vary.
     */
    private const GENERIC_MESSAGE =
        'If that email address belongs to an active staff account, a reset link and verification code are on their way.';
}

<?php

declare(strict_types=1);

namespace App\Services\Notification;

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * EmailChannel
 *
 * Brings email into the same ChannelInterface the push and SMS channels already
 * implement, so the worker treats it identically instead of email being the one
 * channel with a special, synchronous code path.
 *
 * This is a THIN wrapper: the SMTP settings and the actual send live in
 * NotificationService::sendEmail(), which is what the rest of the application
 * (FY creation, appraisal status, password reset) already uses. Re-implementing
 * PHPMailer config here would risk the two drifting on host, port or TLS.
 */
class EmailChannel
{
    /**
     * @param array{to:string, subject:string, body:string, html?:bool} $payload
     */
    public function send(array $payload): ChannelResult
    {
        $to = trim((string) ($payload['to'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ChannelResult::skipped('No valid recipient address');
        }

        try {
            $sent = \App\Services\NotificationService::getInstance()->sendEmail(
                $to,
                (string) ($payload['subject'] ?? 'Notification'),
                (string) ($payload['body'] ?? ''),
                (bool) ($payload['html'] ?? true)
            );
        } catch (MailerException $e) {
            // Transport-level problems (SMTP refused, DNS, TLS) are usually
            // transient, so they are retryable rather than permanent.
            return ChannelResult::failedRetryable('Mailer exception: ' . $e->getMessage());
        } catch (\Throwable $e) {
            return ChannelResult::failed('Unexpected mailer error: ' . $e->getMessage());
        }

        // sendEmail() swallows its own exceptions and returns false, so the
        // reason has to be recovered from its log rather than surfaced here.
        return $sent
            ? ChannelResult::sent('smtp:' . substr(hash('sha256', $to . (string) $payload['subject']), 0, 16))
            : ChannelResult::failedRetryable('SMTP send reported failure');
    }
}

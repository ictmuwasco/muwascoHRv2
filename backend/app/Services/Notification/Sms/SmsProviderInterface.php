<?php

declare(strict_types=1);

namespace App\Services\Notification\Sms;

/**
 * SMS provider abstraction.
 *
 * Attendance logic NEVER talks to a concrete gateway: channels depend
 * on this interface only, so switching providers (httpSMS, Africa's
 * Talking, Twilio, ...) means adding a driver - not rewriting rules.
 */
interface SmsProviderInterface
{
    /**
     * Send a text message.
     *
     * @param string $to          E.164 recipient (+2547XXXXXXXX)
     * @param string $message     Text content (kept concise by templates)
     * @param string $requestId   Client-side idempotency key echoed by
     *                            providers that support it (dedup safety net)
     */
    public function sendSms(string $to, string $message, string $requestId = ''): SmsResult;

    /** Human-readable driver name for logs/audit. */
    public function name(): string;
}

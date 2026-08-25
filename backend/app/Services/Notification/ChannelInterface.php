<?php

declare(strict_types=1);

namespace App\Services\Notification;

/**
 * Delivery channel contract implemented by WebPushChannel, SmsChannel
 * and future channels (email, WhatsApp, in-app...).
 */
interface ChannelInterface
{
    /** Channel identifier used in notification_logs.channel */
    public function name(): string;

    /**
     * Attempt delivery for one request.
     * Implementations MUST NOT re-check attendance eligibility - the
     * router owns that decision - but SHOULD report precise failures.
     */
    public function send(NotificationRequest $request): ChannelResult;
}

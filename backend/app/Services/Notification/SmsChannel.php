<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Services\Notification\Sms\SmsProviderInterface;

/**
 * HTTP SMS delivery channel. Delegates wire details to the configured
 * SmsProviderInterface driver; this class only maps results to
 * ChannelResult semantics (retryable vs permanent).
 */
class SmsChannel implements ChannelInterface
{
    private SmsProviderInterface $provider;
    private ReminderTemplateService $templates;

    public function __construct(SmsProviderInterface $provider, ?ReminderTemplateService $templates = null)
    {
        $this->provider  = $provider;
        $this->templates = $templates ?? new ReminderTemplateService();
    }

    public function name(): string
    {
        return 'sms';
    }

    public function send(NotificationRequest $request): ChannelResult
    {
        if ($request->phone === null || $request->phone === '') {
            return ChannelResult::skipped('Missing phone number');
        }

        // request_id doubles as provider-side idempotency echo.
        $requestId = sprintf(
            'att-%d-%s-%s',
            (int) $request->userId,
            str_replace('-', '', $request->businessDate),
            $request->stage
        );

        try {
            $result = $this->provider->sendSms($request->phone, $this->templates->buildSmsText($request), $requestId);
        } catch (\Throwable $e) {
            \logger()->error('SMS provider threw', ['error' => $e->getMessage(), 'user_id' => $request->userId]);
            return ChannelResult::failedRetryable('Provider exception: ' . $e->getMessage());
        }

        if ($result->getStatus() === \App\Services\Notification\Sms\SmsResult::STATUS_SUCCESS) {
            return ChannelResult::sent($result->getProviderMessageId());
        }
        if ($result->isRetryable()) {
            return ChannelResult::failedRetryable($result->getFailureReason());
        }
        return ChannelResult::failed('[' . $result->getStatus() . '] ' . $result->getFailureReason());
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Notification\Sms;

/**
 * httpSMS driver (https://httpsms.com) - Android-phone-as-gateway.
 *
 *   POST {HTTPSMS_BASE_URL}/v1/messages/send
 *   x-api-key: <HTTPSMS_API_KEY>
 *   {"content": "...", "from": "+2547XXXXXXXX", "to": "+2547XXXXXXXX",
 *    "request_id": "att-123-20260824"}
 *
 * Credentials live exclusively in server-side env; this class is the
 * ONLY place that knows the wire format. Swap via SMS_PROVIDER env.
 */
class HttpSmsProvider implements SmsProviderInterface
{
    private string $apiKey;
    private string $baseUrl;
    private string $senderPhone;
    private int $timeoutSeconds;

    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $senderPhone = null,
        int $timeoutSeconds = 15
    ) {
        $this->apiKey         = (string) ($apiKey ?? env('HTTPSMS_API_KEY', ''));
        $this->baseUrl        = rtrim((string) ($baseUrl ?? env('HTTPSMS_BASE_URL', 'https://api.httpsms.com')), '/');
        $this->senderPhone    = (string) ($senderPhone ?? env('HTTPSMS_SENDER_PHONE', ''));
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function name(): string
    {
        return 'httpsms';
    }

    public function sendSms(string $to, string $message, string $requestId = ''): SmsResult
    {
        if ($this->apiKey === '' || $this->senderPhone === '') {
            return SmsResult::failure(
                SmsResult::STATUS_PROVIDER_ERROR,
                'httpSMS not configured: HTTPSMS_API_KEY / HTTPSMS_SENDER_PHONE missing'
            );
        }

        $payload = [
            'content' => $message,
            'from'    => $this->senderPhone,
            'to'      => $to,
        ];
        if ($requestId !== '') {
            // Provider-side idempotency echo (dedup safety net).
            $payload['request_id'] = substr($requestId, 0, 64);
        }

        $ch = curl_init($this->baseUrl . '/v1/messages/send');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $body     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return SmsResult::failure(SmsResult::STATUS_TEMPORARY_FAILURE, 'Transport error: ' . $curlErr);
        }

        $decoded = json_decode((string) $body, true);

        switch (true) {
            case $httpCode >= 200 && $httpCode < 300:
                $messageId = $decoded['data']['id'] ?? null;
                \logger()->info('SMS sent via httpSMS', ['to' => $to, 'message_id' => $messageId]);
                return SmsResult::success(is_string($messageId) ? $messageId : null);

            case $httpCode === 429:
                return SmsResult::failure(SmsResult::STATUS_RATE_LIMITED, 'Provider rate limited (HTTP 429)');

            case $httpCode === 400 || $httpCode === 422:
                $reason = is_array($decoded) ? json_encode($decoded) : ('HTTP ' . $httpCode);
                return SmsResult::failure(
                    str_contains($reason, 'phone') || str_contains(strtolower($reason), 'invalid')
                        ? SmsResult::STATUS_INVALID_NUMBER
                        : SmsResult::STATUS_PERMANENT_FAILURE,
                    substr('Rejected by provider: ' . $reason, 0, 480)
                );

            case $httpCode === 401 || $httpCode === 403:
                return SmsResult::failure(SmsResult::STATUS_PROVIDER_ERROR, 'Authentication failed (check API key)');

            default:
                // 5xx and anything unexpected = transient.
                return SmsResult::failure(SmsResult::STATUS_TEMPORARY_FAILURE, 'HTTP ' . $httpCode . ': ' . substr((string) $body, 0, 200));
        }
    }
}

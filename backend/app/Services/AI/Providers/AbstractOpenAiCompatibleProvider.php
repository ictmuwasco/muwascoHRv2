<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

use App\Services\AI\AiCompletionResult;
use App\Services\AI\Contracts\AiProviderInterface;
use App\Services\AI\Contracts\MessageInterface;
use App\Services\AI\Transport\HttpTransport;

/**
 * AbstractOpenAiCompatibleProvider — shared implementation over the standard
 * OpenAI chat-completions wire format (/v1/chat/completions).
 *
 * Concrete drivers supply base_url / model / api_key / limits from config and
 * choose whether to attach tool definitions. Parsing, error classification,
 * retryable-status mapping and redaction live here once.
 *
 * Transport failures NEVER throw: they are folded into an AiCompletionResult
 * with a structured status so AiProviderManager can decide retry / fallback.
 */
abstract class AbstractOpenAiCompatibleProvider implements AiProviderInterface
{
    protected string $baseUrl;
    protected string $model;
    protected string $apiKey;
    protected int $timeoutSeconds;
    protected int $connectTimeout;
    protected int $maxTokens;
    protected int $maxRetries;
    protected int $retryBackoffMs;
    protected bool $supportsTools;
    protected HttpTransport $transport;

    public function __construct(array $config, ?HttpTransport $transport = null)
    {
        $this->baseUrl        = rtrim((string) ($config['base_url'] ?? ''), '/');
        $this->model          = (string) ($config['model'] ?? '');
        $this->apiKey         = (string) ($config['api_key'] ?? '');
        $this->timeoutSeconds = (int) ($config['timeout_seconds'] ?? 60);
        $this->connectTimeout = (int) ($config['connect_timeout'] ?? 5);
        $this->maxTokens      = (int) ($config['max_tokens'] ?? 2048);
        $this->maxRetries     = (int) ($config['max_retries'] ?? 0);
        $this->retryBackoffMs = (int) ($config['retry_backoff_ms'] ?? 500);
        $this->supportsTools  = (bool) ($config['supports_tools'] ?? false);
        $this->transport      = $transport ?? new HttpTransport();
    }

    public function model(): string
    {
        return $this->model;
    }

    public function isHealthy(): bool
    {
        if ($this->baseUrl === '') {
            return false;
        }
        // A cheap no-op probe against the chat host. Some local gateways
        // expose /api/tags (overridden by LocalProvider); this fallback simply
        // confirms the host answers anything non-5xx.
        $result = $this->transport->getJson(
            $this->baseUrl . '/models',
            $this->defaultHeaders(),
            min(5, $this->timeoutSeconds),
            $this->connectTimeout
        );
        return $result['ok'] && $result['http_code'] >= 200 && $result['http_code'] < 500;
    }

    public function chat(array $messages, ?array $tools = null, array $options = []): AiCompletionResult
    {
        $maxRetries = (int) ($options['max_retries'] ?? $this->maxRetries);
        $attempts   = 0;

        do {
            $attempts++;
            $result = $this->attemptCompletion($messages, $tools, $options);
            if (!$result->isRetryable() || $attempts > $maxRetries) {
                break;
            }
            usleep($this->retryBackoffMs * 1000);
        } while (true);

        return $result->withAttempts($attempts);
    }

    /* =================================================================
     * Internals
     * ================================================================= */

    private function attemptCompletion(array $messages, ?array $tools, array $options): AiCompletionResult
    {
        if ($this->baseUrl === '') {
            return AiCompletionResult::failure(
                AiCompletionResult::STATUS_PROVIDER_ERROR,
                'AI provider base_url is not configured.'
            );
        }

        $payload = [
            'model'       => $this->model,
            'messages'    => $this->normalizeMessages($messages),
            'temperature' => (float) ($options['temperature'] ?? 0.2),
            'max_tokens'  => (int) ($options['max_tokens'] ?? $this->maxTokens),
        ];

        if ($this->supportsTools && is_array($tools) && $tools !== []) {
            $payload['tools']       = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $transport = $this->transport->postJson(
            $this->baseUrl . '/v1/chat/completions',
            $payload,
            $this->defaultHeaders(),
            $this->timeoutSeconds,
            $this->connectTimeout
        );

        if (!$transport['ok']) {
            $status = $this->detectTimeout($transport['error'])
                ? AiCompletionResult::STATUS_TIMEOUT
                : AiCompletionResult::STATUS_TEMPORARY_FAILURE;
            return AiCompletionResult::failure(
                $status,
                $this->safeTransportError($transport['error']),
                $transport['http_code']
            );
        }

        $httpCode = $transport['http_code'];
        $decoded  = json_decode($transport['body'], true);
        $raw      = is_array($decoded) ? $decoded : [];

        switch (true) {
            case $httpCode >= 200 && $httpCode < 300:
                return $this->parseSuccess($raw);

            case $httpCode === 429:
                return AiCompletionResult::failure(
                    AiCompletionResult::STATUS_RATE_LIMITED,
                    'AI provider rate limited (HTTP 429).',
                    $httpCode
                );

            case $httpCode === 401 || $httpCode === 403:
                return AiCompletionResult::failure(
                    AiCompletionResult::STATUS_PROVIDER_ERROR,
                    'AI provider authentication failed (check API key).',
                    $httpCode
                );

            case $httpCode === 400 || $httpCode === 422:
                return AiCompletionResult::failure(
                    AiCompletionResult::STATUS_PERMANENT_FAILURE,
                    'AI provider rejected the request (HTTP ' . $httpCode . ').',
                    $httpCode
                );

            default:
                // 5xx and anything unexpected = transient.
                return AiCompletionResult::failure(
                    AiCompletionResult::STATUS_TEMPORARY_FAILURE,
                    'AI provider HTTP ' . $httpCode . '.',
                    $httpCode
                );
        }
    }

    /** @return array<int, array<string,mixed>> */
    private function normalizeMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            if (!$message instanceof MessageInterface) {
                continue;
            }
            $row = ['role' => $message->role()];

            if ($message->role() === 'tool') {
                $row['tool_call_id'] = (string) ($message->toolCallId() ?? '');
                $row['content']      = $message->content();
                $out[] = $row;
                continue;
            }

            if ($message->role() === 'assistant' && $message->toolCall() !== null) {
                $tc = $message->toolCall();
                $row['content']    = $message->content();
                $row['tool_calls'] = [[
                    'id'       => (string) ($tc['id'] ?? 'call_' . sha1($tc['name'] . $tc['arguments'])),
                    'type'     => 'function',
                    'function' => [
                        'name'      => (string) $tc['name'],
                        'arguments' => (string) $tc['arguments'],
                    ],
                ]];
                $out[] = $row;
                continue;
            }

            $row['content'] = $message->content();
            $out[] = $row;
        }
        return $out;
    }

    private function parseSuccess(array $raw): AiCompletionResult
    {
        $choice = $raw['choices'][0] ?? null;
        if (!is_array($choice)) {
            return AiCompletionResult::failure(
                AiCompletionResult::STATUS_PERMANENT_FAILURE,
                'AI provider returned an empty choices payload.'
            );
        }

        $message      = $choice['message'] ?? [];
        $finish       = (string) ($choice['finish_reason'] ?? 'stop');
        $toolCallsRaw = $message['tool_calls'] ?? [];

        if (is_array($toolCallsRaw) && $toolCallsRaw !== []) {
            $toolCalls = [];
            foreach ($toolCallsRaw as $tc) {
                $fn = $tc['function'] ?? [];
                $toolCalls[] = [
                    'name'      => (string) ($fn['name'] ?? ''),
                    'arguments' => (string) ($fn['arguments'] ?? ''),
                    'id'        => (string) ($tc['id'] ?? ''),
                ];
            }
            return AiCompletionResult::toolCalls($toolCalls, (string) ($raw['model'] ?? $this->model));
        }

        $content = (string) ($message['content'] ?? '');
        return AiCompletionResult::success(
            $content,
            $finish,
            $this->parseUsage($raw),
            (string) ($raw['model'] ?? $this->model)
        );
    }

    private function parseUsage(array $raw): array
    {
        $u = $raw['usage'] ?? [];
        if (!is_array($u)) {
            return [];
        }
        return [
            'prompt_tokens'     => isset($u['prompt_tokens']) ? (int) $u['prompt_tokens'] : 0,
            'completion_tokens' => isset($u['completion_tokens']) ? (int) $u['completion_tokens'] : 0,
            'total_tokens'      => isset($u['total_tokens']) ? (int) $u['total_tokens'] : 0,
        ];
    }

    private function defaultHeaders(): array
    {
        $headers = [];
        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }
        return $headers;
    }

    private function detectTimeout(string $error): bool
    {
        $needle = strtolower($error);
        return str_contains($needle, 'timeout') || str_contains($needle, 'timed out') || str_contains($needle, 'timedout');
    }

    private function safeTransportError(string $error): string
    {
        return substr(trim($error), 0, 200);
    }
}
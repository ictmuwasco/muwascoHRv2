<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Services\AI\AiCompletionResult;
use App\Services\AI\ChatMessage;
use App\Services\AI\Providers\OpenAiCompatibleProvider;
use App\Services\AI\Transport\HttpTransport;
use Tests\TestCase;

/**
 * OpenAiCompatibleProvider — wire-format parsing, error classification,
 * retryable-status mapping and message normalization, driven by a fake
 * transport so NO network is ever touched by tests.
 *
 * Place: backend/tests/Unit/Services/AI/OpenAiCompatibleProviderTest.php
 */
class OpenAiCompatibleProviderTest extends TestCase
{
    private function provider(FakeHttpTransport $transport, array $config = []): OpenAiCompatibleProvider
    {
        return new OpenAiCompatibleProvider(array_merge([
            'base_url'         => 'https://example.test/v1',
            'model'            => 'test-model',
            'api_key'          => 'sk-test',
            'timeout_seconds'  => 30,
            'connect_timeout'  => 5,
            'max_tokens'       => 512,
            'max_retries'     => 0,
            'retry_backoff_ms' => 1,
            'supports_tools'   => true,
        ], $config), $transport);
    }

    public function testSuccessParsesContentAndUsage(): void
    {
        $transport = new FakeHttpTransport([
            'ok' => true,'http_code' => 200,'error' => '','body' => json_encode([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => 'Your balance is 21 days'], 'finish_reason' => 'stop'],
                ],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
                'model' => 'test-model',
            ]),
        ]);

        $result = $this->provider($transport)->chat([ChatMessage::user('balance?')]);
        $this->assertTrue($result->isSuccess());
        $this->assertSame('Your balance is 21 days', $result->getContent());
        $this->assertSame(15, $result->getUsage()['total_tokens']);
        $this->assertSame('test-model', $result->getProviderModel());
        $this->assertSame(1, $result->getAttempts());
    }

    public function testToolCallsAreReturnedAsProposalsOnly(): void
    {
        $transport = new FakeHttpTransport([
            'ok' => true,'http_code' => 200,'error' => '','body' => json_encode([
                'choices' => [
                    ['message' => [
                        'role' => 'assistant',
                        'content' => '',
                        'tool_calls' => [
                            ['id' => 'call_9', 'type' => 'function', 'function' => ['name' => 'getMyLeaveBalance', 'arguments' => '{"year":2026}']],
                        ],
                    ], 'finish_reason' => 'tool_calls'],
                ],
                'model' => 'test-model',
            ]),
        ]);

        $result = $this->provider($transport)->chat([ChatMessage::user('balance?')], $this->toolDefinition());
        $this->assertTrue($result->isToolCall());
        $calls = $result->getToolCalls();
        $this->assertCount(1, $calls);
        $this->assertSame('getMyLeaveBalance', $calls[0]['name']);
        $this->assertSame('{"year":2026}', $calls[0]['arguments']);
        $this->assertSame('call_9', $calls[0]['id']);
    }

    public function testHttp429MapsToRateLimited(): void
    {
        $transport = new FakeHttpTransport([
            'ok' => true,'http_code' => 429,'error' => '','body' => '{"error":{"message":"rate limited"}}',
        ]);
        $result = $this->provider($transport)->chat([ChatMessage::user('hi')]);
        $this->assertSame(AiCompletionResult::STATUS_RATE_LIMITED, $result->getStatus());
        $this->assertTrue($result->isRetryable());
    }

    public function testHttp401MapsToProviderError(): void
    {
        $transport = new FakeHttpTransport([
            'ok' => true,'http_code' => 401,'error' => '','body' => '{"error":{"message":"invalid key"}}',
        ]);
        $result = $this->provider($transport)->chat([ChatMessage::user('hi')]);
        $this->assertSame(AiCompletionResult::STATUS_PROVIDER_ERROR, $result->getStatus());
    }

    public function testHttp400MapsToPermanentFailure(): void
    {
        $transport = new FakeHttpTransport([
            'ok' => true,'http_code' => 400,'error' => '','body' => '{"error":{"message":"bad"}}',
        ]);
        $result = $this->provider($transport)->chat([ChatMessage::user('hi')]);
        $this->assertSame(AiCompletionResult::STATUS_PERMANENT_FAILURE, $result->getStatus());
        $this->assertFalse($result->isRetryable());
        $this->assertStringContainsString('400', $result->getFailureReason());
    }

    public function testHttp500MapsToTemporaryFailure(): void
   
    {
        $transport = new FakeHttpTransport([
            'ok' => true,'http_code' => 500,'error' => '','body' => 'server error',
        ]);
        $result = $this->provider($transport)->chat([ChatMessage::user('hi')]);
        $this->assertSame(AiCompletionResult::STATUS_TEMPORARY_FAILURE, $result->getStatus());
        $this->assertTrue($result->isRetryable());
    }

    public function testTransportTimeoutMapsToTimeoutStatus(): void
   
    {
        $transport = new FakeHttpTransport([
            'ok' => false,'http_code' => 0,'error' => 'Operation timed out after 30000 milliseconds', 'body' => '',
        ]);
        $result = $this->provider($transport)->chat([ChatMessage::user('hi')]);
        $this->assertSame(AiCompletionResult::STATUS_TIMEOUT, $result->getStatus());
    }
public function testRetriesTransientFailuresThenSucceeds(): void
    {
        $transport = new FakeHttpTransport(
            ['ok' => true, 'http_code' => 503, 'error' => '', 'body' => 'unavailable'],
            ['ok' => true, 'http_code' => 503, 'error' => '', 'body' => 'unavailable'],
            ['ok' => true, 'http_code' => 200, 'error' => '', 'body' => json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'recovered'], 'finish_reason' => 'stop']],
                'usage' => [],
                'model' => 'test-model',
            ])]
        );

        $result = $this->provider($transport)->chat([ChatMessage::user('hi')], null, ['max_retries' => 2]);
        $this->assertTrue($result->isSuccess());
        $this->assertSame('recovered', $result->getContent());
        $this->assertSame(3, $result->getAttempts());
    }

    public function testAssistantToolCallAndToolResultAreNormalized(): void
    {
        $transport = new FakeHttpTransport([
            'ok' => true, 'http_code' => 200, 'error' => '', 'body' => json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'done'], 'finish_reason' => 'stop']],
                'usage' => [],
                'model' => 'test-model',
            ]),
        ]);

        $this->provider($transport)->chat([
            ChatMessage::assistantWithToolCall('', 'getMyLeaveBalance', '{}', 'call_1'),
            ChatMessage::tool('call_1', '{"days":21}'),
            ChatMessage::user('thanks'),
        ]);

        $sent = $transport->lastSentPayload();
        $this->assertSame('assistant', $sent['messages'][0]['role']);
        $this->assertSame('call_1', $sent['messages'][0]['tool_calls'][0]['id']);
        $this->assertSame('tool', $sent['messages'][1]['role']);
        $this->assertSame('call_1', $sent['messages'][1]['tool_call_id']);
        $this->assertSame('{"days":21}', $sent['messages'][1]['content']);
    }

    public function testIsHealthyFallsBackToModelsProbe(): void
    {
        $transport = new FakeHttpTransport();
        $transport->setGetResponse(['ok' => true, 'http_code' => 200, 'error' => '', 'body' => '{"data":[]}']);
        $this->assertTrue($this->provider($transport)->isHealthy());
    }

    public function testIsHealthyFalseOnConnectionError(): void
    {
        $transport = new FakeHttpTransport();
        $transport->setGetResponse(['ok' => false, 'http_code' => 0, 'error' => 'could not resolve host', 'body' => '']);
        $this->assertFalse($this->provider($transport)->isHealthy());
    }

    /** @return array<int, array{name:string, description:string, parameters:array}> */
    private function toolDefinition(): array
    {
        return [[
            'name'        => 'getMyLeaveBalance',
            'description' => 'Get my leave balance',
            'parameters'  => ['type' => 'object', 'properties' => ['year' => ['type' => 'integer']]],
        ]];
    }
}
/**
 * FakeHttpTransport — in-memory HttpTransport replacement: returns canned
 * post responses in FIFO order and a single canned get response. Captures
 * the last POST payload for wire-format assertions. No network is touched.
 */
final class FakeHttpTransport extends HttpTransport
{
    /** @var array<int, array{ok: bool, http_code: int, body: string, error: string}> */
    private array $responses = [];

    /** @var array{ok: bool, http_code: int, body: string, error: string}|null */
    private ?array $getResponse = null;

    private ?array $lastPayload = null;

    public function __construct(array ...$responses)
    {
        $this->responses = $responses;
    }

    public function setGetResponse(array $response): void
    {
        $this->getResponse = $response;
    }

    public function lastSentPayload(): ?array
    {
        return $this->lastPayload;
    }

    public function postJson(string $url, array $payload, array $headers = [], int $timeoutSeconds = 60, int $connectTimeout = 5): array
    {
        $this->lastPayload = $payload;
        $response = array_shift($this->responses);
        if (is_array($response)) {
            return $response;
        }
        return [
            'ok'        => true,
            'http_code' => 200,
            'body'      => json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'fallback'], 'finish_reason' => 'stop']],
                'usage'   => [],
                'model'   => 'test',
            ]),
            'error' => '',
        ];
    }

    public function getJson(string $url, array $headers = [], int $timeoutSeconds = 5, int $connectTimeout = 3): array
    {
        return $this->getResponse ?? ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'no get response'];
    }
}
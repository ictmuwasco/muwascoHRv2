<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Services\AI\AiCompletionResult;
use App\Services\AI\AiProviderException;
use App\Services\AI\AiProviderManager;
use App\Services\AI\ChatMessage;
use App\Services\AI\Contracts\AiProviderInterface;
use Tests\TestCase;

/**
 * AiProviderManager — provider resolution, fallback policy and config
 * redaction, exercised through a testable subclass so no network and no
 * shared config cache is touched.
 *
 * Place: backend/tests/Unit/Services/AI/AiProviderManagerTest.php
 */
class AiProviderManagerTest extends TestCase
{
    public function testProviderResolvesConfiguredDriver(): void
    {
        $fake = new FakeAiProvider('local', AiCompletionResult::success('ok'));
        $manager = $this->manager(['ai.provider' => 'local'], ['local' => $fake]);

        $provider = $manager->selfProvider();
        $this->assertInstanceOf(AiProviderInterface::class, $provider);
        $this->assertSame('local', $provider->name());
    }

    public function testUnknownDriverThrowsProviderException(): void
    {
        $this->expectException(AiProviderException::class);

        $manager = $this->manager(['ai.provider' => 'nope']);
        $manager->selfProvider();
    }

    public function testNoFallbackWhenSuccess(): void
    {
        $fake = new FakeAiProvider('local', AiCompletionResult::success('fine'));
        $manager = $this->manager(
            ['ai.provider' => 'local', 'ai.fallback.enabled' => true, 'ai.fallback.providers' => ['openai_compatible']],
            ['local' => $fake]
        );

        $result = $manager->selfChat([ChatMessage::user('hi')], null, []);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $fake->createdCount());
    }

    public function testFallsBackOnRetryableFailureWhenEnabled(): void
    {
        $local = new FakeAiProvider('local', AiCompletionResult::failure(AiCompletionResult::STATUS_TIMEOUT, 't'));
        $fallback = new FakeAiProvider('openai_compatible', AiCompletionResult::success('from fallback'));
        $manager = $this->manager(
            ['ai.provider' => 'local', 'ai.fallback.enabled' => true, 'ai.fallback.providers' => ['local', 'openai_compatible']],
            ['local' => $local, 'openai_compatible' => $fallback]
        );

        $result = $manager->chat([ChatMessage::user('hi')], null, []);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('from fallback', $result->getContent());
        $this->assertSame(1, $local->createdCount());
        $this->assertSame(1, $fallback->createdCount());
    }

    public function testNoFallbackWhenDisabled(): void
    {
        $local = new FakeAiProvider('local', AiCompletionResult::failure(AiCompletionResult::STATUS_TIMEOUT, 't'));
        $manager = $this->manager(
            ['ai.provider' => 'local', 'ai.fallback.enabled' => false, 'ai.fallback.providers' => ['openai_compatible']],
            ['local' => $local]
        );

        $result = $manager->chat([ChatMessage::user('hi')], null, []);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(AiCompletionResult::STATUS_TIMEOUT, $result->getStatus());
        $this->assertSame(1, $local->createdCount());
    }

    public function testNoFallbackOnPermanentFailure(): void
    {
        $local = new FakeAiProvider('local', AiCompletionResult::failure(AiCompletionResult::STATUS_PERMANENT_FAILURE, 'bad'));
        $manager = $this->manager(
            ['ai.provider' => 'local', 'ai.fallback.enabled' => true, 'ai.fallback.providers' => ['openai_compatible']],
            ['local' => $local]
        );

        $result = $manager->chat([ChatMessage::user('hi')], null, []);

        $this->assertSame(AiCompletionResult::STATUS_PERMANENT_FAILURE, $result->getStatus());
        $this->assertSame(1, $local->createdCount());
    }
    public function testSkipsUnhealthyFallbackProvider(): void
    {
        $local = new FakeAiProvider('local', AiCompletionResult::failure(AiCompletionResult::STATUS_TIMEOUT, 't'));
        $unhealthy = new FakeAiProvider('nvidia_nim', AiCompletionResult::failure(AiCompletionResult::STATUS_TIMEOUT, 't'), false);
        $fallback = new FakeAiProvider('openai_compatible', AiCompletionResult::success('ok'));
        $manager = $this->manager(
            ['ai.provider' => 'local', 'ai.fallback.enabled' => true, 'ai.fallback.providers' => ['nvidia_nim', 'openai_compatible']],
            ['local' => $local, 'nvidia_nim' => $unhealthy, 'openai_compatible' => $fallback]
        );

        $result = $manager->chat([ChatMessage::user('hi')], null, []);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $local->createdCount());
        // Health checks gate fallback candidates: a dead provider is never
        // attempted, so the loop moves on to the next healthy candidate.
        $this->assertSame(0, $unhealthy->createdCount());
        $this->assertSame(1, $fallback->createdCount());
    }

    public function testSafeConfigRedactsApiKeys(): void
    {
        $manager = $this->manager([
            'ai.provider' => 'openai_compatible',
            'ai' => [
                'provider' => 'openai_compatible',
                'providers' => [
                    'openai_compatible' => ['api_key' => 'sk-secret', 'base_url' => 'https://x.test/v1'],
                ],
            ],
        ]);

        $config = $manager->safeConfig();
        $key = (string) ($config['providers']['openai_compatible']['api_key'] ?? '');

        $this->assertStringNotContainsString('sk-secret', $key);
        $this->assertStringContainsString('***', $key);
    }

    public function testChatWithThrowingProviderReturnsProviderError(): void
    {
        $throwing = new ThrowingAiProvider('local');
        $manager = $this->manager(
            ['ai.provider' => 'local', 'ai.fallback.enabled' => false],
            ['local' => $throwing]
        );

        $result = $manager->chat([ChatMessage::user('hi')], null, []);

        $this->assertSame(AiCompletionResult::STATUS_PROVIDER_ERROR, $result->getStatus());
        // A thrown provider exception is folded into PROVIDER_ERROR, which is
        // deliberately retryable/fallback-eligible (see AiCompletionResult).
        $this->assertTrue($result->isRetryable());
    }

    /* =================================================================
     * Helpers
     * ================================================================= */

    /** @param array<string, array<string, AiProviderInterface>> $providers */
    private function manager(array $configMap, array $providers = []): TestableAiProviderManager
    {
        return new TestableAiProviderManager($configMap, $providers);
    }
}
/**
 * TestableAiProviderManager — subclass that stubs config reads and provider
 * construction so fallback orchestration is fully deterministic.
 */
final class TestableAiProviderManager extends AiProviderManager
{
    /** @var array<string, string|bool|array> */
    private array $configMap;

    /** @var array<string, AiProviderInterface> */
    private array $providers;

    public function __construct(array $configMap, array $providers)
    {
        parent::__construct();
        $this->configMap = $configMap;
        $this->providers = $providers;
    }

    public function selfProvider(): AiProviderInterface
    {
        return $this->provider();
    }

    public function selfChat(array $messages, ?array $tools, array $options): AiCompletionResult
    {
        return $this->chat($messages, $tools, $options);
    }

    protected function configValue(string $key, mixed $default = null): mixed
    {
        return $this->configMap[$key] ?? $default;
    }

    protected function newProvider(string $driver, array $config): AiProviderInterface
    {
        if (!isset($this->providers[$driver])) {
            throw AiProviderException::unknownDriver($driver);
        }
        return $this->providers[$driver];
    }
}

/** Deterministic fake provider. */
final class FakeAiProvider implements AiProviderInterface
{
    private int $created = 0;

    public function __construct(
        private string $name,
        private AiCompletionResult $result,
        private bool $healthy = true
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function chat(array $messages, ?array $tools = null, array $options = []): AiCompletionResult
    {
        $this->created++;
        return $this->result;
    }

    public function isHealthy(): bool
    {
        return $this->healthy;
    }

    public function model(): string
    {
        return 'fake-' . $this->name;
    }

    public function createdCount(): int
    {
        return $this->created;
    }
}

/** Deterministic provider that simulates an unexpected exception. */
final class ThrowingAiProvider implements AiProviderInterface
{
    public function __construct(private string $name)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function chat(array $messages, ?array $tools = null, array $options = []): AiCompletionResult
    {
        throw new \RuntimeException('boom');
    }

    public function isHealthy(): bool
    {
        return true;
    }

    public function model(): string
    {
        return 'fake-' . $this->name;
    }
}
<?php

declare(strict_types=1);

namespace App\Services\AI\Contracts;

use App\Services\AI\AiCompletionResult;

/**
 * AiProviderInterface — the provider-independent AI contract.
 *
 * Chat logic (the controlled-tool layer, Phase 5, and the conversation loop,
 * Phase 6) depends ONLY on this interface. Adding a model host means adding a
 * driver that implements it — never rewriting orchestration.
 *
 * The interface deliberately exposes NO SQL, NO raw action execution and NO
 * unrestricted tool invocation. It describes a read-focused, labelled
 * assistant: everything it may "do" is a controlled tool routed through
 * AuthorizationService by the Phase 5 layer, independent of the provider.
 */
interface AiProviderInterface
{
    /** Driver id from config, e.g. 'local' | 'nvidia_nim' | 'openai_compatible'. */
    public function name(): string;

    /**
     * Complete a chat conversation, optionally with tool definitions.
     *
     * @param MessageInterface[] $messages system + user + assistant history
     * @param array|null         $tools    [{name, description, parameters}]
     * @param array              $options  driver hints (temperature, max_tokens)
     * @return AiCompletionResult
     */
    public function chat(array $messages, ?array $tools = null, array $options = []): AiCompletionResult;

    /**
     * Minimal liveness probe used by the health check / bootstrap fallback.
     * Must be cheap and MUST NOT throw; cached by AiProviderManager.
     */
    public function isHealthy(): bool;

    /** Provider-native model id string (for logs / audit / usage rows). */
    public function model(): string;
}

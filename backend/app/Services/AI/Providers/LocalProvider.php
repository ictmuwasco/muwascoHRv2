<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

/**
 * LocalProvider — FREE, self-hosted local model driver (Ollama-compatible
 * /v1/chat/completions). Default AI_PROVIDER. No API key required; local
 * gateways may expose an optional key which is honoured when configured.
 *
 * Tool calling is supported when the configured model supports it
 * (supports_tools defaults to true for modern llama3+ builds; disable via
 * config if the deployed model lacks function support).
 */
final class LocalProvider extends AbstractOpenAiCompatibleProvider
{
    public function name(): string
    {
        return 'local';
    }

    public function isHealthy(): bool
    {
        if ($this->baseUrl === '') {
            return false;
        }
        // Ollama exposes /api/tags; a 200 with any body means the daemon is up.
        $result = $this->transport->getJson(
            $this->baseUrl . '/api/tags',
            [],
            min(5, $this->timeoutSeconds),
            $this->connectTimeout
        );
        if ($result['ok'] && $result['http_code'] >= 200 && $result['http_code'] < 300) {
            return true;
        }
        // Fall back to the generic /models probe for non-Ollama gateways.
        return parent::isHealthy();
    }
}
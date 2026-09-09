<?php

declare(strict_types=1);

/**
 * AI Provider Configuration
 *
 * Server-side, provider-independent configuration for the AI assistance
 * layer. This file is the SINGLE source of truth for which AI provider is
 * active and for the guarded limits applied to every completion call.
 *
 * The provider is selected through the AI_PROVIDER env var and swapped by
 * adding a driver — never by rewriting chat logic. Provider API keys live
 * EXCLUSIVELY in server-side env; they are never exposed to the React
 * frontend. If configuration is ever logged or serialized for debugging, the
 * api_key values MUST first be replaced with '***' (AiProviderManager::KEY_REDACTION
 * — the manager exposes AiProviderManager::safeConfig() for exactly that).
 *
 * Defaults deliberately favour the FREE, self-hosted, local (Ollama-style)
 * provider. OpenAI-compatible / NVIDIA drivers exist behind configuration so
 * the organisation can switch without code changes, but no cloud provider is
 * contacted unless AI_PROVIDER (or an enabled fallback) points at it.
 *
 * Place: backend/config/ai.php
 */

return [

    /**
     * Active provider driver: 'local' | 'nvidia_nim' | 'openai_compatible'.
     * Default 'local' = free, self-hosted, no API key required.
     */
    'provider' => env('AI_PROVIDER', 'local'),

    'providers' => [

        // Free, self-hosted local model (Ollama-compatible /v1/chat/completions).
        'local' => [
            'driver'          => \App\Services\AI\Providers\LocalProvider::class,
            'base_url'        => (string) env('AI_LOCAL_BASE_URL', 'http://127.0.0.1:11434'),
            'model'           => (string) env('AI_LOCAL_MODEL', 'llama3'),
            'api_key'         => env('AI_LOCAL_API_KEY', null),   // usually none
            'timeout_seconds' => (int) env('AI_LOCAL_TIMEOUT', 90),
            'connect_timeout' => 5,
            'max_tokens'      => (int) env('AI_LOCAL_MAX_TOKENS', 2048),
            'max_retries'     => 2,
            'retry_backoff_ms'=> 500,
        ],

        // NVIDIA-hosted model (OpenAI-compatible NIM endpoints).
        'nvidia_nim' => [
            'driver'          => \App\Services\AI\Providers\NvidiaNimProvider::class,
            'base_url'        => (string) env('AI_NVIDIA_BASE_URL', 'https://integrate.api.nvidia.com'),
            'model'           => (string) env('AI_NVIDIA_MODEL', 'nvidia/nemotron-3.5-lightning-30b-a3b'),
            'api_key'         => env('AI_NVIDIA_API_KEY', ''),
            'timeout_seconds' => (int) env('AI_NVIDIA_TIMEOUT', 90),
            'connect_timeout' => 10,
            'max_tokens'      => (int) env('AI_NVIDIA_MAX_TOKENS', 2048),
            'max_retries'     => (int) env('AI_NVIDIA_MAX_RETRIES', 1),
            'retry_backoff_ms'=> 750,
            // nemotron models handle OpenAI-style function calling; local stays off.
            'supports_tools'  => filter_var(env('AI_NVIDIA_SUPPORTS_TOOLS', true), FILTER_VALIDATE_BOOLEAN),
            // Provider-specific body params merged verbatim into every chat
            // completion request (JSON string in env). Used e.g. to disable
            // chain-of-thought on reasoning models:
            //   AI_NVIDIA_EXTRA_BODY={"chat_template_kwargs":{"thinking":false}}
            'extra_body'      => json_decode((string) env('AI_NVIDIA_EXTRA_BODY', ''), true) ?? [],
        ],

        // Generic OpenAI-compatible host (OpenAI, Azure, Together, Groq, ...).
        'openai_compatible' => [
            'driver'          => \App\Services\AI\Providers\OpenAiCompatibleProvider::class,
            'base_url'        => (string) env('AI_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model'           => (string) env('AI_OPENAI_MODEL', 'gpt-4o-mini'),
            'api_key'         => env('AI_OPENAI_API_KEY', ''),
            'timeout_seconds' => (int) env('AI_OPENAI_TIMEOUT', 90),
            'connect_timeout' => 10,
            'max_tokens'      => (int) env('AI_OPENAI_MAX_TOKENS', 2048),
            'max_retries'     => 2,
            'retry_backoff_ms'=> 750,
        ],
    ],

    /**
     * Automatic fallback to another provider. Disabled by default so nothing
     * ever silently phones a paid/cloud provider. When enabled, only
     * retryable statuses (TEMPORARY_FAILURE / RATE_LIMITED / TIMEOUT /
     * PROVIDER_ERROR) may trigger a fallback — never permanent failures or
     * any client-side authorization denial.
     */
    'fallback' => [
        'enabled'   => filter_var(env('AI_FALLBACK_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'providers' => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_FALLBACK_PROVIDERS', 'openai_compatible'))))),
        'only_on'   => ['TEMPORARY_FAILURE', 'RATE_LIMITED', 'TIMEOUT', 'PROVIDER_ERROR'],
    ],

    /**
     * Global request/response guards. Bounds cost, latency and data leakage
     * regardless of provider. Applied by the conversation layer (Phase 6/11)
     * and enforced here as a last line of defence.
     */
    'request' => [
        'max_request_chars'    => (int) env('AI_MAX_REQUEST_CHARS', 4000),
        'max_response_chars'   => (int) env('AI_MAX_RESPONSE_CHARS', 8000),
        'max_history_messages' => (int) env('AI_MAX_HISTORY_MESSAGES', 20),
        'default_temperature'  => (float) env('AI_TEMPERATURE', 0.2),
    ],

    /**
     * Controlled HR data tools (Phase 5). The model can only REQUEST these
     * registered, permission-checked, owner-scoped tools; execution always
     * happens server-side and every invocation is written to ai_tool_calls.
     */
    'tools' => [
        'enabled'   => filter_var(env('AI_TOOLS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'max_calls' => (int) env('AI_MAX_TOOL_CALLS', 3),
    ],

    'health' => [
        // How long a healthy probe is trusted before re-checking.
        'cached_seconds' => (int) env('AI_HEALTH_CACHE_SECONDS', 30),
    ],

    'logging' => [
        // Persist usage rows (ai_usage_logs, Phase 4) on every completion.
        'usage_enabled'          => filter_var(env('AI_USAGE_LOGGING', true), FILTER_VALIDATE_BOOLEAN),
        // Retention horizon used by the Phase 4 purge/retention policy.
        'content_retention_days' => (int) env('AI_CONTENT_RETENTION_DAYS', 90),
    ],
];

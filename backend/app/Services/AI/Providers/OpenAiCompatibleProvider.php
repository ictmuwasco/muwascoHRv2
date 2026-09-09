<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

/**
 * OpenAiCompatibleProvider — generic OpenAI-compatible host driver (OpenAI,
 * Azure, Together, Groq, ...). Works with any host exposing the standard
 * /v1/chat/completions contract via base_url + model + api_key from config.
 *
 * Not the default: enabled by setting AI_PROVIDER=openai_compatible or via
 * fallback config. Keys stay server-side and are NEVER sent to React.
 */
final class OpenAiCompatibleProvider extends AbstractOpenAiCompatibleProvider
{
    public function name(): string
    {
        return 'openai_compatible';
    }
}
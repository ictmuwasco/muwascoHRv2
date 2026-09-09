<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

/**
 * NvidiaNimProvider — NVIDIA-hosted model driver (NIM OpenAI-compatible
 * endpoints). Requires AI_NVIDIA_API_KEY (server-side env only)。Not the
 * default: enabled by setting AI_PROVIDER=nvidia_nim or via fallback config.

 * Endpoint: POST {base_url}/v1/chat/completions with Bearer auth.
 */
final class NvidiaNimProvider extends AbstractOpenAiCompatibleProvider
{
    public function name(): string
    {
        return 'nvidia_nim';
    }
}
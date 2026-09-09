<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * AiProviderException — raised for programming / configuration errors in the
 * AI provider layer (unknown driver, malformed config, unsupported tool
 * schema), NOT for ordinary transport failures. Transport failures are
 * returned as AiCompletionResult with a structured status so the caller can
 * decide on retry / fallback without exception handling.
 *
 * Messages are safe to surface: they never contain provider bodies, headers,
 * API keys or model output.
 */
final class AiProviderException extends \RuntimeException
{
    public static function unknownDriver(string $driver): self
    {
        return new self("Unknown AI provider driver '{$driver}'.");
    }

    public static function missingDriverClass(string $driver, string $class): self
    {
        return new self("AI driver '{$driver}' maps to missing class '{$class}'.");
    }

    public static function baseUrlMissing(string $driver): self
    {
        return new self("AI provider '{$driver}' has no base_url configured.");
    }
}

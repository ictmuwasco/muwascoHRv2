<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * AiSanitizer — pure, stateless input/output hygiene for the AI layer.
 *
 *   - sanitizeUserMessage: strips control characters / normalizes whitespace
 *     and enforces the config cap (ai.request.max_request_chars) BEFORE the
 *     message is stored or forwarded to a provider.
 *   - sanitizeAssistantContent: output validation — caps the provider reply
 *     (ai.request.max_response_chars) and removes invisible characters so a
 *     model can never smuggle control sequences into the UI.
 *   - detectPromptInjection: HIGH-PRECISION heuristics only. A match blocks
 *     the turn (audited); a non-match never grants anything — authorization
 *     for data access lives exclusively in the Phase 5 controlled-tool layer,
 *     so the cost of a missed pattern is bounded by what the caller may
 *     already see.
 *
 * Place: backend/app/Services/AI/AiSanitizer.php
 */
final class AiSanitizer
{
    /** Hard cap applied to stored feedback comments regardless of config. */
    public const MAX_FEEDBACK_COMMENT_CHARS = 500;

    /**
     * High-precision prompt-injection patterns => audit label. Deliberately
     * narrow: blocking a legitimate HR question is worse than forwarding a
     * weak injection attempt, because the tool layer already guarantees that
     * the model can only see authorized data.
     *
     * @return array<string, string> regex => label
     */
    private const INJECTION_PATTERNS = [
        '/ignore\s+(all\s+|any\s+|the\s+)?(previous|prior|above|earlier)\s+(instructions|prompts?|rules?|guardrails)/i' => 'instruction_override',
        '/disregard\s+(all\s+|the\s+|your\s+)?(previous\s+|prior\s+|above\s+)?(instructions|rules|guardrails|prompts?)/i' => 'instruction_override',
        '/(reveal|show|print|repeat|output|what\s+is)\s+(me\s+)?(your|the)\s+(system\s+|hidden\s+|secret\s+)?(prompt|instructions|rules)/i' => 'prompt_extraction',
        '/you\s+are\s+now\s+(a|an|the)\b/i' => 'persona_hijack',
        '/pretend\s+(you\s+are|to\s+be)\b/i' => 'persona_hijack',
        '/act\s+as\s+if\s+you\s+(were|are)\b/i' => 'persona_hijack',
        '/(developer|god|admin|dan)\s+mode\b/i' => 'mode_hijack',
        '/\bjailbreak\b/i' => 'mode_hijack',
        '/bypass\s+(the\s+)?(rules?|restrictions?|filters?|permissions?|authorization)/i' => 'authorization_bypass',
    ];

    /**
     * Normalize + cap a user chat message.
     */
    public static function sanitizeUserMessage(string $raw, int $maxChars): string
    {
        return self::clean($raw, $maxChars);
    }

    /**
     * Normalize + cap a provider reply before it is stored or shown.
     */
    public static function sanitizeAssistantContent(string $raw, int $maxChars): string
    {
        return self::clean($raw, $maxChars);
    }

    /**
     * Normalize a feedback comment (hard-capped at MAX_FEEDBACK_COMMENT_CHARS).
     */
    public static function sanitizeFeedbackComment(string $raw): string
    {
        return self::clean($raw, self::MAX_FEEDBACK_COMMENT_CHARS);
    }

    /**
     * Return the matched injection label, or null when no high-confidence
     * pattern matched. The label is safe for logs/audit (never the payload).
     */
    public static function detectPromptInjection(string $text): ?string
    {
        foreach (self::INJECTION_PATTERNS as $pattern => $label) {
            if (preg_match($pattern, $text) === 1) {
                return $label;
            }
        }
        return null;
    }

    /**
     * Shared cleaning: strip control characters (keep \n and \t), remove
     * zero-width/invisible Unicode, normalize line endings, collapse runs of
     * blank lines and hard-cap the length.
     */
    private static function clean(string $raw, int $maxChars): string
    {
        $maxChars = max(1, $maxChars);

        // Normalize newlines first so the control-char strip keeps \n only.
        $text = str_replace(["\r\n", "\r"], "\n", $raw);

        // Remove UTF-8 zero-width / invisible formatting characters.
        $text = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u', '', $text) ?? '';

        // Strip control characters except \n and \t.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? '';

        // Collapse 3+ consecutive newlines into a paragraph break.
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';

        $text = trim($text);
        return mb_substr($text, 0, $maxChars);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Services\ErrorTracking\RequestIdService;

/**
 * AiUsageLogger — best-effort persistence of one completion record into
 * `ai_usage_logs` (migration 041). Metadata ONLY: sizes, status, provider,
 * model, correlation id. Message content is NEVER written here.
 *
 * Failures are swallowed (error_log) — telemetry must never break the chat
 * path, mirroring AuditService conventions.
 *
 * Place: backend/app/Services/AI/AiUsageLogger.php
 */
final class AiUsageLogger
{
    /**
     * @param array{
     *   user_id?:int|null, conversation_id?:string|null, provider:string,
     *   model?:string|null, status:string, attempts?:int, http_status?:int|null,
     *   prompt_chars?:int, response_chars?:int, error_code?:string|null
     * } $row
     */
    public static function log(array $row): void
    {
        try {
            db()->insert('ai_usage_logs', [
                'user_id'         => $row['user_id'] ?? null,
                'conversation_id' => $row['conversation_id'] ?? null,
                'provider'        => mb_substr((string) $row['provider'], 0, 50),
                'model'           => isset($row['model']) ? mb_substr((string) $row['model'], 0, 100) : null,
                'status'          => mb_substr((string) $row['status'], 0, 30),
                'attempts'        => max(1, (int) ($row['attempts'] ?? 1)),
                'http_status'     => isset($row['http_status']) ? (int) $row['http_status'] : null,
                'prompt_chars'    => max(0, (int) ($row['prompt_chars'] ?? 0)),
                'response_chars'  => max(0, (int) ($row['response_chars'] ?? 0)),
                'error_code'      => isset($row['error_code']) ? mb_substr((string) $row['error_code'], 0, 50) : null,
                'request_id'      => RequestIdService::current(),
            ]);
        } catch (\Throwable $e) {
            \error_log('[AiUsageLogger] failed to persist usage log: ' . $e->getMessage());
        }
    }
}

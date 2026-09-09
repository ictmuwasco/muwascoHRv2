<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\Database;

/**
 * AiConversationRepository — all persistence for the AI assistant layer.
 *
 * SECURITY MODEL (Phase 4/11): every read and write is scoped to the
 * conversation owner. The user_id is ALWAYS taken from the authenticated
 * session inside AiAssistantService and bound as a query parameter — it is
 * never accepted from the request body. A conversation id that exists but is
 * not owned by the caller is indistinguishable from a missing one (404), so
 * ids cannot be enumerated.
 *
 * Only parameterized statements are used; table/column identifiers are a
 * hardcoded allowlist, never request input.
 *
 * Place: backend/app/Repositories/AiConversationRepository.php
 */
class AiConversationRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Create a conversation owned by $userId and return its UUID.
     */
    public function createConversation(int $userId, string $uuid, string $title): string
    {
        $this->db->insert('ai_conversations', [
            'id'              => $uuid,
            'user_id'         => $userId,
            'title'           => $title,
            'message_count'   => 0,
            'last_message_at' => date('Y-m-d H:i:s'),
        ]);
        return $uuid;
    }

    /**
     * Owner-scoped conversation fetch. Returns null when the conversation
     * does not exist OR belongs to someone else (never leak existence).
     *
     * @return array<string,mixed>|null
     */
    public function findConversation(string $conversationId, int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, user_id, title, message_count, last_message_at, created_at
               FROM ai_conversations
              WHERE id = ? AND user_id = ?',
            'si',
            [$conversationId, $userId]
        );
    }

    /**
     * Most recent conversation for the user (used when the client sends an
     * empty conversation id to continue the current chat).
     *
     * @return array<string,mixed>|null
     */
    public function latestConversation(int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, user_id, title, message_count, last_message_at, created_at
               FROM ai_conversations
              WHERE user_id = ?
              ORDER BY last_message_at DESC
              LIMIT 1',
            'i',
            [$userId]
        );
    }

    /**
     * Append a turn. Content must already be sanitized by the service layer.
     *
     * @param array<int, array{type:string,label:string}>|null $sources
     * @param array<int, string>|null                          $toolsUsed
     */
    public function addMessage(
        string $conversationId,
        int $userId,
        string $role,
        string $content,
        ?array $sources = null,
        ?array $toolsUsed = null,
        ?string $provider = null,
        ?string $model = null,
        string $status = 'ok',
        ?string $errorCode = null,
        ?int $latencyMs = null
    ): int {
        $this->db->insert('ai_messages', [
            'conversation_id' => $conversationId,
            'user_id'         => $userId,
            'role'            => $role,
            'content'         => $content,
            'sources'         => $sources !== null ? json_encode($sources) : null,
            'tools_used'      => $toolsUsed !== null ? json_encode($toolsUsed) : null,
            'provider'        => $provider,
            'model'           => $model,
            'status'          => $status,
            'error_code'      => $errorCode,
            'latency_ms'      => $latencyMs,
        ]);

        $messageId = (int) $this->db->lastInsertId();

        $this->db->query(
            'UPDATE ai_conversations
                SET message_count = message_count + 1,
                    last_message_at = CURRENT_TIMESTAMP
              WHERE id = ? AND user_id = ?',
            'si',
            [$conversationId, $userId]
        );

        return $messageId;
    }

    /**
     * Ordered transcript (oldest first) capped to the most recent $limit
     * turns — inner query picks the newest N owner-scoped rows, outer
     * re-sorts chronologically so the transcript renders in order.
     *
     * @return array<int, array<string,mixed>>
     */
    public function getMessages(string $conversationId, int $userId, int $limit = 200): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM (
                 SELECT id, role, content, sources, tools_used, status, error_code, created_at
                   FROM ai_messages
                  WHERE conversation_id = ? AND user_id = ?
                  ORDER BY id DESC
                  LIMIT ' . (int) $limit . '
             ) AS recent
             ORDER BY recent.id ASC',
            'si',
            [$conversationId, $userId]
        );
    }

    /**
     * Recent successful turns used as bounded LLM context (role + content).
     *
     * @return array<int, array{role:string,content:string}>
     */
    public function getContextMessages(string $conversationId, int $userId, int $limit): array
    {
        $rows = $this->db->fetchAll(
            'SELECT role, content FROM (
                 SELECT role, content
                   FROM ai_messages
                  WHERE conversation_id = ? AND user_id = ? AND status = \'ok\'
                  ORDER BY id DESC
                  LIMIT ' . (int) $limit . '
             ) AS recent
             ORDER BY recent.id ASC',
            'si',
            [$conversationId, $userId]
        );

        return array_map(
            static fn (array $row): array => ['role' => (string) $row['role'], 'content' => (string) $row['content']],
            $rows
        );
    }

    /**
     * Delete all turns and reset counters. The conversation shell is kept so
     * the client's open panel keeps working after "Clear conversation".
     */
    public function clearMessages(string $conversationId, int $userId): void
    {
        $this->db->query(
            'DELETE FROM ai_messages WHERE conversation_id = ? AND user_id = ?',
            'si',
            [$conversationId, $userId]
        );
        $this->db->query(
            'UPDATE ai_conversations
                SET message_count = 0, title = NULL, last_message_at = CURRENT_TIMESTAMP
              WHERE id = ? AND user_id = ?',
            'si',
            [$conversationId, $userId]
        );
    }

    /**
     * Feedback bookkeeping: one row per (message, user).
     */
    public function setFeedback(int $messageId, int $userId, string $rating): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->query(
            'INSERT INTO ai_feedback (message_id, user_id, rating, created_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), created_at = VALUES(created_at)',
            'iiss',
            [$messageId, $userId, $rating, $now]
        );
    }

    /**
     * Owner-scoped message fetch (feedback validation).
     *
     * @return array<string,mixed>|null
     */
    public function findMessage(int $messageId, int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, conversation_id, user_id, role, status
               FROM ai_messages
              WHERE id = ? AND user_id = ?',
            'ii',
            [$messageId, $userId]
        );
    }

    /**
     * Record one completion telemetry row (metadata only — never content).
     */
    public function logUsage(array $entry): void
    {
        $this->db->insert('ai_usage_logs', [
            'user_id'         => $entry['user_id'],
            'conversation_id' => $entry['conversation_id'],
            'provider'        => (string) $entry['provider'],
            'model'           => $entry['model'],
            'status'          => (string) $entry['status'],
            'attempts'        => (int) ($entry['attempts'] ?? 1),
            'http_status'     => $entry['http_status'],
            'prompt_chars'    => (int) ($entry['prompt_chars'] ?? 0),
            'response_chars'  => (int) ($entry['response_chars'] ?? 0),
            'error_code'      => $entry['error_code'],
            'request_id'      => $entry['request_id'],
        ]);
    }

    /**
     * Retention sweep: delete conversations whose last activity is older than
     * $days. Content cascades (ai_messages FK ON DELETE CASCADE); usage logs
     * survive deliberately (metadata-only accounting).
     *
     * @return int Number of conversations removed.
     */
    public function purgeExpired(int $days): int
    {
        if ($days <= 0) {
            return 0; // Retention disabled by configuration.
        }

        $countRow = $this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM ai_conversations
              WHERE last_message_at < (NOW() - INTERVAL ? DAY)',
            'i',
            [$days]
        );
        $count = (int) ($countRow['c'] ?? 0);
        if ($count === 0) {
            return 0;
        }

        $this->db->query(
            'DELETE FROM ai_conversations
              WHERE last_message_at < (NOW() - INTERVAL ? DAY)',
            'i',
            [$days]
        );
        return $count;
    }
}
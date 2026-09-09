<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Helpers\Database;

/**
 * Applies migration 041_ai_assistant.sql (idempotent):
 *   - Creates the AI-layer tables: ai_conversations, ai_messages,
 *     ai_usage_logs, ai_tool_calls, ai_feedback, ai_prompt_versions.
 *   - Seeds the ACTIVE v1 system prompt (INSERT IGNORE — never clobbers a
 *     newer version on re-run).
 *
 * All AI tables are strictly owner-scoped (user_id) and store sanitized
 * content only — no provider keys, no raw provider payloads, no unnecessary
 * sensitive HR data (see the migration header for the full security notes).
 * Chat-content retention (AI_CONTENT_RETENTION_DAYS) is enforced by the
 * Phase 5 cron sweep — no MySQL EVENT is created by this migration.
 *
 * Usage: php backend/database/run_migration_041.php
 */

try {
    $db   = Database::getInstance();
    $conn = $db->getConnection();

    $sql = file_get_contents(__DIR__ . '/migrations/041_ai_assistant.sql');

    if ($conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
        if ($conn->errno) {
            echo 'Error executing migration 041: ' . $conn->error . "\n";
            exit(1);
        }
        echo "041_ai_assistant.sql executed successfully\n";
    } else {
        echo 'Error executing migration 041: ' . $conn->error . "\n";
        exit(1);
    }

    // ---- Verification -------------------------------------------------------
    $checks = [
        'ai_conversations table exists (expect 1)' =>
            "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ai_conversations'",
        'ai_messages table exists (expect 1)' =>
            "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ai_messages'",
        'ai_usage_logs table exists (expect 1)' =>
            "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ai_usage_logs'",
        'ai_tool_calls table exists (expect 1)' =>
            "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ai_tool_calls'",
        'ai_feedback table exists (expect 1)' =>
            "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ai_feedback'",
        'ai_prompt_versions table exists (expect 1)' =>
            "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ai_prompt_versions'",
        'active v1 system prompt seeded (expect 1)' =>
            "SELECT COUNT(*) AS c FROM ai_prompt_versions WHERE name = 'muwasco_hr_assistant' AND version = 1 AND is_active = 1",
        'ai_conversations.id is CHAR(36) (expect 1)' =>
            "SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ai_conversations' AND column_name = 'id' AND data_type = 'char' AND character_maximum_length = 36",
        'ai_feedback one vote per user+message (expect 1)' =>
            "SELECT COUNT(DISTINCT index_name) AS c FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'ai_feedback' AND index_name = 'uk_ai_feedback_message_user'",
    ];

    $failed = false;
    foreach ($checks as $label => $query) {
        $result = $conn->query($query);
        $count = $result ? (int) ($result->fetch_assoc()['c'] ?? 0) : -1;
        echo str_pad($label, 60) . " => {$count}\n";
        if ($count <= 0) {
            $failed = true;
        }
    }

    echo $failed
        ? "Migration 041 completed WITH WARNINGS — review the checks above.\n"
        : "Migration 041 completed successfully — all checks passed.\n";
    exit($failed ? 1 : 0);
} catch (Throwable $e) {
    echo 'Migration 041 failed: ' . $e->getMessage() . "\n";
    exit(1);
}

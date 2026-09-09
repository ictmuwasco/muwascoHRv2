<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Helpers\Database;

/**
 * One-shot maintenance script for migration 041 (AI assistant tables).
 *
 * A partial earlier run of 041 may have left an ai_prompt_versions table with
 * an older schema (the migration was restructured before completion). Because
 * the migration uses CREATE TABLE IF NOT EXISTS, a stale table would silently
 * keep the old shape and break the seed INSERT.
 *
 * This script drops ONLY the brand-new 041 AI tables (no production data can
 * exist in them — they are introduced by this migration) and prints the
 * expected follow-up command. The canonical 041 runner then recreates them:
 *
 *   php backend/database/reset_ai_tables_041.php
 *   php backend/database/run_migration_041.php
 */

$tables = [
    'ai_knowledge_chunks',
    'ai_knowledge_documents',
    'ai_feedback',
    'ai_tool_calls',
    'ai_usage_logs',
    'ai_messages',
    'ai_conversations',
    'ai_prompt_versions',
];

try {
    $conn = Database::getInstance()->getConnection();
    $conn->query('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        $conn->query('DROP TABLE IF EXISTS `' . $table . '`');
        echo 'dropped ' . $table . PHP_EOL;
    }
    $conn->query('SET FOREIGN_KEY_CHECKS=1');
    echo 'OK — now run: php backend/database/run_migration_041.php' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    echo 'Reset failed: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

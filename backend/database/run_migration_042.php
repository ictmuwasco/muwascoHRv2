<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Helpers\Database;

/**
 * Applies migration 042_performance_instrumentation.sql (idempotent):
 *   - Adds the Phase 2 per-phase breakdown columns to performance_events:
 *     query_count, query_ms, max_query_ms, auth_ms, authorization_ms,
 *     controller_ms, serialization_ms, ai_provider_ms, ai_provider_calls,
 *     ai_tool_calls, external_http_ms.
 *
 * Every column is nullable metadata (durations/counts only); no SQL text,
 * tokens, prompts, headers or bodies are ever stored.
 *
 * Usage: php backend/database/run_migration_042.php
 */

try {
    $db   = Database::getInstance();
    $conn = $db->getConnection();

    // Preferred column definitions for the Phase 2 breakdown (metadata only).
    $defs = [
        'query_count'         => 'INT UNSIGNED NULL',
        'query_ms'            => 'INT UNSIGNED NULL',
        'max_query_ms'        => 'INT UNSIGNED NULL',
        'auth_ms'             => 'INT UNSIGNED NULL',
        'authorization_ms'    => 'INT UNSIGNED NULL',
        'controller_ms'       => 'INT UNSIGNED NULL',
        'serialization_ms'    => 'INT UNSIGNED NULL',
        'ai_provider_ms'      => 'INT UNSIGNED NULL',
        'ai_provider_calls'   => 'SMALLINT UNSIGNED NULL',
        'ai_tool_calls'       => 'SMALLINT UNSIGNED NULL',
        'external_http_ms'    => 'INT UNSIGNED NULL',
    ];

    // Compute which columns are missing (idempotent; MySQL lacks ADD COLUMN IF
    // NOT EXISTS). Build ONE ALTER TABLE for all missing columns so the apply
    // is a single atomic DDL statement — robust on MySQL and MariaDB alike.
    $existing = [];
    $res = $conn->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'performance_events'"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $existing[strtoupper((string) $row['COLUMN_NAME'])] = true;
        }
        $res->free();
    }

    $adds = [];
    foreach ($defs as $column => $type) {
        if (!isset($existing[strtoupper($column)])) {
            $adds[] = "ADD COLUMN `{$column}` {$type}";
        }
    }

    if ($adds !== []) {
        $conn->query('ALTER TABLE `performance_events` ' . implode(', ', $adds));
        echo 'Added ' . count($adds) . " missing column(s).\n";
    } else {
        echo "All columns already present — nothing to do.\n";
    }

    echo "042_performance_instrumentation.sql applied successfully\n";

    // ---- Verification -------------------------------------------------------
    $failed = false;
    foreach (array_keys($defs) as $column) {
        $count = (int) ($db->fetchValue(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'performance_events' AND column_name = ?",
            's',
            [$column]
        ) ?? 0);
        echo str_pad('performance_events.' . $column, 60) . " => {$count}\n";
        if ($count <= 0) {
            $failed = true;
        }
    }

    echo $failed
        ? "Migration 042 completed WITH WARNINGS — review the checks above.\n"
        : "Migration 042 completed successfully — all checks passed.\n";
    exit($failed ? 1 : 0);
} catch (Throwable $e) {
    echo 'Migration 042 failed: ' . $e->getMessage() . "\n";
    exit(1);
}
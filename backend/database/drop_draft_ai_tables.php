<?php

declare(strict_types=1);

/**
 * ONE-OFF dev repair: drop the draft AI tables from migration 041's earlier
 * (pre-final) run so the finalized migration can apply cleanly. NOT part of
 * the repository deliverables — deleted after use.
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Helpers\Database;

$conn = Database::getInstance()->getConnection();

// Children before parents (FK constraints).
$tables = [
    'ai_tool_calls',
    'ai_feedback',
    'ai_messages',
    'ai_conversations',
    'ai_usage_logs',
    'ai_prompt_versions',
];

foreach ($tables as $table) {
    $conn->query("DROP TABLE IF EXISTS `{$table}`");
    echo "dropped {$table}\n";
}

echo "done\n";
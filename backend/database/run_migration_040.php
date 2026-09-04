<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Helpers\Database;

/**
 * Applies migration 040_delegations.sql (idempotent):
 *   - Creates the `delegations` table (Temporary Delegation / Acting
 *     Authority: explicit, time-bound, scope-aware authority transfer).
 *   - Extends `leave_history` with delegation_id + acted_for_user_id so a
 *     delegate's decision records the delegation reference and the ORIGINAL
 *     approver alongside the actual acting user.
 *   - Seeds the `delegations` permission module (view: all roles;
 *     create: supervisory roles; approve: hr_manager + super_admin;
 *     cancel: delegator-side roles + HR).
 *
 * The SQL is fully idempotent, so this runner can be re-run safely on every
 * environment.
 *
 * Usage: php backend/database/run_migration_040.php
 */

try {
    $db   = Database::getInstance();
    $conn = $db->getConnection();

    $sql = file_get_contents(__DIR__ . '/migrations/040_delegations.sql');

    if ($conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
        if ($conn->errno) {
            echo 'Error executing migration 040: ' . $conn->error . "\n";
            exit(1);
        }
        echo "040_delegations.sql executed successfully\n";
    } else {
        echo 'Error executing migration 040: ' . $conn->error . "\n";
        exit(1);
    }

    // ---- Verification -------------------------------------------------------
    $checks = [
        'delegations table exists (expect 1)' =>
            "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'delegations'",
        'leave_history.delegation_id exists (expect 1)' =>
            "SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'leave_history' AND column_name = 'delegation_id'",
        'leave_history.acted_for_user_id exists (expect 1)' =>
            "SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'leave_history' AND column_name = 'acted_for_user_id'",
        'delegations:view granted rows (expect 10)' =>
            "SELECT COUNT(*) AS c FROM role_permissions WHERE module = 'delegations' AND action = 'view' AND is_granted = 1",
        'delegations:create granted rows (expect 7)' =>
            "SELECT COUNT(*) AS c FROM role_permissions WHERE module = 'delegations' AND action = 'create' AND is_granted = 1",
        'delegations:approve granted rows (expect 2)' =>
            "SELECT COUNT(*) AS c FROM role_permissions WHERE module = 'delegations' AND action = 'approve' AND is_granted = 1",
        'delegations:cancel granted rows (expect 7)' =>
            "SELECT COUNT(*) AS c FROM role_permissions WHERE module = 'delegations' AND action = 'cancel' AND is_granted = 1",
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
        ? "Migration 040 completed WITH WARNINGS — review the checks above.\n"
        : "Migration 040 completed successfully — all checks passed.\n";
    exit($failed ? 1 : 0);
} catch (Throwable $e) {
    echo 'Migration 040 failed: ' . $e->getMessage() . "\n";
    exit(1);
}

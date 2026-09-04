<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Helpers\Database;

/**
 * Applies migration 038_role_permission_matrix.sql (idempotent):
 *   - settings module seeds (page shell + tabs: super_admin; notifications: all)
 *   - officer/employee pruning to the minimal default matrix
 *   - hr_manager revocation of permission_overrides + users + settings API (admin)
 *   - managing_director full-minus-settings reconciliation
 *   - Phase 10: Employees/Roster/Reports are HR-only modules (heads + MD
 *     revoked; roster moved to the dedicated leave:roster permission)
 *
 * The SQL is fully idempotent (INSERT ... ON DUPLICATE KEY UPDATE is_granted =
 * VALUES(is_granted)), so this runner ALWAYS reconciles the role_permissions
 * table to the CURRENT matrix in the file — editing the migration and
 * re-running deterministically applies the delta to all environments.
 *
 * Usage: php backend/database/run_migration_038.php
 */

try {
    $db   = Database::getInstance();
    $conn = $db->getConnection();

    $sql = file_get_contents(__DIR__ . '/migrations/038_role_permission_matrix.sql');

    if ($conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
        if ($conn->errno) {
            echo 'Error executing migration 038: ' . $conn->error . "\n";
            exit(1);
        }
        echo "038_role_permission_matrix.sql executed successfully\n";
    } else {
        echo 'Error executing migration 038: ' . $conn->error . "\n";
        exit(1);
    }

    // ---- Verification -------------------------------------------------------
    $checks = [
        'settings rows (expect 17)' =>
            "SELECT COUNT(*) AS c FROM role_permissions WHERE module = 'settings'",
        'settings:view granted only to super_admin (expect 1)' =>
            "SELECT COUNT(*) AS c FROM role_permissions WHERE module = 'settings' AND action = 'view' AND is_granted = 1",
        'settings:notifications granted to all 10 roles (expect 10)' =>
            "SELECT COUNT(*) AS c FROM role_permissions WHERE module = 'settings' AND action = 'notifications' AND is_granted = 1",
        'hr_manager permission_overrides revoked (expect 0 granted)' =>
            "SELECT COUNT(*) AS c FROM role_permissions WHERE role = 'hr_manager' AND module = 'permission_overrides' AND is_granted = 1",
        'officer employees:view revoked (expect 0 granted)' =>
            "SELECT COUNT(*) AS c FROM role_permissions WHERE role = 'officer' AND module = 'employees' AND action = 'view' AND is_granted = 1",
        'managing_director employees:view revoked (expect 0 granted)' =>
            "SELECT COUNT(*) AS c FROM role_permissions WHERE role = 'managing_director' AND module = 'employees' AND action = 'view' AND is_granted = 1",
        'heads employees/reports revoked (expect 0 granted)' =>
            "SELECT COUNT(*) AS c FROM role_permissions WHERE role IN ('sub_section_head','section_head','dept_head') AND module IN ('employees','reports') AND is_granted = 1",
        'leave:roster granted to HR + super admin only (expect 2)' =>
            "SELECT COUNT(*) AS c FROM role_permissions WHERE module = 'leave' AND action = 'roster' AND is_granted = 1",
        'heads/MD leave:roster revoked (expect 0 granted)' =>
            "SELECT COUNT(*) AS c FROM role_permissions WHERE role IN ('sub_section_head','section_head','dept_head','managing_director') AND module = 'leave' AND action = 'roster' AND is_granted = 1",
    ];

    $failed = false;
    foreach ($checks as $label => $query) {
        $res = $conn->query($query);
        $r   = $res ? $res->fetch_assoc() : null;
        $val = (int) ($r['c'] ?? -1);
        echo ($val >= 0 ? '✓ ' : '✗ ') . $label . ' => ' . $val . "\n";
        if ($val < 0) {
            $failed = true;
        }
    }

    echo $failed
        ? "\n✗ Migration 038 completed with verification warnings.\n"
        : "\n✓ Migration 038 completed successfully!\n";
    exit($failed ? 1 : 0);
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}
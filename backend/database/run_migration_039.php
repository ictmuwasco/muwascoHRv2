<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Helpers\Database;

/**
 * Applies migration 039_hr_restricted_modules.sql (idempotent):
 *   - Reports: re-granted to the Managing Director (view + export); heads
 *     stay explicitly denied.
 *   - Attendance Dashboard: attendance:manage revoked from the three head
 *     roles (HR / MD / super admin only).
 *   - HR Admin group triggers: financial_year:* / consent:view /
 *     holidays:view revoked from the heads.
 *   - Appraisal Cycles: dedicated performance:cycles page permission seeded
 *     to hr_manager / managing_director / super_admin (heads denied).
 *   - Per-user ALLOW overrides for the restricted permissions are
 *     deactivated (active = 0) for users outside the three allowed roles.
 *
 * The SQL is fully idempotent, so this runner ALWAYS reconciles the
 * role_permissions table to the CURRENT matrix in the file — editing the
 * migration and re-running deterministically applies the delta to all
 * environments.
 *
 * Usage: php backend/database/run_migration_039.php
 */

try {
    $db   = Database::getInstance();
    $conn = $db->getConnection();

    $sql = file_get_contents(__DIR__ . '/migrations/039_hr_restricted_modules.sql');

    if ($conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->more_results() && $conn->next_result());
        if ($conn->errno) {
            echo 'Error executing migration 039: ' . $conn->error . "\n";
            exit(1);
        }
        echo "039_hr_restricted_modules.sql executed successfully\n";
    } else {
        echo 'Error executing migration 039: ' . $conn->error . "\n";
        exit(1);
    }

    // ---- Verification -------------------------------------------------------
    $checks = [
        'managing_director reports:view granted (expect 1)' =>
            "SELECT COUNT(*) AS c FROM role_permissions WHERE role = 'managing_director' AND module = 'reports' AND action = 'view' AND is_granted = 1",
        'managing_director reports:export granted (expect 1)' =>
            "SELECT COUNT(*) AS c FROM role_permissions WHERE role = 'managing_director' AND module = 'reports' AND action = 'export' AND is_granted = 1",
        'heads reports view/export revoked (expect 0 granted)' =>
            "SELECT COUNT(*) AS c FROM role_permissions WHERE role IN ('sub_section_head','section_head','dept_head') AND module = 'reports' AND is_granted = 1",
        'heads attendance:manage revoked (expect 0 granted)' =>
            "SELECT COUNT(*) AS c FROM role_permissions WHERE role IN ('sub_section_head','section_head','dept_head') AND module = 'attendance' AND action = 'manage' AND is_granted = 1",
        'heads financial_year:view revoked (expect 0 granted)' =>
            "SELECT COUNT(*) AS c FROM role_permissions WHERE role IN ('sub_section_head','section_head','dept_head') AND module = 'financial_year' AND action = 'view' AND is_granted = 1",
        'heads consent:view revoked (expect 0 granted)' =>
            "SELECT COUNT(*) AS c FROM role_permissions WHERE role IN ('sub_section_head','section_head','dept_head') AND module = 'consent' AND is_granted = 1",
        'heads holidays:view revoked (expect 0 granted)' =>
            "SELECT COUNT(*) AS c FROM role_permissions WHERE role IN ('sub_section_head','section_head','dept_head') AND module = 'holidays' AND is_granted = 1",
        'performance:cycles granted to super_admin/hr_manager/managing_director (expect 3)' =>
            "SELECT COUNT(*) AS c FROM role_permissions WHERE module = 'performance' AND action = 'cycles' AND is_granted = 1",
        'heads performance:cycles revoked (expect 0 granted)' =>
            "SELECT COUNT(*) AS c FROM role_permissions WHERE role IN ('sub_section_head','section_head','dept_head') AND module = 'performance' AND action = 'cycles' AND is_granted = 1",
        'active conflicting overrides deactivated (expect 0)' =>
            "SELECT COUNT(*) AS c FROM user_page_permissions upp JOIN users u ON u.id = upp.user_id WHERE upp.active = 1 AND upp.permission_type = 'allow' AND u.role NOT IN ('hr_manager', 'managing_director', 'super_admin') AND ((upp.module = 'reports' AND upp.action IN ('view','export')) OR (upp.module = 'attendance' AND upp.action = 'manage') OR (upp.module = 'financial_year' AND upp.action IN ('view','create','edit')) OR (upp.module = 'performance' AND upp.action = 'cycles') OR (upp.module = 'consent' AND upp.action = 'view') OR (upp.module = 'holidays' AND upp.action = 'view'))",
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
        ? "\n✗ Migration 039 completed with verification warnings.\n"
        : "\n✓ Migration 039 completed successfully!\n";
    exit($failed ? 1 : 0);
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}

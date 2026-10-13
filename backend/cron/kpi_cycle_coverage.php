<?php

declare(strict_types=1);

/**
 * KPI ↔ appraisal-cycle coverage diagnostic (CLI, READ-ONLY, dry-run only).
 *
 * WHY
 *   The appraisal workflow now shows, for one (employee, cycle), only the
 *   KPIs that are either linked to a workplan activity carrying that cycle,
 *   OR marked recurrent, OR already scored on that appraisal
 *   (AppraisalWorkflowService::indicatorsForAppraisal).
 *
 *   That means an ACTIVE KPI whose linked activities all carry NO cycle
 *   (workplan_objectives.cycle_ids empty) will silently stop appearing in
 *   new appraisals unless it is recurrent. This script surfaces exactly
 *   those rows so HR can fix them (add cycles to the activities, or tick
 *   Recurrent) before they notice scores missing.
 *
 * WHAT IT REPORTS (counts + first 20 rows of each)
 *   A. Active KPIs whose linked activities have NO cycle_ids AND is_recurrent
 *      = 0 — these would disappear from new appraisals.
 *   B. Active KPIs with no assigned employees (assigned_to_employee_ids
 *      empty) — these can never appear in anyone's appraisal.
 *
 * It NEVER writes. Safe to run any time against any environment.
 *
 * Usage:
 *   php backend/cron/kpi_cycle_coverage.php
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Helpers\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$db = Database::getInstance()->getConnection();

/** Print a titled list of up to $limit rows. */
function reportRows(string $title, int $count, array $rows, int $limit = 20): void
{
    echo strtoupper($title) . ' — ' . $count . ($count === 1 ? ' row' : ' rows') . PHP_EOL;
    echo str_repeat('-', 72) . PHP_EOL;
    if ($count === 0) {
        echo '  (none)' . PHP_EOL . PHP_EOL;
        return;
    }
    $shown = 0;
    foreach ($rows as $row) {
        if ($shown++ >= $limit) {
            echo '  … ' . ($count - $limit) . ' more' . PHP_EOL;
            break;
        }
        echo '  #' . $row['id'] . '  ' . $row['name'] . PHP_EOL;
        if (!empty($row['detail'])) {
            echo '        ' . $row['detail'] . PHP_EOL;
        }
    }
    echo PHP_EOL;
}

// ---------------------------------------------------------------------------
// A. Active KPIs whose linked activities all have empty cycle_ids and which
//    are NOT recurrent -> would not appear in any new appraisal.
// ---------------------------------------------------------------------------
$sqlA = "
    SELECT pi.id, pi.name, pi.activity_ids, pi.assigned_to_employee_ids
    FROM performance_indicators pi
    WHERE pi.is_active = 1
      AND (pi.is_recurrent = 0 OR pi.is_recurrent IS NULL)
      AND NOT EXISTS (
          SELECT 1 FROM workplan_objectives wo
          WHERE wo.soft_deleted = 0
            AND wo.cycle_ids IS NOT NULL
            AND wo.cycle_ids <> ''
            AND FIND_IN_SET(wo.id, pi.activity_ids) > 0
      )
    ORDER BY pi.id
";
$resA = $db->query($sqlA);
$rowsA = [];
if ($resA) {
    while ($r = $resA->fetch_assoc()) {
        $rowsA[] = [
            'id'     => (int) $r['id'],
            'name'   => (string) $r['name'],
            'detail' => 'activities=[' . ($r['activity_ids'] ?? '') . '] none carry a cycle; not recurrent',
        ];
    }
}
reportRows(
    'A. Active KPIs with linked activities but NO cycle on any of them (not recurrent) — will not appear in new appraisals',
    count($rowsA),
    $rowsA
);

// ---------------------------------------------------------------------------
// B. Active KPIs with no assigned employees -> can never be scored.
// ---------------------------------------------------------------------------
$sqlB = "
    SELECT pi.id, pi.name, pi.activity_ids, pi.assigned_to_employee_ids
    FROM performance_indicators pi
    WHERE pi.is_active = 1
      AND (pi.assigned_to_employee_ids IS NULL OR pi.assigned_to_employee_ids = '')
    ORDER BY pi.id
";
$resB = $db->query($sqlB);
$rowsB = [];
if ($resB) {
    while ($r = $resB->fetch_assoc()) {
        $rowsB[] = [
            'id'     => (int) $r['id'],
            'name'   => (string) $r['name'],
            'detail' => 'no assigned employees; activities=[' . ($r['activity_ids'] ?? '') . ']',
        ];
    }
}
reportRows(
    'B. Active KPIs with no assigned employees — can never appear in an appraisal',
    count($rowsB),
    $rowsB
);

echo 'Diagnostic complete. No changes were made.' . PHP_EOL;

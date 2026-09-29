<?php
/**
 * backfill-leave-delegations.php — mint the duty-cover delegations that
 * already-approved leave applications should have produced.
 *
 * WHY: createFromApprovedLeave() runs inside LeaveApprovalService::approve(),
 * so it only ever fires for leaves approved AFTER the feature shipped. Every
 * leave approved beforehand (#716, #728, #736 …) has a nominated delegate but
 * no `delegations` row, which is why the Acting Authority register looks empty
 * even though cover genuinely existed.
 *
 * Safe and idempotent:
 *   - one delegation per leave application (UNIQUE leave_application_id)
 *   - honours the "a delegate can act for only one person at a time" rule, so
 *     a clash is SKIPPED and reported rather than double-booked
 *   - a window entirely in the past is still recorded (it becomes 'expired'
 *     via the lazy sweep) because the register is an audit artefact
 *
 * Usage:
 *   php tools/backfill-leave-delegations.php            # dry run (default)
 *   php tools/backfill-leave-delegations.php --apply    # write
 */
require __DIR__ . '/../backend/bootstrap.php';

$apply = in_array('--apply', $argv ?? [], true);
$db    = \App\Helpers\Database::getInstance()->getConnection();
$svc   = \App\Services\DelegationService::getInstance();

echo $apply ? "MODE: APPLY (writes rows)\n" : "MODE: DRY RUN (no writes)\n\n";

/**
 * Approved leaves that carry a delegate and have no delegation row yet, newest
 * first. Both sides must have an active user account, otherwise there is no
 * principal to attach authority to and the row would be meaningless.
 */
$candidates = $db->query("
    SELECT la.id, la.start_date, la.end_date, la.applied_by_user_id,
           applicant.id AS applicant_user_id, applicant.role AS applicant_role,
           delegate.id  AS delegate_user_id,
           CONCAT(applicant.first_name, ' ', applicant.last_name) AS applicant_name,
           CONCAT(delegate.first_name,  ' ', delegate.last_name)  AS delegate_name
    FROM leave_applications la
    JOIN employees e ON e.id = la.employee_id
    JOIN users applicant ON applicant.employee_id = e.employee_id AND applicant.is_active = 1
    JOIN employees de ON de.id = la.delegate_emp_id
    JOIN users delegate  ON delegate.employee_id = de.employee_id AND delegate.is_active = 1
    WHERE la.status = 'approved'
      AND la.delegate_emp_id IS NOT NULL
      AND applicant.id <> delegate.id
      AND NOT EXISTS (SELECT 1 FROM delegations d WHERE d.leave_application_id = la.id)
    ORDER BY la.id DESC
")->fetch_all(MYSQLI_ASSOC);

printf("Approved leaves with a delegate and no delegation row: %d\n\n", count($candidates));

if ($candidates === []) {
    echo "Nothing to backfill.\n";
    exit(0);
}

$created = 0;
$skipped = 0;

foreach ($candidates as $c) {
    $label = sprintf(
        'leave #%d  %s..%s  %s -> %s',
        $c['id'],
        $c['start_date'],
        $c['end_date'],
        $c['applicant_name'],
        $c['delegate_name']
    );

    // Dry run: report the decision without touching anything.
    if (!$apply) {
        $clash = $svc->delegateConflict(
            (int) $c['delegate_user_id'],
            (string) $c['start_date'],
            (string) $c['end_date']
        );
        if ($clash !== null) {
            printf("  SKIP  %s\n        delegate already covering %s (%s..%s)\n",
                $label, $clash['delegator_name'], $clash['start_date'], $clash['end_date']);
            $skipped++;
        } else {
            printf("  WOULD CREATE  %s\n", $label);
            $created++;
        }
        continue;
    }

    // The approver recorded on a historical row is the user who advanced it;
    // fall back to the applicant when it is unknown.
    $approverId = (int) ($c['applicant_user_id'] ?: $c['applied_by_user_id']);
    $result = $svc->createFromApprovedLeave((int) $c['id'], $approverId);

    if (!empty($result['success']) && ($result['data']['created'] ?? false)) {
        printf("  CREATED  #%d  %s\n", (int) $result['data']['id'], $label);
        $created++;
    } else {
        printf("  SKIPPED  %s\n        %s\n", $label, $result['message'] ?? 'unknown');
        $skipped++;
    }
}

printf("\n%d would be created / created, %d skipped\n", $created, $skipped);

if (!$apply && $created > 0) {
    echo "\nRe-run with --apply to write these rows.\n";
}

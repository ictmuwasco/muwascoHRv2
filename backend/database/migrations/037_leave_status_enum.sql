-- 037: Extend leave_applications.status with the statuses the Phase 5
-- code writes but the schema never allowed.
--
--   pending_hr  — fallback initial status returned by
--                 LeaveWorkflowService::determineInitialWorkflowStatus()
--                 for employees without a subsection/section/department
--                 head in their approval chain. Without it, such
--                 submissions silently stored an EMPTY status under the
--                 server's non-strict sql_mode.
--   cancelled   — written by LeaveApprovalService::cancel() (applicant
--                 cancelling a pending application). Same silent-'' risk.
--
-- The value list preserves the existing order and APPENDS the new values,
-- so the ALTER is index-preserving for all existing rows (additive only;
-- historical records untouched).

ALTER TABLE leave_applications
    MODIFY status ENUM(
        'pending',
        'pending_section_head',
        'pending_dept_head',
        'pending_managing_director',
        'pending_hr_manager',
        'approved',
        'rejected',
        'pending_bod_chair',
        'pending_subsection_head',
        'pending_manager',
        'invalidated',
        'pending_hr',
        'cancelled'
    ) NOT NULL DEFAULT 'pending';

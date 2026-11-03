-- Split the four report areas into independently configurable View and Export
-- permissions. Copy existing grants and overrides so current access is kept.

INSERT INTO role_permissions (role, module, action, is_granted)
SELECT rp.role, report_modules.module, rp.action, rp.is_granted
FROM role_permissions rp
CROSS JOIN (
    SELECT 'employee_reports' AS module
    UNION ALL SELECT 'attendance_reports'
    UNION ALL SELECT 'leave_reports'
    UNION ALL SELECT 'appraisal_reports'
) AS report_modules
WHERE rp.module = 'reports'
  AND rp.action IN ('view', 'export')
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

INSERT IGNORE INTO user_page_permissions
    (user_id, module, action, permission_type, granted_by, updated_by,
     granted_at, updated_at, active, notes)
SELECT up.user_id, report_modules.module, up.action, up.permission_type,
       up.granted_by, up.updated_by, up.granted_at, up.updated_at, up.active, up.notes
FROM user_page_permissions up
CROSS JOIN (
    SELECT 'employee_reports' AS module
    UNION ALL SELECT 'attendance_reports'
    UNION ALL SELECT 'leave_reports'
    UNION ALL SELECT 'appraisal_reports'
) AS report_modules
WHERE up.module = 'reports'
  AND up.action IN ('view', 'export');

-- Meetings Role Permissions (Phase 018)
-- =====================================
-- Seeds role_permissions rows for the `meetings` module so MeetingController's
-- requirePermission('meetings', ...) checks resolve for non-super_admin roles.

INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('super_admin', 'meetings', 'view', 1),
('super_admin', 'meetings', 'create', 1),
('super_admin', 'meetings', 'edit', 1),
('super_admin', 'meetings', 'manage', 1),
('super_admin', 'meetings', 'confirm', 1),
('super_admin', 'meetings', 'view_attendance', 1),

('hr_manager', 'meetings', 'view', 1),
('hr_manager', 'meetings', 'create', 1),
('hr_manager', 'meetings', 'edit', 1),
('hr_manager', 'meetings', 'manage', 1),
('hr_manager', 'meetings', 'confirm', 1),
('hr_manager', 'meetings', 'view_attendance', 1),

('managing_director', 'meetings', 'view', 1),
('managing_director', 'meetings', 'create', 1),
('managing_director', 'meetings', 'edit', 1),
('managing_director', 'meetings', 'manage', 1),
('managing_director', 'meetings', 'confirm', 1),
('managing_director', 'meetings', 'view_attendance', 1),

('dept_head', 'meetings', 'view', 1),
('dept_head', 'meetings', 'create', 1),
('dept_head', 'meetings', 'edit', 1),
('dept_head', 'meetings', 'manage', 1),
('dept_head', 'meetings', 'confirm', 1),
('dept_head', 'meetings', 'view_attendance', 1),

('section_head', 'meetings', 'view', 1),
('section_head', 'meetings', 'create', 1),
('section_head', 'meetings', 'confirm', 1),
('section_head', 'meetings', 'view_attendance', 1),

('sub_section_head', 'meetings', 'view', 1),
('sub_section_head', 'meetings', 'confirm', 1),

('officer', 'meetings', 'view', 1),
('officer', 'meetings', 'confirm', 1),

('employee', 'meetings', 'view', 1),
('employee', 'meetings', 'confirm', 1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

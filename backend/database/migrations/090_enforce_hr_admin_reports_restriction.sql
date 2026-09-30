-- ============================================================================
-- 090_enforce_hr_admin_reports_restriction.sql
-- Enforce strict role-permission matrix restrictions for HR Admin & Reports modules.
-- ============================================================================

-- 1. Reports: super_admin (1), hr_manager (1), managing_director (1)
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('super_admin',       'reports', 'view',   1),
('super_admin',       'reports', 'export', 1),
('hr_manager',        'reports', 'view',   1),
('hr_manager',        'reports', 'export', 1),
('managing_director', 'reports', 'view',   1),
('managing_director', 'reports', 'export', 1),
('dept_head',         'reports', 'view',   0),
('dept_head',         'reports', 'export', 0),
('section_head',      'reports', 'view',   0),
('section_head',      'reports', 'export', 0),
('sub_section_head',  'reports', 'view',   0),
('sub_section_head',  'reports', 'export', 0),
('officer',           'reports', 'view',   0),
('officer',           'reports', 'export', 0),
('employee',          'reports', 'view',   0),
('employee',          'reports', 'export', 0)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- 2. Financial Year: super_admin (1), hr_manager (1)
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('super_admin',       'financial_year', 'view',   1),
('super_admin',       'financial_year', 'create', 1),
('super_admin',       'financial_year', 'edit',   1),
('hr_manager',        'financial_year', 'view',   1),
('hr_manager',        'financial_year', 'create', 1),
('hr_manager',        'financial_year', 'edit',   1),
('admin',             'financial_year', 'view',   0),
('admin',             'financial_year', 'create', 0),
('admin',             'financial_year', 'edit',   0),
('managing_director', 'financial_year', 'view',   0),
('managing_director', 'financial_year', 'create', 0),
('managing_director', 'financial_year', 'edit',   0),
('dept_head',         'financial_year', 'view',   0),
('dept_head',         'financial_year', 'create', 0),
('dept_head',         'financial_year', 'edit',   0),
('section_head',      'financial_year', 'view',   0),
('section_head',      'financial_year', 'create', 0),
('section_head',      'financial_year', 'edit',   0),
('sub_section_head',  'financial_year', 'view',   0),
('sub_section_head',  'financial_year', 'create', 0),
('sub_section_head',  'financial_year', 'edit',   0),
('officer',           'financial_year', 'view',   0),
('officer',           'financial_year', 'create', 0),
('officer',           'financial_year', 'edit',   0),
('employee',          'financial_year', 'view',   0),
('employee',          'financial_year', 'create', 0),
('employee',          'financial_year', 'edit',   0)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- 3. Consent Management: super_admin (1), hr_manager (1)
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('super_admin',       'consent', 'view',   1),
('super_admin',       'consent', 'manage', 1),
('hr_manager',        'consent', 'view',   1),
('hr_manager',        'consent', 'manage', 1),
('managing_director', 'consent', 'view',   0),
('managing_director', 'consent', 'manage', 0),
('dept_head',         'consent', 'view',   0),
('dept_head',         'consent', 'manage', 0),
('section_head',      'consent', 'view',   0),
('section_head',      'consent', 'manage', 0),
('sub_section_head',  'consent', 'view',   0),
('sub_section_head',  'consent', 'manage', 0),
('officer',           'consent', 'view',   0),
('officer',           'consent', 'manage', 0),
('employee',          'consent', 'view',   0),
('employee',          'consent', 'manage', 0)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- 4. Holidays: super_admin (1), hr_manager (1)
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('super_admin',       'holidays', 'view',   1),
('super_admin',       'holidays', 'create', 1),
('super_admin',       'holidays', 'edit',   1),
('super_admin',       'holidays', 'delete', 1),
('hr_manager',        'holidays', 'view',   1),
('hr_manager',        'holidays', 'create', 1),
('hr_manager',        'holidays', 'edit',   1),
('hr_manager',        'holidays', 'delete', 1),
('managing_director', 'holidays', 'view',   0),
('managing_director', 'holidays', 'create', 0),
('managing_director', 'holidays', 'edit',   0),
('managing_director', 'holidays', 'delete', 0),
('dept_head',         'holidays', 'view',   0),
('dept_head',         'holidays', 'create', 0),
('dept_head',         'holidays', 'edit',   0),
('dept_head',         'holidays', 'delete', 0),
('section_head',      'holidays', 'view',   0),
('section_head',      'holidays', 'create', 0),
('section_head',      'holidays', 'edit',   0),
('section_head',      'holidays', 'delete', 0),
('sub_section_head',  'holidays', 'view',   0),
('sub_section_head',  'holidays', 'create', 0),
('sub_section_head',  'holidays', 'edit',   0),
('sub_section_head',  'holidays', 'delete', 0),
('officer',           'holidays', 'view',   0),
('officer',           'holidays', 'create', 0),
('officer',           'holidays', 'edit',   0),
('officer',           'holidays', 'delete', 0),
('employee',          'holidays', 'view',   0),
('employee',          'holidays', 'create', 0),
('employee',          'holidays', 'edit',   0),
('employee',          'holidays', 'delete', 0)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- 5. Appraisal Cycles: super_admin (1), hr_manager (1), managing_director (0)
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('super_admin',       'performance', 'cycles', 1),
('hr_manager',        'performance', 'cycles', 1),
('managing_director', 'performance', 'cycles', 0),
('dept_head',         'performance', 'cycles', 0),
('section_head',      'performance', 'cycles', 0),
('sub_section_head',  'performance', 'cycles', 0),
('officer',           'performance', 'cycles', 0),
('employee',          'performance', 'cycles', 0)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- 6. User page permission overrides reconciliation:
UPDATE user_page_permissions upp
JOIN users u ON u.id = upp.user_id
SET upp.active = 0,
    upp.notes  = CONCAT(COALESCE(upp.notes, ''), ' [deactivated by migration 090: HR Admin and Reports restriction]')
WHERE upp.active = 1
  AND upp.permission_type = 'allow'
  AND (
       (upp.module = 'reports' AND u.role NOT IN ('hr_manager', 'managing_director', 'super_admin'))
    OR (upp.module IN ('financial_year', 'consent', 'holidays') AND u.role NOT IN ('hr_manager', 'super_admin'))
    OR (upp.module = 'performance' AND upp.action = 'cycles' AND u.role NOT IN ('hr_manager', 'super_admin'))
  );

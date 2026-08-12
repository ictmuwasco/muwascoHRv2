-- Role Permissions Table
-- Stores permissions for each role (RBAC)
-- Place: backend/database/migrations/004_role_permissions.sql

CREATE TABLE IF NOT EXISTS role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role VARCHAR(50) NOT NULL COMMENT 'Role name (e.g., super_admin, hr_manager, employee)',
    module VARCHAR(50) NOT NULL COMMENT 'Module/page name (e.g., employees, attendance, leave)',
    action VARCHAR(50) NOT NULL COMMENT 'Action (e.g., view, create, edit, delete)',
    is_granted TINYINT(1) DEFAULT 1 COMMENT '1 = granted, 0 = denied',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Unique constraint: one permission per role/module/action
    UNIQUE KEY uk_role_module_action (role, module, action),
    
    -- Index for faster lookups
    INDEX idx_role (role),
    INDEX idx_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default role permissions
-- Super Admin - Full access to everything
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('super_admin', 'dashboard', 'view', 1),
('super_admin', 'employees', 'view', 1),
('super_admin', 'employees', 'create', 1),
('super_admin', 'employees', 'edit', 1),
('super_admin', 'employees', 'delete', 1),
('super_admin', 'departments', 'view', 1),
('super_admin', 'departments', 'create', 1),
('super_admin', 'departments', 'edit', 1),
('super_admin', 'departments', 'delete', 1),
('super_admin', 'attendance', 'view', 1),
('super_admin', 'attendance', 'manage', 1),
('super_admin', 'leave', 'view', 1),
('super_admin', 'leave', 'apply', 1),
('super_admin', 'leave', 'manage', 1),
('super_admin', 'reports', 'view', 1),
('super_admin', 'reports', 'export', 1),
('super_admin', 'users', 'view', 1),
('super_admin', 'users', 'create', 1),
('super_admin', 'users', 'edit', 1),
('super_admin', 'users', 'delete', 1),
('super_admin', 'admin', 'view', 1),
('super_admin', 'admin', 'manage', 1),
('super_admin', 'audit', 'view', 1),
('super_admin', 'audit', 'export', 1),
('super_admin', 'profile', 'view', 1),
('super_admin', 'profile', 'edit', 1),
('super_admin', 'performance', 'view', 1),
('super_admin', 'performance', 'manage', 1),
('super_admin', 'consent', 'view', 1),
('super_admin', 'consent', 'manage', 1),
    ('super_admin', 'permission_overrides', 'view', 1),
    ('super_admin', 'permission_overrides', 'manage', 1),
    ('super_admin', 'holidays', 'view', 1),
    ('super_admin', 'holidays', 'create', 1),
    ('super_admin', 'holidays', 'edit', 1),
    ('super_admin', 'holidays', 'delete', 1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- HR Manager - Full HR access
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('hr_manager', 'dashboard', 'view', 1),
('hr_manager', 'employees', 'view', 1),
('hr_manager', 'employees', 'create', 1),
('hr_manager', 'employees', 'edit', 1),
('hr_manager', 'employees', 'delete', 1),
('hr_manager', 'departments', 'view', 1),
('hr_manager', 'departments', 'create', 1),
('hr_manager', 'departments', 'edit', 1),
('hr_manager', 'attendance', 'view', 1),
('hr_manager', 'attendance', 'manage', 1),
('hr_manager', 'leave', 'view', 1),
('hr_manager', 'leave', 'apply', 1),
('hr_manager', 'leave', 'manage', 1),
('hr_manager', 'reports', 'view', 1),
('hr_manager', 'reports', 'export', 1),
('hr_manager', 'users', 'view', 1),
('hr_manager', 'users', 'create', 1),
('hr_manager', 'users', 'edit', 1),
('hr_manager', 'admin', 'view', 1),
('hr_manager', 'admin', 'manage', 1),
('hr_manager', 'audit', 'view', 1),
('hr_manager', 'audit', 'export', 1),
('hr_manager', 'profile', 'view', 1),
('hr_manager', 'profile', 'edit', 1),
('hr_manager', 'performance', 'view', 1),
('hr_manager', 'performance', 'manage', 1),
('hr_manager', 'consent', 'view', 1),
('hr_manager', 'consent', 'manage', 1),
    ('hr_manager', 'permission_overrides', 'view', 1),
    ('hr_manager', 'permission_overrides', 'manage', 1),
    ('hr_manager', 'holidays', 'view', 1),
    ('hr_manager', 'holidays', 'create', 1),
    ('hr_manager', 'holidays', 'edit', 1),
    ('hr_manager', 'holidays', 'delete', 1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- Department Head - Department management + team oversight
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('dept_head', 'dashboard', 'view', 1),
('dept_head', 'employees', 'view', 1),
('dept_head', 'employees', 'edit', 1),
('dept_head', 'attendance', 'view', 1),
('dept_head', 'attendance', 'manage', 1),
('dept_head', 'leave', 'view', 1),
('dept_head', 'leave', 'apply', 1),
('dept_head', 'leave', 'manage', 1),
('dept_head', 'reports', 'view', 1),
('dept_head', 'reports', 'export', 1),
('dept_head', 'profile', 'view', 1),
('dept_head', 'profile', 'edit', 1),
('dept_head', 'performance', 'view', 1),
('dept_head', 'performance', 'manage', 1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- Section Head - Section oversight
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('section_head', 'dashboard', 'view', 1),
('section_head', 'employees', 'view', 1),
('section_head', 'attendance', 'view', 1),
('section_head', 'attendance', 'manage', 1),
('section_head', 'leave', 'view', 1),
('section_head', 'leave', 'apply', 1),
('section_head', 'leave', 'manage', 1),
('section_head', 'reports', 'view', 1),
('section_head', 'profile', 'view', 1),
('section_head', 'profile', 'edit', 1),
('section_head', 'performance', 'view', 1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- Sub Section Head
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('sub_section_head', 'dashboard', 'view', 1),
('sub_section_head', 'employees', 'view', 1),
('sub_section_head', 'attendance', 'view', 1),
('sub_section_head', 'attendance', 'manage', 1),
('sub_section_head', 'leave', 'view', 1),
('sub_section_head', 'leave', 'apply', 1),
('sub_section_head', 'leave', 'manage', 1),
('sub_section_head', 'reports', 'view', 1),
('sub_section_head', 'profile', 'view', 1),
('sub_section_head', 'profile', 'edit', 1),
('sub_section_head', 'performance', 'view', 1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- Officer - Basic employee access
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('officer', 'dashboard', 'view', 1),
('officer', 'employees', 'view', 1),
('officer', 'attendance', 'view', 1),
('officer', 'leave', 'view', 1),
('officer', 'leave', 'apply', 1),
('officer', 'reports', 'view', 1),
('officer', 'profile', 'view', 1),
('officer', 'profile', 'edit', 1),
('officer', 'performance', 'view', 1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- Employee - Basic access only
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('employee', 'dashboard', 'view', 1),
('employee', 'attendance', 'view', 1),
('employee', 'leave', 'view', 1),
('employee', 'leave', 'apply', 1),
('employee', 'reports', 'view', 1),
('employee', 'profile', 'view', 1),
('employee', 'profile', 'edit', 1),
('employee', 'performance', 'view', 1),
('employee', 'financial_year', 'view', 1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

-- Financial Year Module Permissions
INSERT INTO role_permissions (role, module, action, is_granted) VALUES
('super_admin', 'financial_year', 'view', 1),
('super_admin', 'financial_year', 'create', 1),
('super_admin', 'financial_year', 'edit', 1),
('hr_manager', 'financial_year', 'view', 1),
('hr_manager', 'financial_year', 'create', 1),
('hr_manager', 'financial_year', 'edit', 1),
('dept_head', 'financial_year', 'view', 1),
('dept_head', 'financial_year', 'create', 1),
('dept_head', 'financial_year', 'edit', 1),
('section_head', 'financial_year', 'view', 1),
('section_head', 'financial_year', 'create', 1),
('sub_section_head', 'financial_year', 'view', 1),
('sub_section_head', 'financial_year', 'create', 1),
('officer', 'financial_year', 'view', 1),
('officer', 'financial_year', 'create', 1),
('admin', 'financial_year', 'view', 1),
('admin', 'financial_year', 'create', 1),
('admin', 'financial_year', 'edit', 1)
ON DUPLICATE KEY UPDATE is_granted = VALUES(is_granted);

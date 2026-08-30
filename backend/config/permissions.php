<?php

declare(strict_types=1);

/**
 * Centralized Permission Catalog
 *
 * This file is the single source of truth for all modules and actions
 * available in the HR system. It powers both the User Permission Management UI
 * and serves as the reference for the authorization system.
 *
 * Each module contains:
 *   'key'      - The module identifier used in role_permissions and user_page_permissions tables
 *   'label'    - Human-readable display name
 *   'icon'     - (Optional) icon key for frontend rendering
 *   'actions'  - Array of actions with:
 *       'key'   - Action identifier
 *       'label' - Human-readable label
 *       'type'  - 'page' (controls sidebar visibility), 'action' (button/feature level)
 *       'default_roles' - Optional array of roles that have this by default (informational)
 *
 * The 'view' action on each module is what controls sidebar/page visibility.
 *
 * Generated from the existing role_permissions table and application routes.
 * Do NOT add modules or actions that don't exist in the application.
 */
return [
    'modules' => [
        'dashboard' => [
            'key'     => 'dashboard',
            'label'   => 'Dashboard',
            'actions' => [
                ['key' => 'view', 'label' => 'View', 'type' => 'page'],
            ],
        ],

        'employees' => [
            'key'     => 'employees',
            'label'   => 'Employees',
            'actions' => [
                ['key' => 'view',   'label' => 'View',        'type' => 'page'],
                ['key' => 'create', 'label' => 'Create',      'type' => 'action'],
                ['key' => 'edit',   'label' => 'Edit',        'type' => 'action'],
                ['key' => 'delete', 'label' => 'Delete',      'type' => 'action'],
            ],
        ],

        'departments' => [
            'key'     => 'departments',
            'label'   => 'Departments',
            'actions' => [
                ['key' => 'view',   'label' => 'View',   'type' => 'page'],
                ['key' => 'create', 'label' => 'Create', 'type' => 'action'],
                ['key' => 'edit',   'label' => 'Edit',   'type' => 'action'],
                ['key' => 'delete', 'label' => 'Delete', 'type' => 'action'],
            ],
        ],

        'attendance' => [
            'key'     => 'attendance',
            'label'   => 'Attendance',
            'actions' => [
                ['key' => 'view',   'label' => 'View',   'type' => 'page'],
                ['key' => 'manage', 'label' => 'Manage', 'type' => 'action'],
            ],
        ],

        'leave' => [
            'key'     => 'leave',
            'label'   => 'Leave Management',
            'actions' => [
                ['key' => 'view',       'label' => 'View',        'type' => 'page'],
                ['key' => 'apply',      'label' => 'Apply',       'type' => 'action'],
                ['key' => 'approve',    'label' => 'Approve',     'type' => 'action'],
                ['key' => 'reject',     'label' => 'Reject',      'type' => 'action'],
                ['key' => 'invalidate', 'label' => 'Invalidate',  'type' => 'action'],
                ['key' => 'manage',     'label' => 'Manage',      'type' => 'action'],
            ],
        ],

        'reports' => [
            'key'     => 'reports',
            'label'   => 'Reports',
            'actions' => [
                ['key' => 'view',   'label' => 'View',   'type' => 'page'],
                ['key' => 'export', 'label' => 'Export', 'type' => 'action'],
                ['key' => 'create', 'label' => 'Create', 'type' => 'action'],
                ['key' => 'edit',   'label' => 'Edit',   'type' => 'action'],
                ['key' => 'delete', 'label' => 'Delete', 'type' => 'action'],
            ],
        ],

        'users' => [
            'key'     => 'users',
            'label'   => 'User Management',
            'actions' => [
                ['key' => 'view',   'label' => 'View',   'type' => 'page'],
                ['key' => 'create', 'label' => 'Create', 'type' => 'action'],
                ['key' => 'edit',   'label' => 'Edit',   'type' => 'action'],
                ['key' => 'delete', 'label' => 'Delete', 'type' => 'action'],
            ],
        ],

        'admin' => [
            'key'     => 'admin',
            'label'   => 'Admin',
            'actions' => [
                ['key' => 'view',   'label' => 'View',   'type' => 'page'],
                ['key' => 'manage', 'label' => 'Manage', 'type' => 'action'],
            ],
        ],

        'audit' => [
            'key'     => 'audit',
            'label'   => 'Audit Trail',
            'actions' => [
                ['key' => 'view',   'label' => 'View',   'type' => 'page'],
                ['key' => 'export', 'label' => 'Export', 'type' => 'action'],
            ],
        ],

        'profile' => [
            'key'     => 'profile',
            'label'   => 'Profile',
            'actions' => [
                ['key' => 'view', 'label' => 'View', 'type' => 'page'],
                ['key' => 'edit', 'label' => 'Edit', 'type' => 'action'],
            ],
        ],

        'performance' => [
            'key'     => 'performance',
            'label'   => 'Performance Appraisal',
            'actions' => [
                ['key' => 'view',   'label' => 'View',   'type' => 'page'],
                ['key' => 'manage', 'label' => 'Manage', 'type' => 'action'],
            ],
        ],

        'consent' => [
            'key'     => 'consent',
            'label'   => 'Consent',
            'actions' => [
                ['key' => 'view',   'label' => 'View',   'type' => 'page'],
                ['key' => 'manage', 'label' => 'Manage', 'type' => 'action'],
            ],
        ],

        'permission_overrides' => [
            'key'     => 'permission_overrides',
            'label'   => 'Permission Overrides',
            'actions' => [
                ['key' => 'view',   'label' => 'View',   'type' => 'page'],
                ['key' => 'manage', 'label' => 'Manage', 'type' => 'action'],
            ],
        ],

        'financial_year' => [
            'key'     => 'financial_year',
            'label'   => 'Financial Year',
            'actions' => [
                ['key' => 'view',   'label' => 'View',   'type' => 'page'],
                ['key' => 'create', 'label' => 'Create', 'type' => 'action'],
                ['key' => 'edit',   'label' => 'Edit',   'type' => 'action'],
            ],
        ],

        'holidays' => [
            'key'     => 'holidays',
            'label'   => 'Holidays',
            'actions' => [
                ['key' => 'view',   'label' => 'View',   'type' => 'page'],
                ['key' => 'create', 'label' => 'Create', 'type' => 'action'],
                ['key' => 'edit',   'label' => 'Edit',   'type' => 'action'],
                ['key' => 'delete', 'label' => 'Delete', 'type' => 'action'],
            ],
        ],

        'meetings' => [
            'key'     => 'meetings',
            'label'   => 'Meetings',
            'actions' => [
                ['key' => 'view',            'label' => 'View',             'type' => 'page'],
                ['key' => 'create',          'label' => 'Create',           'type' => 'action'],
                ['key' => 'edit',            'label' => 'Edit',             'type' => 'action'],
                ['key' => 'delete',          'label' => 'Delete',           'type' => 'action'],
                ['key' => 'invite',          'label' => 'Invite',           'type' => 'action'],
                ['key' => 'manage',          'label' => 'Manage',           'type' => 'action'],
                ['key' => 'view_attendance', 'label' => 'View Attendance',  'type' => 'action'],
                ['key' => 'export',          'label' => 'Export',           'type' => 'action'],
                ['key' => 'confirm',         'label' => 'Confirm Attendance', 'type' => 'action'],
            ],
        ],
        'system_errors' => [
            'key'     => 'system_errors',
            'label'   => 'System Monitoring',
            'actions' => [
                ['key' => 'view',           'label' => 'View Dashboard',      'type' => 'page'],
                ['key' => 'manage',         'label' => 'Manage Errors',       'type' => 'action'],
                ['key' => 'assign',         'label' => 'Assign Errors',       'type' => 'action'],
                ['key' => 'resolve',        'label' => 'Resolve Errors',      'type' => 'action'],
                ['key' => 'view_sensitive', 'label' => 'View Technical Data', 'type' => 'action'],
            ],
        ],
    ],

    /**
     * Valid roles in the system.
     * The 'admin' role is normalized to 'super_admin' in RBAC.php.
     */
    'roles' => [
        'super_admin',
        'hr_manager',
        'dept_head',
        'section_head',
        'sub_section_head',
        'manager',
        'officer',
        'employee',
        'managing_director',
        'bod_chairman',
    ],

    /**
     * Role display labels for the UI.
     */
    'role_labels' => [
        'super_admin'       => 'Super Admin',
        'hr_manager'        => 'HR Manager',
        'dept_head'         => 'Department Head',
        'section_head'      => 'Section Head',
        'sub_section_head'  => 'Sub Section Head',
        'manager'           => 'Manager',
        'officer'           => 'Officer',
        'employee'          => 'Employee',
        'managing_director' => 'Managing Director',
        'bod_chairman'      => 'BOD Chairman',
        'admin'             => 'Admin',
    ],
];

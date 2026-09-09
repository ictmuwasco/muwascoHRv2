<?php

declare(strict_types=1);

namespace Database\Migrations;

use Database\SchemaBuilder;

/**
 * Add Performance Indexes
 * 
 * Adds indexes to improve query performance for frequently accessed columns.
 */
class AddPerformanceIndexes
{
    /**
     * Run the migration
     */
    public function up(): void
    {
        // User indexes
        SchemaBuilder::addIndex('users', 'idx_users_email', ['email'], true);
        SchemaBuilder::addIndex('users', 'idx_users_role', ['role']);
        SchemaBuilder::addIndex('users', 'idx_users_is_active', ['is_active']);
        SchemaBuilder::addIndex('users', 'idx_users_created_at', ['created_at']);

        // Employee indexes
        SchemaBuilder::addIndex('employees', 'idx_employees_email', ['email']);
        SchemaBuilder::addIndex('employees', 'idx_employees_department_id', ['department_id']);
        SchemaBuilder::addIndex('employees', 'idx_employees_section_id', ['section_id']);
        SchemaBuilder::addIndex('employees', 'idx_employees_employee_id', ['employee_id']);

        // Leave application indexes
        SchemaBuilder::addIndex('leave_applications', 'idx_leave_employee_id', ['employee_id']);
        SchemaBuilder::addIndex('leave_applications', 'idx_leave_status', ['status']);
        SchemaBuilder::addIndex('leave_applications', 'idx_leave_type_id', ['leave_type_id']);
        SchemaBuilder::addIndex('leave_applications', 'idx_leave_applied_at', ['applied_at']);

        // Attendance indexes
        SchemaBuilder::addIndex('attendance', 'idx_attendance_employee_id', ['employee_id']);
        SchemaBuilder::addIndex('attendance', 'idx_attendance_date', ['date']);
        SchemaBuilder::addIndex('attendance', 'idx_attendance_employee_date', ['employee_id', 'date']);

        // Audit log indexes
        SchemaBuilder::addIndex('audit_log', 'idx_audit_created_at', ['created_at']);
        SchemaBuilder::addIndex('audit_log', 'idx_audit_user_id', ['user_id']);
        SchemaBuilder::addIndex('audit_log', 'idx_audit_module', ['module']);
        SchemaBuilder::addIndex('audit_log', 'idx_audit_action', ['action']);

        // Department indexes
        SchemaBuilder::addIndex('departments', 'idx_departments_name', ['name']);
        SchemaBuilder::addIndex('sections', 'idx_sections_department_id', ['department_id']);
    }

    /**
     * Reverse the migration
     */
    public function down(): void
    {
        // Drop user indexes
        SchemaBuilder::dropIndex('users', 'idx_users_email');
        SchemaBuilder::dropIndex('users', 'idx_users_role');
        SchemaBuilder::dropIndex('users', 'idx_users_is_active');
        SchemaBuilder::dropIndex('users', 'idx_users_created_at');

        // Drop employee indexes
        SchemaBuilder::dropIndex('employees', 'idx_employees_email');
        SchemaBuilder::dropIndex('employees', 'idx_employees_department_id');
        SchemaBuilder::dropIndex('employees', 'idx_employees_section_id');
        SchemaBuilder::dropIndex('employees', 'idx_employees_employee_id');

        // Drop leave indexes
        SchemaBuilder::dropIndex('leave_applications', 'idx_leave_employee_id');
        SchemaBuilder::dropIndex('leave_applications', 'idx_leave_status');
        SchemaBuilder::dropIndex('leave_applications', 'idx_leave_type_id');
        SchemaBuilder::dropIndex('leave_applications', 'idx_leave_applied_at');

        // Drop attendance indexes
        SchemaBuilder::dropIndex('attendance', 'idx_attendance_employee_id');
        SchemaBuilder::dropIndex('attendance', 'idx_attendance_date');
        SchemaBuilder::dropIndex('attendance', 'idx_attendance_employee_date');

        // Drop audit indexes
        SchemaBuilder::dropIndex('audit_log', 'idx_audit_created_at');
        SchemaBuilder::dropIndex('audit_log', 'idx_audit_user_id');
        SchemaBuilder::dropIndex('audit_log', 'idx_audit_module');
        SchemaBuilder::dropIndex('audit_log', 'idx_audit_action');

        // Drop department indexes
        SchemaBuilder::dropIndex('departments', 'idx_departments_name');
        SchemaBuilder::dropIndex('sections', 'idx_sections_department_id');
    }
}

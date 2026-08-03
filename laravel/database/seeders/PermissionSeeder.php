<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['name' => 'view_dashboard', 'description' => 'View dashboard'],
            ['name' => 'manage_employees', 'description' => 'Manage employees'],
            ['name' => 'view_employees', 'description' => 'View employees'],
            ['name' => 'manage_departments', 'description' => 'Manage departments'],
            ['name' => 'view_departments', 'description' => 'View departments'],
            ['name' => 'manage_sections', 'description' => 'Manage sections'],
            ['name' => 'view_sections', 'description' => 'View sections'],
            ['name' => 'manage_leave', 'description' => 'Manage leave applications'],
            ['name' => 'view_leave', 'description' => 'View leave applications'],
            ['name' => 'approve_leave', 'description' => 'Approve leave applications'],
            ['name' => 'manage_attendance', 'description' => 'Manage attendance'],
            ['name' => 'view_attendance', 'description' => 'View attendance'],
            ['name' => 'manage_users', 'description' => 'Manage users'],
            ['name' => 'view_users', 'description' => 'View users'],
            ['name' => 'manage_roles', 'description' => 'Manage roles and permissions'],
            ['name' => 'manage_payroll', 'description' => 'Manage payroll'],
            ['name' => 'view_payroll', 'description' => 'View payroll'],
            ['name' => 'manage_complaints', 'description' => 'Manage complaints'],
            ['name' => 'view_complaints', 'description' => 'View complaints'],
            ['name' => 'manage_appraisals', 'description' => 'Manage appraisals'],
            ['name' => 'view_appraisals', 'description' => 'View appraisals'],
            ['name' => 'manage_strategic_plans', 'description' => 'Manage strategic plans'],
            ['name' => 'view_strategic_plans', 'description' => 'View strategic plans'],
            ['name' => 'manage_reports', 'description' => 'Manage reports'],
            ['name' => 'view_reports', 'description' => 'View reports'],
            ['name' => 'manage_settings', 'description' => 'Manage settings'],
            ['name' => 'view_audit_logs', 'description' => 'View audit logs'],
            ['name' => 'manage_notifications', 'description' => 'Manage notifications'],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission['name']],
                $permission
            );
        }
    }
}
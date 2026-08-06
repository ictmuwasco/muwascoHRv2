<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $superAdminRole = DB::table('roles')->where('name', 'super_admin')->value('id');
        $adminRole = DB::table('roles')->where('name', 'admin')->value('id');
        $hrManagerRole = DB::table('roles')->where('name', 'hr_manager')->value('id');
        $deptHeadRole = DB::table('roles')->where('name', 'dept_head')->value('id');
        $sectionHeadRole = DB::table('roles')->where('name', 'section_head')->value('id');
        $officerRole = DB::table('roles')->where('name', 'officer')->value('id');

        $allPermissions = DB::table('permissions')->pluck('id')->toArray();

        // Super admin gets all permissions
        foreach ($allPermissions as $permissionId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $superAdminRole, 'permission_id' => $permissionId],
                ['role_id' => $superAdminRole, 'permission_id' => $permissionId]
            );
        }

        // Admin gets most permissions
        $adminPermissions = DB::table('permissions')
            ->whereNotIn('name', ['manage_roles'])
            ->pluck('id')->toArray();
        foreach ($adminPermissions as $permissionId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $adminRole, 'permission_id' => $permissionId],
                ['role_id' => $adminRole, 'permission_id' => $permissionId]
            );
        }

        // HR Manager permissions
        $hrPermissions = DB::table('permissions')
            ->whereIn('name', [
                'view_dashboard', 'manage_employees', 'view_employees',
                'manage_departments', 'view_departments',
                'manage_sections', 'view_sections',
                'manage_leave', 'view_leave', 'approve_leave',
                'manage_attendance', 'view_attendance',
                'view_users', 'manage_payroll', 'view_payroll',
                'manage_complaints', 'view_complaints',
                'manage_appraisals', 'view_appraisals',
                'view_reports', 'manage_notifications',
            ])
            ->pluck('id')->toArray();
        foreach ($hrPermissions as $permissionId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $hrManagerRole, 'permission_id' => $permissionId],
                ['role_id' => $hrManagerRole, 'permission_id' => $permissionId]
            );
        }

        // Department Head permissions
        $deptHeadPermissions = DB::table('permissions')
            ->whereIn('name', [
                'view_dashboard', 'view_employees', 'view_departments',
                'view_sections', 'view_leave', 'approve_leave',
                'view_attendance', 'view_complaints', 'view_appraisals',
            ])
            ->pluck('id')->toArray();
        foreach ($deptHeadPermissions as $permissionId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $deptHeadRole, 'permission_id' => $permissionId],
                ['role_id' => $deptHeadRole, 'permission_id' => $permissionId]
            );
        }

        // Section Head permissions
        $sectionHeadPermissions = DB::table('permissions')
            ->whereIn('name', [
                'view_dashboard', 'view_employees', 'view_sections',
                'view_leave', 'approve_leave', 'view_attendance',
            ])
            ->pluck('id')->toArray();
        foreach ($sectionHeadPermissions as $permissionId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $sectionHeadRole, 'permission_id' => $permissionId],
                ['role_id' => $sectionHeadRole, 'permission_id' => $permissionId]
            );
        }

        // Officer permissions
        $officerPermissions = DB::table('permissions')
            ->whereIn('name', [
                'view_dashboard', 'view_leave', 'view_attendance',
            ])
            ->pluck('id')->toArray();
        foreach ($officerPermissions as $permissionId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $officerRole, 'permission_id' => $permissionId],
                ['role_id' => $officerRole, 'permission_id' => $permissionId]
            );
        }
    }
}
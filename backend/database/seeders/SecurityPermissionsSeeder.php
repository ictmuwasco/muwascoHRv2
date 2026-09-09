<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeder;

/**
 * SecurityPermissionsSeeder — seeds the security module permissions.
 *
 * Grants security:view, security:investigate, security:manage to
 * super_admin and hr_manager roles.
 */
class SecurityPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $roles = ['super_admin', 'hr_manager'];
        $actions = ['view', 'investigate', 'manage'];

        foreach ($roles as $role) {
            foreach ($actions as $action) {
                $this->seed('role_permissions', [[
                    'role' => $role,
                    'module' => 'security',
                    'action' => $action,
                    'is_granted' => 1,
                ]]);
            }
        }
    }
}

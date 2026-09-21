<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeder;

/**
 * SecurityPermissionsSeeder — seeds the security module permissions.
 * Also ensures that all permission catalog entries are granted to a non-super_admin role (hr_manager)
 * for test purposes.
 */
class SecurityPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Grant explicit security actions to super_admin and hr_manager (existing behavior)
        $roles = ['super_admin', 'hr_manager'];
        $securityActions = ['view', 'investigate', 'manage'];
        foreach ($roles as $role) {
            foreach ($securityActions as $action) {
                $this->seed('role_permissions', [[
                    'role' => $role,
                    'module' => 'security',
                    'action' => $action,
                    'is_granted' => 1,
                ]]);
            }
        }

        // Load the full permission catalog and grant each module/action to hr_manager for testing
        $catalogPath = BACKEND_PATH . '/config/permissions.php';
        if (file_exists($catalogPath)) {
            $catalog = require $catalogPath;
            $modules = $catalog['modules'] ?? [];
            foreach ($modules as $module => $data) {
                if (isset($data['actions']) && is_array($data['actions'])) {
                    foreach ($data['actions'] as $actionData) {
                        $action = $actionData['key'] ?? null;
                        if ($action === null) {
                            continue;
                        }
                        $this->seed('role_permissions', [[
                            'role' => 'hr_manager',
                            'module' => $module,
                            'action' => $action,
                            'is_granted' => 1,
                        ]]);
                    }
                }
            }
        }
    }
}

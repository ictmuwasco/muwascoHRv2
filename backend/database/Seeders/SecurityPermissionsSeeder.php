<?php
declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeder;

/**
 * SecurityPermissionsSeeder — seeds the security-module role_permissions.
 *
 * Grants super_admin and hr_manager the security-module
 * view / investigate / manage actions so the existing Security dashboard
 * tiles continue to render.
 *
 * NOTE: a prior version iterated the entire permission catalog and granted
 * EVERY module/action to hr_manager "for testing". That collapsed role
 * boundaries (hr_manager became effectively super_admin for every non-security
 * module) and is a latent privilege-escalation vector on production. It has
 * been removed. Managed (non-super) roles must never receive a blanket grant.
 */
class SecurityPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $roles         = ['super_admin', 'hr_manager'];
        $securityActions = ['view', 'investigate', 'manage'];

        foreach ($roles as $role) {
            foreach ($securityActions as $action) {
                $this->seed('role_permissions', [[
                    'role'       => $role,
                    'module'     => 'security',
                    'action'     => $action,
                    'is_granted' => 1,
                ]]);
            }
        }

        // BLANKET GRANT DISABLED (privilege-escalation guard).
        $this->grantCatalogForTesting();
    }

    /**
     * Historical no-op. Previously granted the entire permission catalog to
     * hr_manager "for testing", collapsing role boundaries. Kept as a method
     * so the removed behaviour is discoverable in grep and cannot be silently
     * reinstated inside run().
     */
    private function grantCatalogForTesting(): void
    {
        // Intentionally a no-op.
    }
}
<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use Tests\TestCase;

/**
 * Permission catalog integrity + drift detection (Phase 2, Section 7).
 *
 * The catalog (backend/config/permissions.php) is the single authoritative
 * permission definition. These tests fail when anything drifts away from it:
 * structure, the sidebar 'view' contract, grantable module derivation, and
 * (with a database) role_permissions row coverage.
 *
 * Place: backend/tests/Unit/Authorization/PermissionCatalogTest.php
 */
class PermissionCatalogTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = require BASE_PATH . '/backend/config/permissions.php';
    }

    public function testCatalogDefinesModules(): void
    {
        $this->assertArrayHasKey('modules', $this->catalog);
        $this->assertCount(25, $this->catalog['modules'], 'Catalog module count changed - review this test');
    }

    public function testEveryModuleDefinesKeyLabelAndActions(): void
    {
        foreach ($this->catalog['modules'] as $key => $module) {
            $this->assertSame($key, $module['key'] ?? null, "Module '$key' key mismatch");
            $this->assertNotEmpty($module['label'] ?? '', "Module '$key' has no label");
            $this->assertNotEmpty($module['actions'] ?? [], "Module '$key' has no actions");

            $actions = array_column($module['actions'], 'key');
            $this->assertCount(count($actions), array_unique($actions), "Module '$key' has duplicate action keys");

            foreach ($module['actions'] as $action) {
                $this->assertNotEmpty($action['label'] ?? '', "Action '{$action['key']}' of '$key' has no label");
                $this->assertContains(
                    $action['type'] ?? '',
                    ['page', 'action'],
                    "Action '{$action['key']}' of '$key' has an invalid type"
                );
            }
        }
    }

    public function testEveryModuleExposesAViewAction(): void
    {
        // The 'view' action controls sidebar/page visibility — the frontend
        // permission context depends on this contract.
        foreach ($this->catalog['modules'] as $key => $module) {
            $actions = array_column($module['actions'], 'key');
            $this->assertContains('view', $actions, "Module '$key' has no 'view' action");
        }
    }

    public function testRolesAreUniqueAndLabelled(): void
    {
        $roles = $this->catalog['roles'];
        $this->assertNotEmpty($roles);
        $this->assertCount(count($roles), array_unique($roles), 'Duplicate roles in catalog');

        foreach ($roles as $role) {
            $this->assertArrayHasKey($role, $this->catalog['role_labels'], "Role '$role' has no display label");
        }

        $this->assertContains('super_admin', $roles);
    }

    public function testGrantableModuleListIsDerivedFromTheCatalog(): void
    {
        // UserPagePermission used to duplicate the module list by hand, which
        // drifted (meetings / system_errors were missing). It must now derive
        // every grantable module from the catalog.
        $model = new \App\Models\UserPagePermission();
        $this->assertSame(
            array_keys($this->catalog['modules']),
            $model->getAllModules(),
            'Grantable modules diverge from the permission catalog'
        );
        $this->assertSame(
            array_map(fn ($m) => $m['label'], $this->catalog['modules']),
            $model->getModuleNames(),
            'Grantable module labels diverge from the permission catalog'
        );
    }

    public function testNoLegacyNonCatalogPermissionChecksRemain(): void
    {
        // MeetingMinutesService used to check 'meetings.minutes.*' actions
        // that never existed in any catalog or seed (silent drift).
        $haystack = '';
        foreach (glob(BASE_PATH . '/backend/app/{Services,Controllers,Helpers,Models,Policies,Gates}/**/*.php', GLOB_BRACE) ?: [] as $file) {
            $haystack .= (string) file_get_contents($file);
        }

        $this->assertStringNotContainsString('meetings.minutes.', $haystack, 'Non-catalog permission checks found');
        $this->assertStringNotContainsString("hasPermission('employees', 'reports')", $haystack);
        $this->assertStringNotContainsString("hasPermission('employees', 'export')", $haystack);
    }

    public function testRolePermissionRowsStayInsideTheCatalog(): void
    {
        $conn = $this->tryDatabaseConnection();
        if ($conn === null) {
            $this->markTestSkipped('Database unavailable - catalog/database drift check skipped');
        }

        $result = $conn->query('SELECT DISTINCT module, action FROM role_permissions');
        $this->assertNotFalse($result);

        $drift = [];
        while ($row = $result->fetch_assoc()) {
            $module = (string) $row['module'];
            $action = (string) $row['action'];
            if (!isset($this->catalog['modules'][$module]['actions'])) {
                $drift[] = "$module:$action (module not in catalog)";
                continue;
            }
            $actions = array_column($this->catalog['modules'][$module]['actions'], 'key');
            if (!in_array($action, $actions, true)) {
                $drift[] = "$module:$action (action not in catalog)";
            }
        }

        $this->assertSame([], $drift, 'role_permissions rows outside the catalog: ' . implode(', ', $drift));
    }

    public function testCatalogModulesUsedByControllersAreSeeded(): void
    {
        $conn = $this->tryDatabaseConnection();
        if ($conn === null) {
            $this->markTestSkipped('Database unavailable - seed coverage check skipped');
        }

        // Modules enforced by controllers must be granted to at least one
        // non-super_admin role, otherwise the feature is only reachable by
        // super admins (who bypass RBAC entirely).
        $result = $conn->query(
            "SELECT DISTINCT module FROM role_permissions WHERE role <> 'super_admin' AND is_granted = 1"
        );
        $this->assertNotFalse($result);

        $seeded = [];
        while ($row = $result->fetch_assoc()) {
            $seeded[(string) $row['module']] = true;
        }

        // Every module with a controller-level requirePermission() call.
        $enforced = [
            'dashboard', 'employees', 'departments', 'attendance', 'leave', 'reports',
            'users', 'admin', 'audit', 'performance', 'consent', 'permission_overrides',
            'financial_year', 'holidays', 'system_errors', 'complaints', 'payroll',
            'notifications', 'strategic_plan', 'performance_contract', 'workplan',
            'kpi', 'sectional_objective', 'meetings',
        ];

        $missing = array_diff($enforced, array_keys($seeded));
        $this->assertSame(
            [],
            array_values($missing),
            'Modules enforced by controllers but granted to no operational role: ' . implode(', ', $missing)
        );
    }

    /**
     * Attempt a connection through the application Database helper.
     */
    private function tryDatabaseConnection(): ?\mysqli
    {
        try {
            return \App\Helpers\Database::getInstance()->getConnection();
        } catch (\Throwable $e) {
            return null;
        }
    }
}

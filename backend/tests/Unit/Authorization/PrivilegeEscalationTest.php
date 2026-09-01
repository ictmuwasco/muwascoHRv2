<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use Tests\TestCase;

/**
 * Privilege escalation prevention (Phase 2, Sections 10 + 15).
 *
 * Locks the guards that stop a permission administrator (or any holder of
 * users:edit) from modifying authorization to their own advantage:
 *
 *   - nobody can write permission overrides for themselves;
 *   - super_admin accounts cannot receive overrides at all;
 *   - invalid modules/actions cannot be granted;
 *   - only a super_admin may assign the super_admin role;
 *   - role values outside the catalog are rejected.
 *
 * Place: backend/tests/Unit/Authorization/PrivilegeEscalationTest.php
 */
class PrivilegeEscalationTest extends TestCase
{
    private \mysqli $conn;

    /** @var int[] */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
                parent::setUp();
        $_SESSION = [];
        $_SESSION['session_valid'] = true;
    }

    protected function tearDown(): void
    {
        $this->cleanupTestUsers();
        $_SESSION = [];
        parent::tearDown();
    }

    public function testSelfOverrideGuardExists(): void
    {
        $source = file_get_contents(BASE_PATH . '/backend/app/Services/PermissionService.php');
        $this->assertNotFalse($source);
        $this->assertStringContainsString(
            'You cannot modify your own permission overrides',
            $source,
            'The self-override escalation guard must stay in place'
        );
    }

    public function testSuperAdminRoleAssignmentGuardExists(): void
    {
        $source = file_get_contents(BASE_PATH . '/backend/app/Services/UserService.php');
        $this->assertNotFalse($source);
        $this->assertStringContainsString(
            'Only a Super Administrator can assign the Super Admin role',
            $source,
            'The role-assignment escalation guard must stay in place'
        );
    }

    public function testUserIdorProtectionOnOverrideEndpointsIsRouteGated(): void
    {
        // /permissions/users/{id}/overrides (any target id) requires
        // permission_overrides:manage at the ROUTE level; the map test locks
        // the mapping, this asserts the gated registrations still exist.
        $source = file_get_contents(BASE_PATH . '/api.php');
        $this->assertNotFalse($source);
        $this->assertStringContainsString(
            "'/permissions/users/{id}/overrides', PermissionController::class, 'setOverride', 'permission_overrides:manage'",
            $source
        );
        $this->assertStringContainsString(
            "'/permissions/users/{id}/overrides', PermissionController::class, 'removeOverride', 'permission_overrides:manage'",
            $source
        );
    }

    /* ====================================================================
     * Database-dependent escalation tests (skipped without a test DB).
     * ==================================================================== */

    public function testUserCannotGrantOverridesToThemselves(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $actor = $this->createTestUser('hr_manager');
        $service = \App\Services\PermissionService::getInstance();

        $result = $service->setOverride($actor, 'reports', 'export', 'allow', $actor, 'self grant attempt');

        $this->assertFalse($result['success'], 'An actor must never target themselves');
        $this->assertStringContainsString('own permission overrides', $result['message']);
    }

    public function testSuperAdminAccountsCannotReceiveOverrides(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $superAdminId = $this->createTestUser('super_admin');
        $actorId = $this->createTestUser('hr_manager');
        $service = \App\Services\PermissionService::getInstance();

        $result = $service->setOverride($superAdminId, 'reports', 'export', 'allow', $actorId);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Super Admin permissions cannot be overridden', $result['message']);
    }

    public function testOverridesRejectModulesOutsideTheCatalog(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $targetId = $this->createTestUser('officer');
        $actorId = $this->createTestUser('hr_manager');
        $service = \App\Services\PermissionService::getInstance();

        $result = $service->setOverride($targetId, 'not_a_module', 'view', 'allow', $actorId);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found in permission catalog', $result['message']);

        $result = $service->setOverride($targetId, 'reports', 'made_up_action', 'allow', $actorId);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString("not valid for module 'reports'", $result['message']);

        $result = $service->setOverride($targetId, 'reports', 'export', 'escalate', $actorId);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('must be "allow" or "deny"', $result['message']);
    }

    public function testNonSuperAdminCannotAssignTheSuperAdminRole(): void
    {
        // Acting user is an HR manager (holds users:edit but is not super).
        $_SESSION['user_id'] = 999999;
        $_SESSION['user_role'] = 'hr_manager';

        $repo = \Mockery::mock(\App\Repositories\Contracts\UserRepositoryInterface::class);
        $repo->shouldReceive('findById')->with(123)->andReturn(['id' => 123, 'role' => 'officer']);
        $repo->shouldNotReceive('updateRole');

        $service = new \App\Services\UserService();
        $service->setUserRepository($repo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only a Super Administrator can assign the Super Admin role');

        $service->updateUserRole(123, 'super_admin');
    }

    public function testSuperAdminMayAssignOrdinaryRoles(): void
    {
        $_SESSION['user_id'] = 5;
        $_SESSION['user_role'] = 'super_admin';

        $repo = \Mockery::mock(\App\Repositories\Contracts\UserRepositoryInterface::class);
        $repo->shouldReceive('findById')->with(123)->andReturn(['id' => 123, 'role' => 'officer']);
        $repo->shouldReceive('updateRole')->once()->with(123, 'dept_head')->andReturn(true);

        $service = new \App\Services\UserService();
        $service->setUserRepository($repo);

        $this->assertTrue($service->updateUserRole(123, 'dept_head'));
    }

    public function testRolesOutsideTheCatalogAreRejected(): void
    {
        $_SESSION['user_id'] = 5;
        $_SESSION['user_role'] = 'super_admin';

        $repo = \Mockery::mock(\App\Repositories\Contracts\UserRepositoryInterface::class);
        $repo->shouldReceive('findById')->with(123)->andReturn(['id' => 123, 'role' => 'officer']);
        $repo->shouldNotReceive('updateRole');

        $service = new \App\Services\UserService();
        $service->setUserRepository($repo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid user role');

        // 'hr' was accepted by a stale, hand-maintained list - it never
        // existed as a role and must not be assignable.
        $service->updateUserRole(123, 'hr');
    }

    /* ====================================================================
     * Helpers
     * ==================================================================== */

        private function databaseAvailable(): bool
    {
        try {
            $this->conn = \App\Helpers\Database::getInstance()->getConnection();

            // In CI MySQL is reachable but the schema may not be migrated.
            // Verify the expected table exists so DB-dependent assertions are
            // skipped rather than crashing with a "table doesn't exist" error.
            $check = $this->conn->query(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users' LIMIT 1"
            );
            if ($check && $check->fetch_assoc()) {
                $check->close();
                return true;
            }
            $this->conn = null;
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function createTestUser(string $role): int
    {
        $email = 'phase2-esc-' . bin2hex(random_bytes(6)) . '@example.test';
        $password = password_hash('phase2-test-password', PASSWORD_DEFAULT);
        $firstName = 'Phase2';
        $lastName = 'Escalation';
        $surname = 'Tester';
        $gender = 'male';

        $stmt = $this->conn->prepare(
            'INSERT INTO users (email, first_name, last_name, surname, gender, password, role, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->bind_param('sssssss', $email, $firstName, $lastName, $surname, $gender, $password, $role);
        $stmt->execute();
        $userId = (int) $this->conn->insert_id;
        $stmt->close();

        $this->createdUserIds[] = $userId;

        return $userId;
    }

    private function cleanupTestUsers(): void
    {
        if (empty($this->createdUserIds)) {
            return;
        }

        try {
            $conn = \App\Helpers\Database::getInstance()->getConnection();
            foreach ($this->createdUserIds as $userId) {
                $stmt = $conn->prepare('DELETE FROM user_page_permissions WHERE user_id = ?');
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $stmt->close();
            }
        } catch (\Throwable $e) {
            // Database unavailable - nothing to clean up.
        }

        $this->createdUserIds = [];
    }
}
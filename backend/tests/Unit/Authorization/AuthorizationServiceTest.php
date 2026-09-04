<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use Tests\TestCase;

/**
 * Authorization engine hierarchy tests (Phase 2, Sections 1/2/9/15).
 *
 * Documents and locks the resolution order of the single authorization
 * engine (App\Helpers\AuthorizationService):
 *
 *   1. Unauthenticated / unknown user            → DENY
 *   2. SUPER ADMIN (role from trusted context)   → ALLOW (never overridden)
 *   3. Explicit user override (allow/deny)       → ALLOW / DENY
 *   4. Self-service own-profile exception        → ALLOW
 *   5. Role permission                           → ALLOW / DENY
 *   6. No rule matched                           → DEFAULT DENY
 *
 * Regression coverage for the Phase-2 findings:
 *   - roles are resolved from trusted context, never passed as user ids;
 *   - other-user checks resolve the TARGET user's role from the database;
 *   - overrides take immediate effect (cache invalidation);
 *   - super_admin cannot be restricted by a stray override row.
 *
 * DB-dependent assertions are skipped when the configured test database is
 * unreachable, so the suite runs everywhere.
 *
 * Place: backend/tests/Unit/Authorization/AuthorizationServiceTest.php
 */
class AuthorizationServiceTest extends TestCase
{
    private \mysqli $conn;

    /** @var int[] user ids created by these tests */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

                $_SESSION = [];
        $_SESSION['session_valid'] = true;
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        $_COOKIE = [];

        // Fresh engine instance per test (private singleton cache reset).
        $this->resetEngine();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestUsers();
        $_SESSION = [];
        $this->resetEngine();

        parent::tearDown();
    }

    public function testUnauthenticatedUserIsDenied(): void
    {
        $service = \App\Helpers\AuthorizationService::getInstance();

        $this->assertFalse($service->hasPermission(0, 'employees', 'view'));
        $this->assertFalse($service->hasPermission(-1, 'employees', 'view'));
    }

    public function testSuperAdminIsAlwaysAllowed(): void
    {
        $_SESSION['user_id'] = 5;
        $_SESSION['user_role'] = 'super_admin';
        $service = \App\Helpers\AuthorizationService::getInstance();

        $this->assertTrue($service->hasPermission(5, 'users', 'delete'));
        $this->assertTrue($service->hasPermission(5, 'permission_overrides', 'manage'));
        $this->assertTrue($service->hasPermission(5, 'audit', 'export'));
    }

    public function testLegacyAdminRoleAliasIsStillSuperAdmin(): void
    {
        // RBAC normalizes 'admin' to super_admin; the engine must not regress.
        $_SESSION['user_id'] = 5;
        $_SESSION['user_role'] = 'admin';
        $service = \App\Helpers\AuthorizationService::getInstance();

        $this->assertTrue($service->hasPermission(5, 'users', 'delete'));
    }

    public function testSelfServiceOwnProfileException(): void
    {
        $_SESSION['user_id'] = 42;
        $_SESSION['user_role'] = 'officer';
        $service = \App\Helpers\AuthorizationService::getInstance();

        $this->assertTrue($service->hasPermission(42, 'profile', 'view'));
        $this->assertTrue($service->hasPermission(42, 'profile', 'edit'));
    }

    public function testDefaultDenyForUngrantedPermission(): void
    {
        $_SESSION['user_id'] = 42;
        $_SESSION['user_role'] = 'officer';
        $service = \App\Helpers\AuthorizationService::getInstance();

        $this->assertFalse($service->hasPermission(42, 'users', 'create'));
        $this->assertFalse($service->hasPermission(42, 'made_up_module', 'view'));
    }

    public function testPageAccessIsViewActionOfTheModule(): void
    {
        $_SESSION['user_id'] = 5;
        $_SESSION['user_role'] = 'super_admin';
        $service = \App\Helpers\AuthorizationService::getInstance();

        $this->assertTrue($service->hasPageAccess(5, 'employees'));
        $this->assertFalse($service->hasPageAccess(0, 'employees'));
    }

    public function testNullUserIdFallsBackToSessionIdentity(): void
    {
        $_SESSION['user_id'] = 5;
        $_SESSION['user_role'] = 'super_admin';
        $service = \App\Helpers\AuthorizationService::getInstance();

        $this->assertTrue($service->hasPermission(null, 'users', 'delete'));
    }

    /* ====================================================================
     * Database-dependent hierarchy tests (skipped without a test database).
     * ==================================================================== */

    public function testExplicitAllowOverrideGrantsPermissionBeyondRole(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $userId = $this->createTestUser('officer');
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_role'] = 'officer';
        $service = \App\Helpers\AuthorizationService::getInstance();

        // Officers do not hold meetings:invite by role.
        $this->assertFalse($service->hasPermission($userId, 'meetings', 'invite'));

        $this->assertTrue(
            $service->setPermissionOverride($userId, 'meetings', 'invite', 'allow', $userId, $userId, 'phase2 test'),
            'Override write failed'
        );

        $this->assertTrue($service->hasPermission($userId, 'meetings', 'invite'));
    }

    public function testExplicitDenyOverrideBeatsRolePermission(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $userId = $this->createTestUser('hr_manager');
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_role'] = 'hr_manager';
        $service = \App\Helpers\AuthorizationService::getInstance();

        // HR managers hold leave:manage by role.
        $this->assertTrue($service->hasPermission($userId, 'leave', 'manage'));

        $this->assertTrue($service->setPermissionOverride($userId, 'leave', 'manage', 'deny', $userId, $userId, 'phase2 test'));

        // User-specific DENY beats the role grant.
        $this->assertFalse($service->hasPermission($userId, 'leave', 'manage'));
    }

    public function testOverrideRemovalRestoresRolePermission(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $userId = $this->createTestUser('hr_manager');
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_role'] = 'hr_manager';
        $service = \App\Helpers\AuthorizationService::getInstance();

        $service->setPermissionOverride($userId, 'leave', 'manage', 'deny', $userId, $userId, 'phase2 test');
        $this->assertFalse($service->hasPermission($userId, 'leave', 'manage'));

        $this->assertTrue($service->removePermissionOverride($userId, 'leave', 'manage'));
        $this->assertTrue(
            $service->hasPermission($userId, 'leave', 'manage'),
            'Removing the override must restore the role permission (inherit)'
        );
    }

    public function testCacheInvalidationMakesOverrideChangesImmediate(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $userId = $this->createTestUser('officer');
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_role'] = 'officer';
        $service = \App\Helpers\AuthorizationService::getInstance();

        $service->hasPermission($userId, 'meetings', 'invite'); // prime the per-user cache

        $service->setPermissionOverride($userId, 'meetings', 'invite', 'allow', $userId, $userId, 'phase2 test');
        $this->assertTrue(
            $service->hasPermission($userId, 'meetings', 'invite'),
            'Override must take effect immediately (cache invalidated on write)'
        );

        $service->setPermissionOverride($userId, 'meetings', 'invite', 'deny', $userId, $userId, 'phase2 test');
        $this->assertFalse($service->hasPermission($userId, 'meetings', 'invite'));
    }

    public function testOtherUserCheckResolvesTargetRoleFromDatabase(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        // Regression: the acting user's session role must never authorize a
        // DIFFERENT user id. An hr_manager actor inspects an officer target:
        // hr managers hold employees:view, officers do not (migration 038).
        $actorId = $this->createTestUser('hr_manager');
        $targetId = $this->createTestUser('officer');

        $_SESSION['user_id'] = $actorId;
        $_SESSION['user_role'] = 'hr_manager';
        $service = \App\Helpers\AuthorizationService::getInstance();

        $this->assertTrue($service->hasPermission($actorId, 'employees', 'view'), 'Actor (hr_manager) holds employees:view');
        $this->assertFalse(
            $service->hasPermission($targetId, 'employees', 'view'),
            'Target (officer) must be evaluated with the TARGET role from the database'
        );
    }

    public function testSuperAdminCannotBeRestrictedByAStrayOverrideRow(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $userId = $this->createTestUser('super_admin');
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_role'] = 'super_admin';
        $service = \App\Helpers\AuthorizationService::getInstance();

        // Simulate a stray/manual deny row (the admin UI refuses to create
        // these) — the engine must still honour the super-admin policy.
        $stmt = $this->conn()->prepare(
            "INSERT INTO user_page_permissions (user_id, module, action, permission_type, granted_by, updated_by, active)
             VALUES (?, 'leave', 'manage', 'deny', ?, ?, 1)"
        );
        $stmt->bind_param('iii', $userId, $userId, $userId);
        $stmt->execute();
        $stmt->close();

        $this->assertTrue(
            $service->hasPermission($userId, 'leave', 'manage'),
            'Super admin overrides must never restrict super admins (documented policy)'
        );
    }

    public function testIsPermissionManagerFollowsThePermissionNotAHardcodedRole(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $officerId = $this->createTestUser('officer');
        $_SESSION['user_id'] = $officerId;
        $_SESSION['user_role'] = 'officer';
        $service = \App\Helpers\AuthorizationService::getInstance();
        $this->assertFalse($service->isPermissionManager(), 'Officers must not manage permission overrides');

        // Phase (role/page/permission restriction): permission administration
        // is super_admin-only by default — migration 038 revoked the hr_manager
        // seeds. An explicit user grant is still honoured (single engine).
        $hrId = $this->createTestUser('hr_manager');
        $_SESSION['user_id'] = $hrId;
        $_SESSION['user_role'] = 'hr_manager';
        $this->resetEngine();
        $service = \App\Helpers\AuthorizationService::getInstance();
        $this->assertFalse(
            $service->isPermissionManager(),
            'permission_overrides:manage must NOT be a hr_manager default (migration 038)'
        );

        // An explicit user grant is honoured as well (single engine).
        $officerId2 = $this->createTestUser('officer');
        $service->setPermissionOverride($officerId2, 'permission_overrides', 'manage', 'allow', $hrId, $hrId, 'phase2 test');
        $_SESSION['user_id'] = $officerId2;
        $_SESSION['user_role'] = 'officer';
        $this->resetEngine();
        $service = \App\Helpers\AuthorizationService::getInstance();
        $this->assertTrue($service->isPermissionManager(), 'Explicit override must grant permission-manager capability');
    }

    public function testEffectivePermissionStringsForSuperAdminCoverTheCatalog(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $userId = $this->createTestUser('super_admin');
        $service = \App\Helpers\AuthorizationService::getInstance();

        $permissions = $service->getEffectivePermissionStrings($userId);
        $catalog = require BASE_PATH . '/backend/config/permissions.php';

        $expected = [];
        foreach ($catalog['modules'] as $module) {
            foreach ($module['actions'] as $action) {
                $expected[] = $module['key'] . ':' . $action['key'];
            }
        }

        $this->assertSame(
            $expected,
            $permissions,
            'Super admin must resolve to the full catalog (documented policy)'
        );
    }

    public function testEffectivePermissionStringsHonourOverridesAndDenyByDefault(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $userId = $this->createTestUser('officer');
        $service = \App\Helpers\AuthorizationService::getInstance();

        $permissions = $service->getEffectivePermissionStrings($userId);

        $this->assertNotContains('users:create', $permissions, 'Ungranted permissions must not leak');
        $this->assertContains('profile:view', $permissions, 'Self-service profile exception');

        $service->setPermissionOverride($userId, 'meetings', 'invite', 'allow', $userId, $userId, 'phase2 test');
        $permissions = $service->getEffectivePermissionStrings($userId);
        $this->assertContains('meetings:invite', $permissions, 'Explicit allow must appear in the effective set');
    }

    /* ====================================================================
     * Helpers
     * ==================================================================== */

    private function databaseAvailable(): bool
    {
        return $this->conn() !== null;
    }

        private function conn(): ?\mysqli
    {
        if (isset($this->conn) && $this->conn instanceof \mysqli) {
            return $this->conn;
        }

        try {
            $this->conn = \App\Helpers\Database::getInstance()->getConnection();

            // In CI MySQL is reachable but the schema is not migrated. Verify
            // the expected table exists so DB-dependent assertions are skipped
            // rather than crashing with a "table doesn't exist" error.
            $check = $this->conn->query(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users' LIMIT 1"
            );
            if ($check && $check->fetch_assoc()) {
                return $this->conn;
            }
            $check?->close();
            $this->conn = null;
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Create a disposable user for authorization tests.
     */
    private function createTestUser(string $role): int
    {
        $conn = $this->conn();
        if ($conn === null) {
            $this->markTestSkipped('Database unavailable');
        }

        $email = 'phase2-test-' . bin2hex(random_bytes(6)) . '@example.test';
        $password = password_hash('phase2-test-password', PASSWORD_DEFAULT);
        $firstName = 'Phase2';
        $lastName = 'AuthTest';
        $surname = 'Tester';
        $gender = 'male';

        $stmt = $conn->prepare(
            'INSERT INTO users (email, first_name, last_name, surname, gender, password, role, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->bind_param('sssssss', $email, $firstName, $lastName, $surname, $gender, $password, $role);
        $stmt->execute();
        $userId = (int) $conn->insert_id;
        $stmt->close();

        $this->createdUserIds[] = $userId;

        return $userId;
    }

    /**
     * Reset the engine singleton (private static cache) between tests.
     */
    private function resetEngine(): void
    {
        try {
            $ref = new \ReflectionProperty(\App\Helpers\AuthorizationService::class, 'instance');
        } catch (\ReflectionException $e) {
            return;
        }
        $ref->setAccessible(true);
        $ref->setValue(null, null);
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

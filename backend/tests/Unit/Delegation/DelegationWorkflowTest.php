<?php

declare(strict_types=1);

namespace Tests\Unit\Delegation;

use Tests\TestCase;

/**
 * Temporary Delegation / Acting Authority — integration tests.
 *
 * Covers the delegation spec's required scenarios at the two enforcement
 * layers:
 *
 *   1. EFFECTIVE PERMISSION RESOLUTION (AuthorizationService Priority 6):
 *      an active delegation grants ONLY its snapshotted permissions; expired /
 *      cancelled / pending delegations grant NOTHING; an explicit user 'deny'
 *      override still wins; non-delegatable modules can never be granted.
 *      (Scenarios 1, 2, 3, 4, 6, 8-partial)
 *
 *   2. LEAVE APPROVAL INTEGRATION (DelegationService::canActAsLeaveApprover):
 *      the delegate may decide ONLY at stages whose required role matches the
 *      delegated role, ONLY within the delegated scope, and NEVER their own
 *      application. (Scenarios 5, 7-authorization, self-approval guard)
 *
 * DB-dependent assertions are skipped when the configured database is
 * unreachable or the delegations table is not migrated.
 *
 * Place: backend/tests/Unit/Delegation/DelegationWorkflowTest.php
 */
class DelegationWorkflowTest extends TestCase
{
    /** @var int[] user ids created by these tests */
    private array $createdUserIds = [];

    /** @var int[] delegation ids created by these tests */
    private array $createdDelegationIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
        $_SESSION['session_valid'] = true;
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        $_COOKIE = [];

        $this->resetEngine();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        $_SESSION = [];
        $this->resetEngine();

        parent::tearDown();
    }

    /* ====================================================================
     * 1. Effective permission resolution (AuthorizationService Priority 6)
     * ==================================================================== */

    public function testActiveDelegationGrantsSnapshottedPermissions(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $delegateId = $this->createTestUser('officer');
        $this->insertDelegation($delegateId, 'section_head', 'section', 1, 'approved', ['leave:manage', 'leave:approve']);

        $service = \App\Helpers\AuthorizationService::getInstance();

        // Officers do NOT hold leave:manage by role — only via delegation.
        $this->assertTrue($service->hasPermission($delegateId, 'leave', 'manage'));
        $this->assertTrue($service->hasPermission($delegateId, 'leave', 'approve'));
    }

    public function testDelegationDoesNotGrantUnsnapshottedPermissions(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $delegateId = $this->createTestUser('officer');
        $this->insertDelegation($delegateId, 'section_head', 'section', 1, 'approved', ['leave:manage']);

        $service = \App\Helpers\AuthorizationService::getInstance();

        // Not in the snapshot → still default-deny (officer has no grants here).
        $this->assertFalse($service->hasPermission($delegateId, 'workplan', 'manage'));
        $this->assertFalse($service->hasPermission($delegateId, 'employees', 'view'));
    }

    public function testDelegationCanNeverGrantNonDelegatableModules(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $delegateId = $this->createTestUser('officer');
        // Simulate a hand-edited row attempting to delegate Settings — the
        // resolution-time blacklist (defence in depth) must refuse it.
        $this->insertDelegation($delegateId, 'super_admin', 'organization', 0, 'approved', ['settings:users', 'permission_overrides:manage']);

        $service = \App\Helpers\AuthorizationService::getInstance();

        $this->assertFalse($service->hasPermission($delegateId, 'settings', 'users'));
        $this->assertFalse($service->hasPermission($delegateId, 'permission_overrides', 'manage'));
    }

    public function testExpiredDelegationGrantsNothing(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $delegateId = $this->createTestUser('officer');
        $this->insertDelegation(
            $delegateId,
            'section_head',
            'section',
            1,
            'approved',
            ['leave:manage'],
            '-10 days',
            '-3 days'
        );

        $service = \App\Helpers\AuthorizationService::getInstance();

        $this->assertFalse($service->hasPermission($delegateId, 'leave', 'manage'));
    }

    public function testPendingDelegationGrantsNothing(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $delegateId = $this->createTestUser('officer');
        $this->insertDelegation($delegateId, 'section_head', 'section', 1, 'pending', ['leave:manage']);

        $service = \App\Helpers\AuthorizationService::getInstance();

        $this->assertFalse($service->hasPermission($delegateId, 'leave', 'manage'));
    }

    public function testCancelledDelegationGrantsNothing(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $delegateId = $this->createTestUser('officer');
        $this->insertDelegation($delegateId, 'section_head', 'section', 1, 'cancelled', ['leave:manage']);

        $service = \App\Helpers\AuthorizationService::getInstance();

        $this->assertFalse($service->hasPermission($delegateId, 'leave', 'manage'));
    }

    public function testExplicitDenyOverrideBeatsActiveDelegation(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $delegateId = $this->createTestUser('officer');
        $this->insertDelegation($delegateId, 'section_head', 'section', 1, 'approved', ['leave:manage']);

        // Explicit user DENY outranks the delegation (Priority 3 > Priority 6).
        $stmt = $this->conn()->prepare(
            "INSERT INTO user_page_permissions (user_id, module, action, permission_type, granted_by, updated_by, active)
             VALUES (?, 'leave', 'manage', 'deny', ?, ?, 1)"
        );
        $stmt->bind_param('iii', $delegateId, $delegateId, $delegateId);
        $stmt->execute();
        $stmt->close();

        $this->resetEngine(); // fresh override cache

        $service = \App\Helpers\AuthorizationService::getInstance();
        $this->assertFalse($service->hasPermission($delegateId, 'leave', 'manage'));
    }

    public function testEffectivePermissionStringsIncludeDelegatedAuthority(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $delegateId = $this->createTestUser('officer');
        $this->insertDelegation($delegateId, 'section_head', 'section', 1, 'approved', ['leave:manage', 'leave:approve']);

        $strings = \App\Helpers\AuthorizationService::getInstance()->getEffectivePermissionStrings($delegateId);

        $this->assertContains('leave:manage', $strings);
        $this->assertContains('leave:approve', $strings);
        $this->assertNotContains('settings:users', $strings);
    }

    /* ====================================================================
     * 2. Leave approval integration (scope / stage / self-approval guards)
     * ==================================================================== */

    public function testDelegateCanApproveInScopeApplication(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $employee = $this->firstActiveEmployeeInSection();
        if ($employee === null) {
            $this->markTestSkipped('No active employee with a section available');
        }

        $delegateId = $this->createTestUser('officer', $employee['employee_id']);
        $this->insertDelegation($delegateId, 'section_head', 'section', (int) $employee['section_id'], 'approved', ['leave:approve']);

        $service = \App\Services\DelegationService::getInstance();

        $delegation = $service->canActAsLeaveApprover(
            $delegateId,
            'pending_section_head',
            ['employee_id' => (int) $employee['id'], 'status' => 'pending_section_head'],
            ['subsection_id' => $employee['subsection_id'], 'section_id' => $employee['section_id'], 'department_id' => $employee['department_id']]
        );

        $this->assertNotNull($delegation, 'An in-scope, active, role-matching delegation must authorize the stage');
    }

    public function testDelegateCannotApproveOutOfScopeApplication(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $employee = $this->firstActiveEmployeeInSection();
        if ($employee === null) {
            $this->markTestSkipped('No active employee with a section available');
        }

        $delegateId = $this->createTestUser('officer', $employee['employee_id']);
        $this->insertDelegation($delegateId, 'section_head', 'section', (int) $employee['section_id'], 'approved', ['leave:approve']);

        $service = \App\Services\DelegationService::getInstance();

        // Scenario 5 (wrong scope): application from ANOTHER section.
        $delegation = $service->canActAsLeaveApprover(
            $delegateId,
            'pending_section_head',
            ['employee_id' => (int) $employee['id'], 'status' => 'pending_section_head'],
            ['subsection_id' => null, 'section_id' => ((int) $employee['section_id']) + 999999, 'department_id' => null]
        );

        $this->assertNull($delegation, 'The delegate must NOT decide applications outside the delegated scope');
    }

    public function testDelegateCannotDecideStageOutsideDelegatedRole(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $employee = $this->firstActiveEmployeeInSection();
        if ($employee === null) {
            $this->markTestSkipped('No active employee with a section available');
        }

        $delegateId = $this->createTestUser('officer', $employee['employee_id']);
        $this->insertDelegation($delegateId, 'section_head', 'section', (int) $employee['section_id'], 'approved', ['leave:approve']);

        $service = \App\Services\DelegationService::getInstance();

        // Scenario 5 (wrong role): the pending stage needs dept_head authority.
        $delegation = $service->canActAsLeaveApprover(
            $delegateId,
            'pending_dept_head',
            ['employee_id' => (int) $employee['id'], 'status' => 'pending_dept_head'],
            ['subsection_id' => null, 'section_id' => $employee['section_id'], 'department_id' => $employee['department_id']]
        );

        $this->assertNull($delegation, 'A section_head delegation must NOT authorize a dept_head stage');
    }

    public function testDelegateCannotDecideOwnApplication(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $employee = $this->firstActiveEmployeeInSection();
        if ($employee === null) {
            $this->markTestSkipped('No active employee with a section available');
        }

        $delegateId = $this->createTestUser('officer', $employee['employee_id']);
        $this->insertDelegation($delegateId, 'section_head', 'section', (int) $employee['section_id'], 'approved', ['leave:approve']);

        $service = \App\Services\DelegationService::getInstance();

        // Self-approval guard (§32): the delegate IS the applicant.
        $delegation = $service->canActAsLeaveApprover(
            $delegateId,
            'pending_section_head',
            ['employee_id' => (int) $employee['id'], 'status' => 'pending_section_head'],
            ['subsection_id' => null, 'section_id' => $employee['section_id'], 'department_id' => $employee['department_id']]
        );

        $this->assertNull($delegation, 'The delegate must never decide their own application via delegation');
    }

    public function testDelegatedVisibilityFragmentsMatchDelegatedScope(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $delegateId = $this->createTestUser('officer');
        $this->insertDelegation($delegateId, 'section_head', 'section', 42, 'approved', ['leave:manage']);

        $fragments = \App\Services\DelegationService::getInstance()->delegatedVisibilityFragments($delegateId);

        $this->assertContains('(e.section_id = 42)', $fragments);
    }

    public function testOfficerCannotCreateDelegation(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }

        $officerId = $this->createTestUser('officer');
        $delegateId = $this->createTestUser('employee');

        $service = \App\Services\DelegationService::getInstance();

        // Scenario 6 (wrong role): an officer holds no supervisory authority
        // they could transfer — creation must be denied.
        $result = $service->create($officerId, [
            'delegate_user_id' => $delegateId,
            'start_date' => date('Y-m-d'),
            'end_date' => date('Y-m-d', strtotime('+7 days')),
            'permissions' => ['leave:manage'],
            'reason' => 'officer escalation attempt',
        ]);

        $this->assertFalse($result['success']);
    }





    /* --------------------------------------------------------------------
     * Helpers
     * -------------------------------------------------------------------- */

    private function databaseAvailable(): bool
    {
        return $this->conn() !== null;
    }

    private function conn(): ?\mysqli
    {
        static $conn = null;
        if ($conn instanceof \mysqli) {
            return $conn;
        }

        try {
            $conn = \App\Helpers\Database::getInstance()->getConnection();

            $check = $conn->query(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'delegations' LIMIT 1"
            );
            if ($check && $check->fetch_assoc()) {
                return $conn;
            }
            $check?->close();
            $conn = null;
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function createTestUser(string $role, ?string $employeeCode = null): int
    {
        $conn = $this->conn();
        if ($conn === null) {
            $this->markTestSkipped('Database unavailable');
        }

        $email = 'delegation-test-' . bin2hex(random_bytes(6)) . '@example.test';
        $password = password_hash('delegation-test-password', PASSWORD_DEFAULT);
        $firstName = 'Delegation';
        $lastName = 'Test';

        $stmt = $conn->prepare(
            'INSERT INTO users (email, first_name, last_name, password, role, is_active, employee_id)
             VALUES (?, ?, ?, ?, ?, 1, ?)'
        );
        $employeeCode = $employeeCode;
        $stmt->bind_param('ssssss', $email, $firstName, $lastName, $password, $role, $employeeCode);
        $stmt->execute();
        $userId = (int) $conn->insert_id;
        $stmt->close();

        $this->createdUserIds[] = $userId;
        return $userId;
    }

    private function insertDelegation(
        int $delegateUserId,
        string $delegatedRole,
        string $scopeType,
        int $scopeId,
        string $status,
        array $permissions,
        string $startOffset = '-1 day',
        string $endOffset = '+14 days'
    ): int {
        $conn = $this->conn();
        if ($conn === null) {
            $this->markTestSkipped('Database unavailable');
        }
        $delegatorId = $this->createTestUser('super_admin');

        $start = date('Y-m-d', strtotime($startOffset));
        $end = date('Y-m-d', strtotime($endOffset));
        $permJson = json_encode($permissions);

        $stmt = $conn->prepare(
            "INSERT INTO delegations
                (delegator_user_id, delegate_user_id, delegated_role, scope_type, scope_id,
                 permissions, start_date, end_date, reason, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'delegation test', ?, ?)"
        );
        $stmt->bind_param(
            'iississssi',
            $delegatorId,
            $delegateUserId,
            $delegatedRole,
            $scopeType,
            $scopeId,
            $permJson,
            $start,
            $end,
            $status,
            $delegatorId
        );
        $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();

        $this->createdDelegationIds[] = $id;
        return $id;
    }

    /**
     * An existing active employee WITH a section, used read-only to satisfy
     * the users ↔ employees join for scope tests. The test never mutates the
     * employee row — only the throwaway test user points at its code.
     */
    private function firstActiveEmployeeInSection(): ?array
    {
        $conn = $this->conn();
        if ($conn === null) {
            return null;
        }
        $res = $conn->query(
            "SELECT id, employee_id, section_id, subsection_id, department_id
             FROM employees
             WHERE employee_status = 'active' AND section_id IS NOT NULL
             LIMIT 1"
        );
        if (!$res) {
            return null;
        }
        $row = $res->fetch_assoc();
        $res->close();
        return $row ?: null;
    }


    private function resetEngine(): void
    {
        foreach (
            [
                [\App\Helpers\AuthorizationService::class, 'instance'],
                [\App\Services\DelegationService::class, 'instance'],
            ] as [$class, $prop]
        ) {
            try {
                $ref = new \ReflectionProperty($class, $prop);
            } catch (\ReflectionException $e) {
                continue;
            }
            $ref->setAccessible(true);
            $ref->setValue(null, null);
        }
    }

    private function cleanupTestData(): void
    {
        if (empty($this->createdUserIds)) {
            $this->createdDelegationIds = [];
            return;
        }

        try {
            $conn = \App\Helpers\Database::getInstance()->getConnection();

            // Delegations cascade on user deletion, but delete explicitly first
            // so a failure mid-cleanup never leaves orphan authority rows.
            if (!empty($this->createdDelegationIds)) {
                $conn->query('DELETE FROM delegations WHERE id IN (' . implode(',', array_map('intval', $this->createdDelegationIds)) . ')');
            }
            foreach ($this->createdUserIds as $userId) {
                $stmt = $conn->prepare('DELETE FROM user_page_permissions WHERE user_id = ?');
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare('DELETE FROM delegations WHERE delegator_user_id = ? OR delegate_user_id = ?');
                $stmt->bind_param('ii', $userId, $userId);
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
        $this->createdDelegationIds = [];
    }

}

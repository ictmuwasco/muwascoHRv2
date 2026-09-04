<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use Tests\TestCase;

/**
 * Default Role Access Matrix tests (Phase: Role, Page & Permission
 * restriction system).
 *
 * Locks the documented default matrix (docs/AUTHORIZATION.md) through the
 * REAL authorization engine against the seeded role_permissions table:
 *
 *   Officer / Employee     Dashboard, Profile, Attendance [own], My Meetings,
 *                          Leave Application, Leave Profile [own] — nothing else
 *   Sub-section Head       officer access + unit-scoped leave management extras
 *   Section Head           hierarchical access + section leave management
 *   Department Head        hierarchical access + department leave management
 *   HR Manager             all HR modules EXCEPT Settings + permission
 *                          administration (permission_overrides + users denied)
 *   Managing Director      everything EXCEPT Settings + permission administration
 *   Super Admin            everything (engine policy)
 *   settings:notifications self-service tab granted to ALL roles
 *
 * DB-dependent assertions are skipped when the configured test database is
 * unreachable, so the suite runs everywhere.
 *
 * Place: backend/tests/Unit/Authorization/RolePermissionMatrixTest.php
 */
class RolePermissionMatrixTest extends TestCase
{
    /** @var array<string, int> role => created user id */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
        $_SESSION['session_valid'] = true;
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        if (!empty($this->users)) {
            try {
                $conn = \App\Helpers\Database::getInstance()->getConnection();
                foreach ($this->users as $userId) {
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
        }
        $this->users = [];
        $_SESSION = [];

        parent::tearDown();
    }

    /* ====================================================================
     * Officer (Section 4 + Section 33 test matrix)
     * ==================================================================== */

    public function testOfficerDefaultMatrix(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }
        $service = $this->engineFor('officer');

        // ALLOW — own pages and own records only.
        $this->assertTrue($service->hasPermission($this->uid('officer'), 'dashboard', 'view'));
        $this->assertTrue($service->hasPermission($this->uid('officer'), 'profile', 'view'));
        $this->assertTrue($service->hasPermission($this->uid('officer'), 'profile', 'edit'));
        $this->assertTrue($service->hasPermission($this->uid('officer'), 'attendance', 'view'));
        $this->assertTrue($service->hasPermission($this->uid('officer'), 'meetings', 'view'));
        $this->assertTrue($service->hasPermission($this->uid('officer'), 'meetings', 'confirm'));
        $this->assertTrue($service->hasPermission($this->uid('officer'), 'leave', 'view'));
        $this->assertTrue($service->hasPermission($this->uid('officer'), 'leave', 'apply'));
        $this->assertTrue($service->hasPermission($this->uid('officer'), 'settings', 'notifications'));
        // Leave Profile page is open to all roles with leave:view (own record).
        $this->assertFalse($service->hasPermission($this->uid('officer'), 'meetings', 'dashboard'), 'Officer must NOT see the org-wide meetings dashboard');
        // Phase 10: Roster/Reports/Employees are HR-only modules.
        $this->assertFalse($service->hasPermission($this->uid('officer'), 'leave', 'roster'), 'Officer must NOT see the Roster (HR-only)');
        $this->assertFalse($service->hasPermission($this->uid('officer'), 'reports', 'view'), 'Officer must NOT see Reports');
        $this->assertFalse($service->hasPermission($this->uid('officer'), 'employees', 'view'), 'Officer must NOT see the Employees module');

        // DENY — management pages, other employees' records, administration.
        $this->assertFalse($service->hasPermission($this->uid('officer'), 'attendance', 'manage'), 'Officer must not access HR attendance management');
        $this->assertFalse($service->hasPermission($this->uid('officer'), 'leave', 'manage'), 'Officer must not manage leave');
        $this->assertFalse($service->hasPermission($this->uid('officer'), 'leave', 'approve'), 'Officer must not approve leave');
        $this->assertFalse($service->hasPermission($this->uid('officer'), 'workplan', 'view'), 'Officer must not open workplans');
        $this->assertFalse($service->hasPermission($this->uid('officer'), 'performance', 'view'), 'Officer must not open Appraisal');
        $this->assertFalse($service->hasPermission($this->uid('officer'), 'users', 'view'), 'Officer must not access user management');
        $this->assertFalse($service->hasPermission($this->uid('officer'), 'settings', 'view'), 'Officer must be DENIED the Settings page');
        $this->assertFalse($service->hasPermission($this->uid('officer'), 'settings', 'permissions'), 'Officer must be DENIED permission management');
        $this->assertFalse($service->hasPermission($this->uid('officer'), 'permission_overrides', 'manage'));
    }

    /* ====================================================================
     * Sub-section / Section / Department Heads (Sections 5-7)
     * ==================================================================== */

    public function testSubSectionHeadDefaultMatrix(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }
        $service = $this->engineFor('sub_section_head');
        $uid = $this->uid('sub_section_head');

        // Officer-level access.
        $this->assertTrue($service->hasPermission($uid, 'dashboard', 'view'));
        $this->assertTrue($service->hasPermission($uid, 'attendance', 'view'));
        $this->assertTrue($service->hasPermission($uid, 'leave', 'view'));
        $this->assertTrue($service->hasPermission($uid, 'leave', 'apply'));

        // Sub-section leave management.
        $this->assertTrue($service->hasPermission($uid, 'leave', 'manage'), 'Sub-section head manages leave for own subsection');
        $this->assertTrue($service->hasPermission($uid, 'leave', 'approve'));

        // Phase 10: Employees/Roster/Reports are HR-only administrative
        // modules - heads must NOT receive them by default (leave:manage
        // no longer implies Roster access).
        $this->assertFalse($service->hasPermission($uid, 'employees', 'view'), 'Sub-section head must NOT see the Employees module');
        $this->assertFalse($service->hasPermission($uid, 'reports', 'view'), 'Sub-section head must NOT see Reports');
        $this->assertFalse($service->hasPermission($uid, 'leave', 'roster'), 'Sub-section head must NOT see the Roster (HR-only)');

        // Phase 11 (migration 039): Attendance Dashboard + HR Admin group are
        // HR-restricted (hr_manager / managing_director / super_admin only).
        $this->assertFalse($service->hasPermission($uid, 'attendance', 'manage'), 'Sub-section head must NOT open the Attendance Dashboard');
        $this->assertFalse($service->hasPermission($uid, 'financial_year', 'view'), 'Sub-section head must NOT see the HR Admin group');
        $this->assertFalse($service->hasPermission($uid, 'performance', 'cycles'), 'Sub-section head must NOT manage Appraisal Cycles');

        // Settings denied (Sections 8/27).
        $this->assertFalse($service->hasPermission($uid, 'settings', 'view'));
        $this->assertFalse($service->hasPermission($uid, 'permission_overrides', 'manage'));
    }

    public function testSectionHeadDefaultMatrix(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }
        $service = $this->engineFor('section_head');
        $uid = $this->uid('section_head');

        $this->assertTrue($service->hasPermission($uid, 'dashboard', 'view'));
        $this->assertTrue($service->hasPermission($uid, 'attendance', 'view'));
        $this->assertTrue($service->hasPermission($uid, 'leave', 'view'));
        $this->assertTrue($service->hasPermission($uid, 'leave', 'apply'));
        $this->assertTrue($service->hasPermission($uid, 'leave', 'manage'), 'Section head manages leave for own section');
        $this->assertTrue($service->hasPermission($uid, 'leave', 'approve'));

        // Phase 10: Employees/Roster/Reports are HR-only administrative modules.
        $this->assertFalse($service->hasPermission($uid, 'employees', 'view'), 'Section head must NOT see the Employees module');
        $this->assertFalse($service->hasPermission($uid, 'reports', 'view'), 'Section head must NOT see Reports');
        $this->assertFalse($service->hasPermission($uid, 'leave', 'roster'), 'Section head must NOT see the Roster (HR-only)');

        // Phase 11 (migration 039): Attendance Dashboard + HR Admin group are
        // HR-restricted (hr_manager / managing_director / super_admin only).
        $this->assertFalse($service->hasPermission($uid, 'attendance', 'manage'), 'Section head must NOT open the Attendance Dashboard');
        $this->assertFalse($service->hasPermission($uid, 'financial_year', 'view'), 'Section head must NOT see the HR Admin group');
        $this->assertFalse($service->hasPermission($uid, 'performance', 'cycles'), 'Section head must NOT manage Appraisal Cycles');

        $this->assertFalse($service->hasPermission($uid, 'settings', 'view'), 'Section head must be DENIED Settings');
        $this->assertFalse($service->hasPermission($uid, 'settings', 'users'));
        $this->assertFalse($service->hasPermission($uid, 'permission_overrides', 'manage'));
    }

    public function testDepartmentHeadDefaultMatrix(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }
        $service = $this->engineFor('dept_head');
        $uid = $this->uid('dept_head');

        $this->assertTrue($service->hasPermission($uid, 'dashboard', 'view'));
        $this->assertTrue($service->hasPermission($uid, 'attendance', 'view'));
        $this->assertTrue($service->hasPermission($uid, 'leave', 'manage'), 'Department head manages leave for own department');
        $this->assertTrue($service->hasPermission($uid, 'leave', 'approve'));
        $this->assertTrue($service->hasPermission($uid, 'leave', 'reject'));

        // Phase 10: Employees/Roster/Reports are HR-only administrative modules.
        $this->assertFalse($service->hasPermission($uid, 'employees', 'view'), 'Department head must NOT see the Employees module');
        $this->assertFalse($service->hasPermission($uid, 'reports', 'view'), 'Department head must NOT see Reports');
        $this->assertFalse($service->hasPermission($uid, 'leave', 'roster'), 'Department head must NOT see the Roster (HR-only)');

        // Phase 11 (migration 039): Attendance Dashboard + HR Admin group are
        // HR-restricted (hr_manager / managing_director / super_admin only).
        $this->assertFalse($service->hasPermission($uid, 'attendance', 'manage'), 'Department head must NOT open the Attendance Dashboard');
        $this->assertFalse($service->hasPermission($uid, 'financial_year', 'view'), 'Department head must NOT see the HR Admin group');
        $this->assertFalse($service->hasPermission($uid, 'performance', 'cycles'), 'Department head must NOT manage Appraisal Cycles');

        $this->assertFalse($service->hasPermission($uid, 'settings', 'view'), 'Department head must be DENIED Settings');
        $this->assertFalse($service->hasPermission($uid, 'permission_overrides', 'manage'));
    }

    /* ====================================================================
     * HR Manager (Section 8) — everything EXCEPT Settings APIs and
     * permission administration.
     * ==================================================================== */

    public function testHrManagerDefaultMatrix(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }
        $service = $this->engineFor('hr_manager');
        $uid = $this->uid('hr_manager');

        // All HR modules.
        $this->assertTrue($service->hasPermission($uid, 'dashboard', 'view'));
        $this->assertTrue($service->hasPermission($uid, 'employees', 'view'));
        $this->assertTrue($service->hasPermission($uid, 'employees', 'create'));
        $this->assertTrue($service->hasPermission($uid, 'departments', 'view'));
        $this->assertTrue($service->hasPermission($uid, 'attendance', 'view'));
        $this->assertTrue($service->hasPermission($uid, 'attendance', 'manage'));
        $this->assertTrue($service->hasPermission($uid, 'leave', 'manage'));
        $this->assertTrue($service->hasPermission($uid, 'leave', 'approve'));
        $this->assertTrue($service->hasPermission($uid, 'reports', 'view'));
        $this->assertTrue($service->hasPermission($uid, 'reports', 'export'));
        $this->assertTrue($service->hasPermission($uid, 'leave', 'roster'), 'HR Manager owns the Leave Roster (HR-only module)');
        $this->assertTrue($service->hasPermission($uid, 'performance', 'cycles'), 'HR Manager owns the HR Admin Appraisal Cycles page');
        $this->assertTrue($service->hasPermission($uid, 'meetings', 'manage'));
        $this->assertTrue($service->hasPermission($uid, 'meetings', 'dashboard'), 'HR Manager may open the org-wide meetings dashboard');
        $this->assertTrue($service->hasPermission($uid, 'payroll', 'view'));

        // Settings page, tabs, settings APIs and permission management DENIED.
        $this->assertFalse($service->hasPermission($uid, 'settings', 'view'), 'HR Manager must be DENIED the Settings page');
        $this->assertFalse($service->hasPermission($uid, 'settings', 'users'), 'HR Manager must be DENIED the users tab API surface');
        $this->assertFalse($service->hasPermission($uid, 'settings', 'audit'));
        $this->assertFalse($service->hasPermission($uid, 'settings', 'permissions'));
        $this->assertFalse($service->hasPermission($uid, 'settings', 'monitoring'));
        $this->assertFalse($service->hasPermission($uid, 'users', 'view'), 'HR Manager must be DENIED the user-management API (Settings API)');
        $this->assertFalse($service->hasPermission($uid, 'users', 'create'));
        $this->assertFalse($service->hasPermission($uid, 'users', 'edit'));
        $this->assertFalse($service->hasPermission($uid, 'permission_overrides', 'view'), 'HR Manager must be DENIED permission management');
        $this->assertFalse($service->hasPermission($uid, 'permission_overrides', 'manage'));
        $this->assertFalse($service->hasPermission($uid, 'admin', 'view'), 'HR Manager must be DENIED the Settings API (GET /settings)');
        $this->assertFalse($service->hasPermission($uid, 'admin', 'manage'), 'HR Manager must be DENIED the Settings API (PUT /settings)');

        // Self-service notifications tab stays available.
        $this->assertTrue($service->hasPermission($uid, 'settings', 'notifications'));
    }

    /* ====================================================================
     * Managing Director (Section 10) — everything EXCEPT Settings.
     * ==================================================================== */

    public function testManagingDirectorDefaultMatrix(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }
        $service = $this->engineFor('managing_director');
        $uid = $this->uid('managing_director');

        // All operational modules. Phase 10: Employees/Roster are HR-only;
        // Phase 11 (migration 039): Reports was re-granted to the Managing
        // Director as an oversight consumer (heads stay denied).
        $this->assertTrue($service->hasPermission($uid, 'dashboard', 'view'));
        $this->assertFalse($service->hasPermission($uid, 'employees', 'view'), 'Managing Director must NOT see the Employees module by default');
        $this->assertTrue($service->hasPermission($uid, 'departments', 'edit'));
        $this->assertTrue($service->hasPermission($uid, 'attendance', 'view'));
        $this->assertTrue($service->hasPermission($uid, 'attendance', 'manage'));
        $this->assertTrue($service->hasPermission($uid, 'leave', 'approve'));
        $this->assertTrue($service->hasPermission($uid, 'leave', 'invalidate'));
        $this->assertTrue($service->hasPermission($uid, 'reports', 'view'), 'Managing Director may open Reports (oversight consumer, migration 039)');
        $this->assertTrue($service->hasPermission($uid, 'reports', 'export'));
        $this->assertTrue($service->hasPermission($uid, 'performance', 'cycles'), 'Managing Director may open the HR Admin Appraisal Cycles page');
        $this->assertFalse($service->hasPermission($uid, 'leave', 'roster'), 'Managing Director must NOT see the Roster by default');
        $this->assertTrue($service->hasPermission($uid, 'meetings', 'create'));
        $this->assertTrue($service->hasPermission($uid, 'meetings', 'dashboard'), 'Managing Director may open the org-wide meetings dashboard');
        $this->assertTrue($service->hasPermission($uid, 'strategic_plan', 'manage'));
        $this->assertTrue($service->hasPermission($uid, 'workplan', 'manage'));
        $this->assertTrue($service->hasPermission($uid, 'payroll', 'manage'));
        $this->assertTrue($service->hasPermission($uid, 'holidays', 'create'));

        // Settings + permission administration DENIED.
        $this->assertFalse($service->hasPermission($uid, 'settings', 'view'), 'Managing Director must be DENIED the Settings page');
        $this->assertFalse($service->hasPermission($uid, 'settings', 'users'));
        $this->assertFalse($service->hasPermission($uid, 'settings', 'permissions'));
        $this->assertFalse($service->hasPermission($uid, 'settings', 'monitoring'));
        $this->assertFalse($service->hasPermission($uid, 'permission_overrides', 'view'), 'Managing Director must be DENIED permission management');
        $this->assertFalse($service->hasPermission($uid, 'permission_overrides', 'manage'));
        $this->assertFalse($service->hasPermission($uid, 'users', 'create'), 'Managing Director must be DENIED user administration');
        $this->assertFalse($service->hasPermission($uid, 'admin', 'view'), 'Managing Director must be DENIED system settings');
        $this->assertFalse($service->hasPermission($uid, 'system_errors', 'view'));

        // Self-service notifications tab stays available.
        $this->assertTrue($service->hasPermission($uid, 'settings', 'notifications'));
    }

    /* ====================================================================
     * Super Admin (Section 9) — everything.
     * ==================================================================== */

    public function testSuperAdminDefaultMatrix(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }
        $service = $this->engineFor('super_admin');
        $uid = $this->uid('super_admin');

        $this->assertTrue($service->hasPermission($uid, 'settings', 'view'), 'Super Admin must have full Settings access');
        $this->assertTrue($service->hasPermission($uid, 'settings', 'users'));
        $this->assertTrue($service->hasPermission($uid, 'settings', 'permissions'));
        $this->assertTrue($service->hasPermission($uid, 'settings', 'monitoring'));
        $this->assertTrue($service->hasPermission($uid, 'permission_overrides', 'manage'));
        $this->assertTrue($service->hasPermission($uid, 'users', 'delete'));
        $this->assertTrue($service->hasPermission($uid, 'attendance', 'manage'));
        $this->assertTrue($service->hasPermission($uid, 'leave', 'roster'), 'Super Admin has the Leave Roster');
        $this->assertTrue($service->hasPermission($uid, 'employees', 'view'), 'Super Admin has the Employees module');
        $this->assertTrue($service->hasPermission($uid, 'reports', 'view'), 'Super Admin has Reports');
        $this->assertTrue($service->hasPermission($uid, 'meetings', 'dashboard'), 'Super Admin has the org-wide meetings dashboard');
    }

    /* ====================================================================
     * All roles — Leave Profile page (every role may open /leave/profile
     * with own-record data scope; manager + bod_chairman seeded leave:view).
     * ==================================================================== */

    public function testEveryRoleCanOpenTheirOwnLeaveProfile(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }
        $roles = [
            'officer', 'employee', 'sub_section_head', 'section_head',
            'dept_head', 'manager', 'hr_manager', 'managing_director',
            'bod_chairman', 'super_admin',
        ];
        foreach ($roles as $role) {
            $uid = $this->uid($role);
            $service = $this->engineFor($role);
            $this->assertTrue(
                $service->hasPermission($uid, 'leave', 'view'),
                "Role '{$role}' must be able to open the Leave Profile page (leave:view)"
            );
        }

        // Self-service roles must NOT gain leave-management just from profile
        // visibility (heads/HR/MD hold leave:manage by their own explicit
        // seeds; super_admin is policy-allowed). Locked here so the new
        // manager/bod_chairman leave:view seed never leaks manage.
        foreach (['officer', 'employee', 'manager', 'bod_chairman'] as $role) {
            $uid = $this->uid($role);
            $this->assertFalse(
                $this
                    ->engineFor($role)
                    ->hasPermission($uid, 'leave', 'manage'),
                "Self-service role '{$role}' must NOT gain leave management from profile visibility"
            );
        }
    }

    /* ====================================================================
     * Unknown roles / default deny (Section 11)
     * ==================================================================== */

    public function testUnknownRoleAndUnknownPermissionDefaultToDeny(): void
    {
        if (!$this->databaseAvailable()) {
            $this->markTestSkipped('Database unavailable');
        }
        $userId = $this->createMatrixUser('made_up_role_xyz');
        $this->users['made_up_role_xyz'] = $userId;

        $_SESSION['user_id'] = $userId;
        $_SESSION['user_role'] = 'made_up_role_xyz';
        $service = \App\Helpers\AuthorizationService::getInstance();

        $this->assertFalse($service->hasPermission($userId, 'dashboard', 'view'), 'Unknown role = DENY');
        $this->assertFalse($service->hasPermission($userId, 'made_up_module', 'view'), 'Unknown permission = DENY');
    }

    /* ====================================================================
     * Helpers
     * ==================================================================== */

    private function databaseAvailable(): bool
    {
        try {
            $conn = \App\Helpers\Database::getInstance()->getConnection();
            $check = $conn->query(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'role_permissions' LIMIT 1"
            );

            return $check && (bool) $check->fetch_assoc();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function uid(string $role): int
    {
        if (!isset($this->users[$role])) {
            $this->users[$role] = $this->createMatrixUser($role);
        }

        return $this->users[$role];
    }

    private function engineFor(string $role): \App\Helpers\AuthorizationService
    {
        $userId = $this->uid($role);
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_role'] = $role;

        return \App\Helpers\AuthorizationService::getInstance();
    }

    private function createMatrixUser(string $role): int
    {
        $conn = \App\Helpers\Database::getInstance()->getConnection();

        $email = 'matrix-test-' . bin2hex(random_bytes(6)) . '@example.test';
        $password = password_hash('matrix-test-password', PASSWORD_DEFAULT);
        $firstName = 'Matrix';
        $lastName = 'RoleTest';
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

        return $userId;
    }
}

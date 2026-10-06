<?php

declare(strict_types=1);

namespace Tests\Integration\Security;

use App\Services\Vault\VaultException;
use App\Services\Vault\VaultService;
use PHPUnit\Framework\TestCase;

/**
 * The vault authorization contract, exercised against a real scratch database.
 *
 * WHY A REAL DATABASE
 *   The properties under test are enforced by SQL - "active AND not expired",
 *   "scoped to this employee" - so a mock would only test the mock. Every
 *   statement runs against MariaDB with the production shape, and the schema
 *   comes from migration 105 itself, so the shipped DDL is covered too.
 *
 * ISOLATION
 *   A database named vault_access_test is created and dropped per test. The
 *   setUp guard refuses to run anything unless it can prove it is connected to
 *   that scratch database, so the application's own data is never at risk.
 *
 * WHAT IS PROVEN
 *   1.  an employee resolves to their OWN employee record
 *   2.  a vault can only be set up once
 *   3.  the owner reads their own vault
 *   4.  a NON-OWNER with no grant gets 404, not 403 - the response must not
 *       confirm that a vault exists at all
 *   5.  approving a request does NOT grant access by itself
 *   6.  a grant makes ciphertext readable, and only while it is live
 *   7.  an EXPIRED grant is denied
 *   8.  a revoked grant is denied
 *   9.  a non-owner cannot decide another employee's request
 *  10.  a stale version write is refused
 */
final class VaultAccessTest extends TestCase
{
    private const SCRATCH_DB = 'vault_access_test';

    /** user id => employee id */
    private const OWNER_USER = 1;
    private const OWNER_EMP  = 1;
    private const HR_USER    = 2;
    private const HR_EMP     = 2;
    private const THIRD_USER = 3;
    private const THIRD_EMP  = 3;

    private ?\mysqli $conn = null;

    protected function setUp(): void
    {
        $host = (string) config('database.connections.mysql.host');
        $port = (int) (config('database.connections.mysql.port') ?: 3306);
        $user = (string) config('database.connections.mysql.username');
        $pass = (string) config('database.connections.mysql.password');

        $root = @new \mysqli($host, $user, $pass, null, $port);
        if ($root->connect_errno) {
            $this->markTestSkipped('No MySQL connection available for the scratch database.');
        }
        $root->query('DROP DATABASE IF EXISTS `' . self::SCRATCH_DB . '`');
        $root->query('CREATE DATABASE `' . self::SCRATCH_DB . '` DEFAULT CHARACTER SET utf8mb4');
        $root->close();

        $this->conn = @new \mysqli($host, $user, $pass, self::SCRATCH_DB, $port);
        if ($this->conn->connect_errno) {
            $this->markTestSkipped('Could not open the scratch database.');
        }

        // Guard: never assert against the application's own data.
        $this->assertSame(
            self::SCRATCH_DB,
            (string) $this->conn->query('SELECT DATABASE()')->fetch_row()[0],
            'refusing to run outside the scratch database'
        );

        $this->createSchema();
        $this->seedPeople();
    }

    protected function tearDown(): void
    {
        if ($this->conn !== null) {
            $this->conn->close();
            $this->conn = null;
        }

        $host = (string) config('database.connections.mysql.host');
        $port = (int) (config('database.connections.mysql.port') ?: 3306);
        $user = (string) config('database.connections.mysql.username');
        $pass = (string) config('database.connections.mysql.password');

        $root = @new \mysqli($host, $user, $pass, null, $port);
        if (!$root->connect_errno) {
            $root->query('DROP DATABASE IF EXISTS `' . self::SCRATCH_DB . '`');
            $root->close();
        }
    }

    /**
     * Build the scratch schema: the columns the vault joins touch, plus
     * migration 105's own tables so the shipped DDL is covered.
     */
    private function createSchema(): void
    {
        $m = $this->conn;

        $m->query('CREATE TABLE employees (
            id int(11) NOT NULL AUTO_INCREMENT,
            employee_id varchar(50) DEFAULT NULL,
            first_name varchar(100) DEFAULT NULL,
            last_name varchar(100) DEFAULT NULL,
            department_id int(11) DEFAULT NULL,
            section_id int(11) DEFAULT NULL,
            subsection_id int(11) DEFAULT NULL,
            office_id int(11) DEFAULT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB');

        // `designation` is required: User::findById() selects it, and
        // AuthorizationService::resolveUserRole() goes through that model. A
        // missing column makes role resolution throw, which resolveUserRole
        // swallows into an empty role - and then every permission check
        // denies, which looks like a vault bug rather than a schema gap.
        $m->query("CREATE TABLE users (
            id int(11) NOT NULL AUTO_INCREMENT,
            employee_id varchar(50) DEFAULT NULL,
            email varchar(255) DEFAULT NULL,
            first_name varchar(100) DEFAULT NULL,
            last_name varchar(100) DEFAULT NULL,
            designation varchar(150) DEFAULT NULL,
            role enum('hr_manager','super_admin','employee') DEFAULT 'employee',
            is_active tinyint(1) DEFAULT 1,
            PRIMARY KEY (id),
            KEY ix_users_employee (employee_id)
        ) ENGINE=InnoDB");

        $m->query("CREATE TABLE audit_logs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id int(10) unsigned DEFAULT NULL,
            user_name_snapshot varchar(255) DEFAULT NULL,
            user_role_snapshot varchar(100) DEFAULT NULL,
            request_id varchar(64) DEFAULT NULL,
            action varchar(100) NOT NULL,
            module varchar(100) NOT NULL,
            description text,
            target_type varchar(100) DEFAULT NULL,
            target_id bigint(20) unsigned DEFAULT NULL,
            target_name varchar(255) DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent text,
            location varchar(255) DEFAULT NULL,
            old_values longtext, new_values longtext, metadata longtext,
            status varchar(50) NOT NULL DEFAULT 'SUCCESS',
            created_at datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id)
        ) ENGINE=InnoDB");

        $m->query("CREATE TABLE notifications (
            id int(11) NOT NULL AUTO_INCREMENT,
            user_id int(11) NOT NULL,
            title varchar(255) NOT NULL,
            message text,
            type varchar(100) DEFAULT NULL,
            category varchar(100) DEFAULT NULL,
            action_url varchar(500) DEFAULT NULL,
            is_read tinyint(1) DEFAULT 0,
            is_sent tinyint(1) DEFAULT 1,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id)
        ) ENGINE=InnoDB");

        // The RBAC matrix. VaultService::requesterMayViewEmployee() delegates
        // to EmployeePolicy::canView(), which reads employees:view from here -
        // so the "a grant must not be a side door around RBAC" guard can only be
        // tested if this table exists with a realistic shape.
        $m->query("CREATE TABLE role_permissions (
            id int(11) NOT NULL AUTO_INCREMENT,
            role varchar(50) NOT NULL,
            module varchar(50) NOT NULL,
            action varchar(50) NOT NULL,
            is_granted tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_role_module_action (role, module, action)
        ) ENGINE=InnoDB");
        $m->query("CREATE TABLE permissions (
            id int(11) NOT NULL AUTO_INCREMENT,
            module varchar(50) NOT NULL,
            action varchar(50) NOT NULL,
            description varchar(255) DEFAULT NULL,
            is_sensitive tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_perm (module, action)
        ) ENGINE=InnoDB");
        $m->query("INSERT INTO permissions (module, action, description) VALUES
            ('employees', 'view', 'View employee profiles and the directory'),
            ('profile',   'view', 'View your own profile'),
            ('profile',   'edit', 'Edit your own profile')");

        // hr_manager and super_admin may view; a plain employee may not view
        // anyone else. owner has no row for employees:view, which is what
        // makes the negative cases meaningful.
        $m->query("INSERT INTO role_permissions (role, module, action, is_granted) VALUES
            ('hr_manager', 'employees', 'view', 1),
            ('super_admin', 'employees', 'view', 1),
            ('employee',    'employees', 'view', 0),
            ('employee',    'profile',   'view', 1),
            ('employee',    'profile',   'edit', 1),
            ('hr_manager',  'profile',   'view', 1),
            ('hr_manager',  'profile',   'edit', 1)");

        // Run the real migration so the shipped DDL is what is exercised.
        $sql = file_get_contents(dirname(__DIR__, 3) . '/database/migrations/105_employee_vault.sql');
        $this->assertIsString($sql, 'migration 105 must be readable');

        $m->multi_query($sql);
        $err = null;
        do {
            if ($r = $m->store_result()) {
                $r->free();
            }
            if ($m->errno !== 0) {
                $err = $m->error;
                break;
            }
        } while ($m->more_results() && $m->next_result());

        $this->assertNull($err, 'migration 105 must apply cleanly: ' . (string) $err);
    }

    private function seedPeople(): void
    {
        $m = $this->conn;

        $rows = [
            1 => ['EMP001', 'Alice', 'Owner', 'employee'],
            2 => ['EMP002', 'Bob', 'HrBoss', 'hr_manager'],
            3 => ['EMP003', 'Cara', 'Other', 'employee'],
        ];

        foreach ($rows as $id => [$emp, $first, $last, $role]) {
            $s = $m->prepare('INSERT INTO employees (id, employee_id, first_name, last_name) VALUES (?,?,?,?)');
            $s->bind_param('isss', $id, $emp, $first, $last);
            $s->execute();
            $s->close();

            $email = $first . '@example.test';
            $s = $m->prepare('INSERT INTO users (id, employee_id, email, first_name, last_name, role) VALUES (?,?,?,?,?,?)');
            $s->bind_param('isssss', $id, $emp, $email, $first, $last, $role);
            $s->execute();
            $s->close();
        }
    }

    /**
     * Point the app's Database helper at the scratch connection for one test.
     *
     * VaultService calls \db(), so the singleton has to resolve to the scratch
     * database rather than the application's own. Without this the assertions
     * would run against production data - which the setUp guard exists to make
     * impossible to do by accident.
     */
    /**
     * Point the app's Database helper at the scratch connection for one test.
     *
     * VaultService calls \db(), so the singleton has to resolve to the scratch
     * database rather than the application's own. Without this the assertions
     * would run against production data - which the setUp guard exists to make
     * impossible to do by accident.
     */
    private function useScratchConnection(): void
    {
        \App\Helpers\Database::getInstance()->setConnection($this->conn);
    }

    /**
     * Read a single scalar straight from the scratch connection.
     *
     * Asserting on stored state must not go through the application's Database
     * helper: an instrumented result wrapper can hand back a row shape the
     * test did not expect, which surfaces as a confusing "undefined array key"
     * instead of a real assertion failure.
     */
    private function scratchScalar(string $sql): mixed
    {
        $result = $this->conn->query($sql);
        $this->assertNotFalse($result, 'scratch query failed: ' . $sql);

        $row = $result->fetch_row();
        $this->assertIsArray($row, 'scratch query returned no row: ' . $sql);
        $this->assertNotEmpty($row, 'scratch query returned an empty row: ' . $sql);

        return $row[0];
    }

    // =================================================================
    // 1. Ownership resolution
    // =================================================================

    public function testEmployeeResolvesToTheirOwnRecord(): void
    {
        $this->useScratchConnection();
        $vault = VaultService::getInstance();

        $this->assertSame(self::OWNER_EMP, $vault->employeeIdForUser(self::OWNER_USER));
        $this->assertSame(self::HR_EMP, $vault->employeeIdForUser(self::HR_USER));
        $this->assertNull($vault->employeeIdForUser(9999), 'unknown user resolves to null');
    }

    // =================================================================
    // 2. Setup is one-time
    // =================================================================

    public function testVaultCanOnlyBeSetUpOnce(): void
    {
        $this->useScratchConnection();
        $vault = VaultService::getInstance();

        $this->assertFalse($vault->hasVault(self::OWNER_EMP), 'no vault before setup');

        $vault->setup(self::OWNER_USER, self::OWNER_EMP, $this->keyPayload());
        $this->assertTrue($vault->hasVault(self::OWNER_EMP), 'vault exists after setup');

        $this->expectException(VaultException::class);
        $vault->setup(self::OWNER_USER, self::OWNER_EMP, $this->keyPayload());
    }

    public function testSetupRejectsAClientSuppliedSaltThatIsNotHex(): void
    {
        $this->useScratchConnection();
        $vault = VaultService::getInstance();

        $payload = $this->keyPayload();
        $payload['salt'] = 'not-hex!!';

        $this->expectException(VaultException::class);
        $vault->setup(self::OWNER_USER, self::OWNER_EMP, $payload);
    }

    public function testKdfParametersComeFromConfigNotTheClient(): void
    {
        $this->useScratchConnection();
        $vault = VaultService::getInstance();

        // A client trying to register a weak vault.
        $payload = $this->keyPayload();
        $payload['kdf_params'] = json_encode(['algorithm' => 'PBKDF2', 'iterations' => 1]);

        $vault->setup(self::OWNER_USER, self::OWNER_EMP, $payload);

        $row = $vault->keyRowForUser(self::OWNER_USER);
        $this->assertNotNull($row);

        $stored = json_decode((string) $row['kdf_params'], true);
        $this->assertIsArray($stored);
        $this->assertSame(
            (int) config('vault.kdf.iterations'),
            (int) $stored['iterations'],
            'a client must not be able to register a 1-iteration vault'
        );
        $this->assertGreaterThanOrEqual(600000, (int) $stored['iterations']);
    }

    // =================================================================
    // 3/4. Owner reads; a stranger gets 404, not 403
    // =================================================================

    public function testOwnerReadsTheirOwnVault(): void
    {
        $this->useScratchConnection();
        $vault = $this->vaultWithData();

        $result = $vault->readItems(self::OWNER_EMP, self::OWNER_USER);

        $this->assertTrue($result['is_owner']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('personal', $result['items'][0]['field_group']);
        $this->assertNull($result['grant'], 'the owner needs no grant');
    }

    public function testNonOwnerWithoutGrantGetsNotFoundNotForbidden(): void
    {
        $this->useScratchConnection();
        $vault = $this->vaultWithData();

        try {
            $vault->readItems(self::OWNER_EMP, self::HR_USER);
            $this->fail('a non-owner must not be able to read the vault');
        } catch (VaultException $e) {
            $this->assertSame(VaultException::NOT_FOUND, $e->getReason());
            // 404 is deliberate: a 403 would confirm a vault exists here.
            $this->assertSame(404, $e->getHttpStatus());
        }
    }

    public function testUnrelatedEmployeeAlsoGetsNotFound(): void
    {
        $this->useScratchConnection();
        $vault = $this->vaultWithData();

        $this->expectException(VaultException::class);
        $vault->readItems(self::OWNER_EMP, self::THIRD_USER);
    }

    // =================================================================
    // 5/6. Approval alone grants nothing; only a minted grant does
    // =================================================================

    public function testApprovingARequestDoesNotGrantAccessByItself(): void
    {
        $this->useScratchConnection();
        $vault = $this->vaultWithData();

        $requestId = $vault->requestAccess(
            self::OWNER_EMP,
            self::HR_USER,
            'Verifying contract details for audit'
        );
        $vault->decideRequest($requestId, self::OWNER_EMP, 'approved');

        // Approval records a decision. Access still requires the EMPLOYEE's
        // browser to mint a grant - the whole point of the control.
        try {
            $vault->readItems(self::OWNER_EMP, self::HR_USER);
            $this->fail('approval alone must not grant vault access');
        } catch (VaultException $e) {
            $this->assertSame(VaultException::NOT_FOUND, $e->getReason());
        }
    }

    public function testGrantMakesCiphertextReadableToTheGrantee(): void
    {
        $this->useScratchConnection();
        $vault = $this->vaultWithData();

        $requestId = $vault->requestAccess(self::OWNER_EMP, self::HR_USER, 'audit');
        $vault->decideRequest($requestId, self::OWNER_EMP, 'approved');
        $grantId = $vault->createGrant(
            self::OWNER_EMP,
            self::HR_USER,
            'WRAPPED-FOR-HR-BROWSER',
            'audit review'
        );

        $result = $vault->readItems(self::OWNER_EMP, self::HR_USER);

        $this->assertFalse($result['is_owner'], 'the reader is a grantee, not the owner');
        $this->assertCount(1, $result['items']);
        $this->assertSame(
            'WRAPPED-FOR-HR-BROWSER',
            $result['grant']['wrapped_data_key'],
            'the wrapped key is relayed to the grantee'
        );
        $this->assertSame($grantId, $result['grant']['id']);
    }

    // =================================================================
    // 7/8. Expiry and revocation
    // =================================================================

    public function testExpiredGrantIsDenied(): void
    {
        $this->useScratchConnection();
        $vault = $this->vaultWithData();

        $grantId = $vault->createGrant(self::OWNER_EMP, self::HR_USER, 'WRAPPED', 'audit');
        $this->assertCount(1, $vault->readItems(self::OWNER_EMP, self::HR_USER)['items']);

        // Push the expiry into the past. The read query checks expires_at, so
        // this is denied IMMEDIATELY - without the cron running at all.
        $this->conn->query(
            'UPDATE vault_grants SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = '
            . (int) $grantId
        );

        try {
            $vault->readItems(self::OWNER_EMP, self::HR_USER);
            $this->fail('an expired grant must not decrypt anything');
        } catch (VaultException $e) {
            $this->assertSame(VaultException::NOT_FOUND, $e->getReason());
        }
    }

    public function testCronSweepMarksLapsedGrantsExpiredAndAuditsThem(): void
    {
        $this->useScratchConnection();
        $vault = $this->vaultWithData();

        $grantId = $vault->createGrant(self::OWNER_EMP, self::HR_USER, 'WRAPPED', 'audit');
        $this->conn->query(
            'UPDATE vault_grants SET expires_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = '
            . (int) $grantId
        );

        $expired = $vault->expireLapsedGrants();
        $this->assertSame(1, $expired, 'the sweep must retire exactly the lapsed grant');

        $status = $this->scratchScalar(
            'SELECT status FROM vault_grants WHERE id = ' . (int) $grantId
        );
        $this->assertSame('expired', $status);

        $audited = (int) $this->scratchScalar(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'VAULT_ACCESS_EXPIRED'"
        );
        $this->assertSame(1, $audited, 'an expired grant must leave an audit trail');
    }

    public function testRevokedGrantIsDenied(): void
    {
        $this->useScratchConnection();
        $vault = $this->vaultWithData();

        $grantId = $vault->createGrant(self::OWNER_EMP, self::HR_USER, 'WRAPPED', 'audit');
        $this->assertCount(1, $vault->readItems(self::OWNER_EMP, self::HR_USER)['items']);

        $vault->revokeGrant($grantId, self::OWNER_EMP);

        $this->expectException(VaultException::class);
        $vault->readItems(self::OWNER_EMP, self::HR_USER);
    }

    // =================================================================
    // 9/10. Cross-tenant writes and stale versions
    // =================================================================

    public function testNonOwnerCannotDecideAnotherEmployeesRequest(): void
    {
        $this->useScratchConnection();
        $vault = $this->vaultWithData();

        // The HR user opens a request against the owner's record, so there is a
        // real row in vault_requests to point at.
        $requestId = $vault->requestAccess(self::OWNER_EMP, self::HR_USER, 'audit');
        $this->assertGreaterThan(0, $requestId);

        // The owner is a DIFFERENT employee from the requester's target, and
        // decideRequest() scopes its lookup to the CALLER's employee id. The
        // third employee tries to answer a request that is not theirs.
        try {
            $vault->decideRequest($requestId, self::THIRD_EMP, 'approved');
            $this->fail('a non-owner must not decide a request');
        } catch (VaultException $e) {
            $this->assertSame(VaultException::NOT_FOUND, $e->getReason());
        }

        $status = $this->scratchScalar(
            'SELECT status FROM vault_requests WHERE id = ' . (int) $requestId
        );
        $this->assertSame('pending', $status, 'the request must be left untouched');
    }

    public function testStaleVersionWriteIsRefused(): void
    {
        $this->useScratchConnection();
        $vault = $this->vaultWithData();

        // The stored version is 1. Claiming to write version 99 means this tab
        // is stale, so the write must be refused rather than clobbering the
        // newer value.
        try {
            $vault->writeItem(
                self::OWNER_EMP,
                'personal',
                base64_encode('STALE'),
                str_repeat('ab', 12),
                '1:personal:v2',
                99
            );
            $this->fail('a stale write must be refused');
        } catch (VaultException $e) {
            $this->assertSame(VaultException::CONFLICT, $e->getReason());
        }
    }

    public function testOwnerCannotGrantThemselves(): void
    {
        $this->useScratchConnection();
        $vault = $this->vaultWithData();

        $this->expectException(VaultException::class);
        $vault->createGrant(self::OWNER_EMP, self::OWNER_USER, 'WRAPPED', 'me');
    }

    // =================================================================
    // The locked-field contract
    // =================================================================

    public function testLockStateNeverReportsALockedFieldAsEmpty(): void
    {
        $this->useScratchConnection();
        $vault = $this->vaultWithData();

        $locked = $vault->lockState(self::OWNER_EMP, self::HR_USER);
        $this->assertSame('locked', $locked['state'], 'a set-up vault reads as LOCKED, not empty');
        $this->assertTrue($locked['can_request']);

        $vault->requestAccess(self::OWNER_EMP, self::HR_USER, 'audit');
        $this->assertSame('pending', $vault->lockState(self::OWNER_EMP, self::HR_USER)['state']);

        $vault->createGrant(self::OWNER_EMP, self::HR_USER, 'WRAPPED', 'audit');
        $granted = $vault->lockState(self::OWNER_EMP, self::HR_USER);
        $this->assertSame('granted', $granted['state']);
        $this->assertNotNull($granted['expires_at']);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /** A syntactically valid setup payload (contents are never decrypted). */
    private function keyPayload(): array
    {
        return [
            'public_key'                  => 'BASE64-SPKI',
            'wrapped_private_key'         => 'BASE64-WRAPPED-PRIVATE',
            'wrapped_data_key_passphrase' => 'BASE64-WRAPPED-PASSPHRASE',
            'wrapped_data_key_recovery'   => 'BASE64-WRAPPED-RECOVERY',
            'salt'                        => str_repeat('ab', 32),
        ];
    }

    /** A vault with one encrypted group, owned by OWNER_EMP. */
    private function vaultWithData(): VaultService
    {
        $vault = VaultService::getInstance();
        $vault->setup(self::OWNER_USER, self::OWNER_EMP, $this->keyPayload());
        $vault->writeItem(
            self::OWNER_EMP,
            'personal',
            base64_encode('ENCRYPTED-BLOB'),
            str_repeat('cd', 12),
            '1:personal:v1',
            0
        );
        return $vault;
    }
}

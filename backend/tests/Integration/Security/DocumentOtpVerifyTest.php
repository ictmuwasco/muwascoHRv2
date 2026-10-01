<?php

declare(strict_types=1);

namespace Tests\Integration\Security;

use App\Services\Security\DocumentAccessService;
use PHPUnit\Framework\TestCase;

/**
 * The OTP verification contract, against a real database.
 *
 * THE BUG THIS SUITE WAS WRITTEN FOR
 *   Both verify methods filtered on `verified_at IS NOT NULL` when looking up
 *   the pending request. Since verified_at only becomes non-NULL *after* a
 *   successful verification, NO request could ever match - so every code was
 *   rejected with `no_live_approval` and the flow was simply broken.
 *
 *   Worse, the attempt counter only increments on a genuine mismatch. If the
 *   row was never found, nothing was counted, so the 5-attempt brute-force
 *   limit never engaged either. The protection was inert in the exact case it
 *   was written for.
 *
 *   Verified against migration 106 on a scratch database, then confirmed live:
 *   a wrong code must now return `incorrect_code` AND increment attempts, and
 *   the limit must actually bite.
 */
final class DocumentOtpVerifyTest extends TestCase
{
    private const SCRATCH_DB = 'vault_otp_verify_test';

    private const EMP_ID    = 1;   // the document owner
    private const EMP_USER  = 1;
    private const REQ_USER  = 2;   // the requester (HR)

    private ?\mysqli $conn = null;
    private ?DocumentAccessService $service = null;

    protected function setUp(): void
    {
        // Mirror what backend/bootstrap.php does for a real request. Without
        // this the CLI default applies (Europe/Berlin on this machine), which
        // is one hour behind the database clock; every expires_at the service
        // writes is then stamped in the past and the row looks born expired.
        // The application is correct - the harness has to stand in the same
        // environment it is testing.
        date_default_timezone_set('Africa/Nairobi');

        $host = (string) config('database.connections.mysql.host');
        $port = (int) (config('database.connections.mysql.port') ?: 3306);
        $user = (string) config('database.connections.mysql.username');
        $pass = (string) config('database.connections.mysql.password');

        $root = @new \mysqli($host, $user, $pass, null, $port);
        if ($root->connect_errno) {
            $this->markTestSkipped('No MySQL connection available.');
        }
        $root->query('DROP DATABASE IF EXISTS `' . self::SCRATCH_DB . '`');
        $root->query('CREATE DATABASE `' . self::SCRATCH_DB . '` DEFAULT CHARACTER SET utf8mb4');
        $root->close();

        $this->conn = @new \mysqli($host, $user, $pass, self::SCRATCH_DB, $port);
        if ($this->conn->connect_errno) {
            $this->markTestSkipped('Could not open the scratch database.');
        }

        $this->assertSame(
            self::SCRATCH_DB,
            (string) $this->conn->query('SELECT DATABASE()')->fetch_row()[0],
            'refusing to run outside the scratch database'
        );

        $this->createSchema();
        $this->seed();

        \App\Helpers\Database::getInstance()->setConnection($this->conn);
        $this->service = new DocumentAccessService();

        // The service caches the connection in its constructor, so it must be
        // constructed AFTER setConnection(). If the ordering ever changes, the
        // service silently talks to the application's own database while the
        // test's direct queries go to the scratch one - producing exactly the
        // "row exists but the service cannot see it" symptom. Asserted so that
        // failure is unambiguous rather than mysterious.
        $serviceDb = $this->service->connection()->query('SELECT DATABASE()')->fetch_row()[0];
        $this->assertSame(
            self::SCRATCH_DB,
            (string) $serviceDb,
            'DocumentAccessService is not on the scratch database'
        );
    }

    protected function tearDown(): void
    {
        \App\Helpers\Database::getInstance()->setConnection(null);
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


    private function createSchema(): void
    {
        $m = $this->conn;

        $m->query("CREATE TABLE employee_documents (
            id int(11) NOT NULL AUTO_INCREMENT,
            employee_id int(11) NOT NULL,
            document_name varchar(255) NOT NULL,
            category varchar(50) DEFAULT 'other',
            file_name varchar(255) NOT NULL,
            uploaded_at timestamp NOT NULL DEFAULT current_timestamp(),
            is_encrypted tinyint(1) NOT NULL DEFAULT 0,
            size_bytes bigint(20) unsigned DEFAULT NULL,
            original_mime varchar(127) DEFAULT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB");

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

        $m->query("CREATE TABLE employees (
            id int(11) NOT NULL AUTO_INCREMENT,
            employee_id varchar(50) DEFAULT NULL,
            first_name varchar(100) DEFAULT NULL,
            last_name varchar(100) DEFAULT NULL,
            department_id int(11) DEFAULT NULL,
            section_id int(11) DEFAULT NULL,
            subsection_id int(11) DEFAULT NULL,
            office_id int(11) DEFAULT NULL,
            PRIMARY KEY (id)
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
        $m->query("INSERT INTO permissions (module, action) VALUES
            ('employees','view'), ('profile','view'), ('profile','edit')");
        $m->query("INSERT INTO role_permissions (role, module, action, is_granted) VALUES
            ('hr_manager','employees','view',1),
            ('employee','employees','view',0),
            ('employee','profile','view',1),
            ('employee','profile','edit',1),
            ('hr_manager','profile','view',1),
            ('hr_manager','profile','edit',1)");

        // The real migration, so the schema under test is the shipped one.
        $sql = file_get_contents(dirname(__DIR__, 3) . '/database/migrations/106_document_access_otp.sql');
        $this->assertIsString($sql, 'migration 106 must be readable');

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

        $this->assertNull($err, 'migration 106 must apply cleanly: ' . (string) $err);
    }

    private function seed(): void
    {
        $m = $this->conn;

        $s = $m->prepare('INSERT INTO employees (id, employee_id, first_name, last_name) VALUES (?,?,?,?)');
        $a = 1; $b = 'EMP001'; $c = 'Alice'; $d = 'Owner';
        $s->bind_param('isss', $a, $b, $c, $d);
        $s->execute();
        $s->close();

        $s = $m->prepare('INSERT INTO users (id, employee_id, email, first_name, last_name, role) VALUES (?,?,?,?,?,?)');
        $a = self::EMP_USER; $b = 'EMP001'; $c = 'owner@example.test'; $d = 'Alice'; $e = 'Owner'; $f = 'employee';
        $s->bind_param('isssss', $a, $b, $c, $d, $e, $f);
        $s->execute();
        $s->close();

        $s = $m->prepare('INSERT INTO users (id, employee_id, email, first_name, last_name, role) VALUES (?,?,?,?,?,?)');
        $a = self::REQ_USER; $b = 'EMP002'; $c = 'hr@example.test'; $d = 'Bob'; $e = 'HrBoss'; $f = 'hr_manager';
        $s->bind_param('isssss', $a, $b, $c, $d, $e, $f);
        $s->execute();
        $s->close();

        $s = $m->prepare(
            'INSERT INTO employee_documents (id, employee_id, document_name, category, file_name) VALUES (?,?,?,?,?)'
        );
        $a = 1; $b = self::EMP_ID; $c = 'National ID.pdf'; $d = 'id'; $e = 'doc_1.pdf';
        $s->bind_param('iisss', $a, $b, $c, $d, $e);
        $s->execute();
        $s->close();
    }

    /**
     * Overwrite the stored hash so a KNOWN code can be redeemed. The real code
     * only ever exists in an email, which this suite cannot read.
     */
    private function setKnownCode(string $code): void
    {
        $ok = $this->conn->query(
            "UPDATE document_access_otp SET code_hash = '" . hash('sha256', $code) . "'
             WHERE document_id = 0 AND owner_employee_id = " . self::EMP_ID
            . ' AND requester_user_id = ' . self::REQ_USER
        );

        $this->assertTrue($ok, 'setKnownCode UPDATE failed: ' . $this->conn->error);

        $row = $this->conn->query(
            'SELECT code_hash, verified_at, consumed_at, expires_at, attempts
             FROM document_access_otp
             WHERE document_id = 0 AND owner_employee_id = ' . self::EMP_ID
            . ' AND requester_user_id = ' . self::REQ_USER
        )->fetch_row();

        $this->assertNotNull($row, 'no approval row exists to stamp a known code into');
        $this->assertSame(
            hash('sha256', $code),
            (string) $row[0],
            'the known code was not stored'
        );
    }

    private function attempts(): int
    {
        return (int) $this->conn->query(
            'SELECT attempts FROM document_access_otp
             WHERE document_id = 0 AND owner_employee_id = ' . self::EMP_ID
            . ' AND requester_user_id = ' . self::REQ_USER
            . ' ORDER BY id DESC LIMIT 1'
        )->fetch_row()[0];
    }



    // =================================================================
    // The regression: a wrong code must be COUNTED, not silently ignored
    // =================================================================

    public function testWrongCodeIsRejectedAndCounted(): void
    {
        $req = $this->service->requestEmployeeAccess(self::EMP_ID, self::REQ_USER);
        $this->assertTrue($req['ok'], 'request must succeed: ' . $req['reason']);

        $this->setKnownCode('123456');

        $before = $this->attempts();
        $result = $this->service->verifyEmployeeCode(self::EMP_ID, self::REQ_USER, '999999');

        $this->assertFalse($result['ok']);
        // The specific bug: this used to be 'no_live_approval', which is what
        // proved the row was never found and the counter never advanced.
        $this->assertSame(
            'incorrect_code',
            $result['reason'],
            'a wrong code must report incorrect_code, not no_live_approval'
        );
        $this->assertSame(
            $before + 1,
            $this->attempts(),
            'a wrong code MUST increment attempts, or the brute-force limit never engages'
        );
    }

    public function testCorrectCodeVerifies(): void
    {
        $this->service->requestEmployeeAccess(self::EMP_ID, self::REQ_USER);
        $this->setKnownCode('123456');

        $result = $this->service->verifyEmployeeCode(self::EMP_ID, self::REQ_USER, '123456');

        $this->assertTrue($result['ok'], 'a correct code must verify');
        $this->assertSame('ok', $result['reason']);
        $this->assertTrue(
            $this->service->hasEmployeeApproval(self::EMP_ID, self::REQ_USER),
            'a verified request must unlock the list'
        );
    }

    public function testCodeIsSingleUse(): void
    {
        $this->service->requestEmployeeAccess(self::EMP_ID, self::REQ_USER);
        $this->setKnownCode('123456');

        $this->assertTrue(
            $this->service->verifyEmployeeCode(self::EMP_ID, self::REQ_USER, '123456')['ok']
        );

        // Redeeming the same code again must fail: this is what the
        // `verified_at IS NULL` filter in the lookup exists to guarantee.
        $again = $this->service->verifyEmployeeCode(self::EMP_ID, self::REQ_USER, '123456');
        $this->assertFalse($again['ok'], 'a code must not be redeemable twice');
    }

    public function testBruteForceLimitBitesAtMaxAttempts(): void
    {
        $this->service->requestEmployeeAccess(self::EMP_ID, self::REQ_USER);
        $this->setKnownCode('123456');

        $max = DocumentAccessService::MAX_ATTEMPTS;

        for ($i = 1; $i <= $max; $i++) {
            $r = $this->service->verifyEmployeeCode(self::EMP_ID, self::REQ_USER, '000000');
            $this->assertFalse($r['ok'], "attempt {$i} must fail");
            $this->assertSame('incorrect_code', $r['reason'], "attempt {$i} reason");
        }

        $this->assertSame($max, $this->attempts(), 'attempts must reach the limit');

        // The CORRECT code must now be refused: the row is burned.
        $after = $this->service->verifyEmployeeCode(self::EMP_ID, self::REQ_USER, '123456');
        $this->assertFalse(
            $after['ok'],
            'a burned approval must refuse even the correct code'
        );
        $this->assertSame($max, $this->attempts(), 'attempts must not exceed the limit');
    }

    public function testExpiredApprovalIsRefused(): void
    {
        $this->service->requestEmployeeAccess(self::EMP_ID, self::REQ_USER);
        $this->setKnownCode('123456');

        $this->conn->query(
            'UPDATE document_access_otp SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE)
             WHERE document_id = 0 AND owner_employee_id = ' . self::EMP_ID
        );

        $r = $this->service->verifyEmployeeCode(self::EMP_ID, self::REQ_USER, '123456');
        $this->assertFalse($r['ok'], 'an expired approval must not verify');
        $this->assertFalse(
            $this->service->hasEmployeeApproval(self::EMP_ID, self::REQ_USER),
            'an expired approval must not unlock the list'
        );
    }

    public function testMalformedCodeIsRejectedWithoutTouchingAttempts(): void
    {
        $this->service->requestEmployeeAccess(self::EMP_ID, self::REQ_USER);
        $before = $this->attempts();

        foreach (['12345', '1234567', 'abcdef', ''] as $bad) {
            $r = $this->service->verifyEmployeeCode(self::EMP_ID, self::REQ_USER, $bad);
            $this->assertFalse($r['ok']);
            $this->assertSame('malformed_code', $r['reason']);
        }

        $this->assertSame(
            $before,
            $this->attempts(),
            'a malformed value is not a guess and must not consume an attempt'
        );
    }

    public function testSpacedInputIsNormalised(): void
    {
        $this->service->requestEmployeeAccess(self::EMP_ID, self::REQ_USER);
        $this->setKnownCode('987654');

        // Users routinely type or paste "987 654"; rejecting it would be a
        // support burden for no security gain.
        $r = $this->service->verifyEmployeeCode(self::EMP_ID, self::REQ_USER, '987 654');
        $this->assertTrue($r['ok'], 'a spaced code must be normalised and accepted');
    }

    public function testAnotherRequesterCannotRedeemTheCode(): void
    {
        $this->service->requestEmployeeAccess(self::EMP_ID, self::REQ_USER);
        $this->setKnownCode('123456');

        // A different user must not be able to present this code.
        $r = $this->service->verifyEmployeeCode(self::EMP_ID, 999, '123456');
        $this->assertFalse($r['ok']);
        $this->assertFalse($this->service->hasEmployeeApproval(self::EMP_ID, 999));
    }

    public function testOwnerNeedsNoCodeForTheirOwnList(): void
    {
        // The owner is never gated: hasEmployeeApproval is only consulted for
        // non-owners, and the controller short-circuits for the owner before
        // reaching it. Asserted so that shortcut stays deliberate.
        $this->assertFalse(
            $this->service->hasEmployeeApproval(self::EMP_ID, self::EMP_USER),
            'the owner has no approval row; the controller must bypass the check for them'
        );
    }
}

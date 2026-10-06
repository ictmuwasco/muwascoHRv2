<?php

declare(strict_types=1);

namespace Tests\Integration\Notifications;

use App\Helpers\Database;
use App\Services\Notification\NotificationDispatcher;
use App\Services\NotificationService;
use PHPUnit\Framework\TestCase;

/**
 * The "every in-house notification is also emailed" contract, exercised
 * against a real scratch database.
 *
 * WHY A REAL DATABASE
 *   The contract is enforced by SQL unique keys, preference rows and
 *   payload columns - a mock would only test the mock. Every statement runs
 *   against MariaDB with the production shape (025 + 094 + 095 for
 *   notification_logs, the unique (user_id, dedupe_key) index included).
 *
 * ISOLATION
 *   A database named notification_mirror_test is created and dropped per
 *   test, and Database::setConnection() - the test-only seam - points the
 *   application helper at it, so the application's own data is never at
 *   risk. Rows queued here are invisible to the scheduled worker, so no
 *   email can ever leave the machine because of this test.
 *
 * WHAT IS PROVEN
 *   1. sendInApp() writes the bell AND queues exactly one pending email row
 *      whose dedupe key derives from the bell's id and whose payload carries
 *      title/body/link.
 *   2. An explicit email opt-out (email_enabled = 0) still produces a
 *      'skipped' row with a reason - the mirror never overrides a choice.
 *   3. A user whose preference row has email_enabled = 1 queues 'pending'.
 *   4. dispatch() adds the email channel whenever in_app was requested, even
 *      when the caller passed [in_app] alone - the organisation rule.
 *   5. Re-queueing the same bell's mirror is rejected by the unique dedupe
 *      index: one bell, one email, no matter how often dispatch is retried.
 *   6. The $category argument (DelegateService's 'leave') survives the
 *      round trip while the mirror still queues.
 */
final class InAppEmailMirrorTest extends TestCase
{
    private const SCRATCH_DB = 'notification_mirror_test';

    private const USER_PLAIN     = 1; // no preference row  -> enabled by default
    private const USER_OPTED_OUT = 2; // email_enabled = 0   -> skipped
    private const USER_ENABLED   = 3; // email_enabled = 1   -> pending

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

        // Point the application helper (and everything built from it) at the
        // scratch database BEFORE any service under test is exercised.
        Database::getInstance()->setConnection($this->conn);
    }

    protected function tearDown(): void
    {
        Database::getInstance()->setConnection(null);

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

    /** 1. sendInApp() writes the bell AND queues one pending email mirror. */
    public function testSendInAppWritesBellAndQueuesEmailMirror(): void
    {
        $id = NotificationService::getInstance()->sendInApp(
            self::USER_PLAIN,
            'Vault access requested',
            'Owner must approve the request.',
            'info',
            '/vault'
        );

        $this->assertGreaterThan(0, $id, 'the bell row must have been inserted');

        $bell = db()->fetchOne('SELECT * FROM notifications WHERE id = ?', 'i', [$id]);
        $this->assertIsArray($bell, 'bell row exists');
        $this->assertSame('Vault access requested', $bell['title']);
        $this->assertSame('general', $bell['category']);
        $this->assertSame('/vault', $bell['action_url']);

        $log = db()->fetchOne(
            "SELECT * FROM notification_logs WHERE user_id = ? AND channel = 'email'",
            'i',
            [self::USER_PLAIN]
        );
        $this->assertIsArray($log, 'an email row was queued');
        $this->assertSame('pending', $log['status'], 'the worker will deliver it');
        $this->assertSame('in_app_mirror', $log['notification_type']);
        $this->assertSame('inapp:' . $id . '|email', $log['dedupe_key']);

        $payload = json_decode((string) $log['payload'], true);
        $this->assertIsArray($payload);
        $this->assertSame('Vault access requested', $payload['title']);
        $this->assertSame('Owner must approve the request.', $payload['body']);
        $this->assertSame('/vault', $payload['link']);
    }

    /** 2. An explicit opt-out is honoured: skipped with a reason, never sent. */
    public function testMirrorRespectsExplicitEmailOptOut(): void
    {
        $id = NotificationService::getInstance()->sendInApp(
            self::USER_OPTED_OUT,
            'Complaint Resolved',
            'Your complaint has been resolved.',
            'success'
        );

        $this->assertGreaterThan(0, $id, 'the bell still lands even when email is off');

        $log = db()->fetchOne(
            "SELECT * FROM notification_logs WHERE user_id = ? AND channel = 'email'",
            'i',
            [self::USER_OPTED_OUT]
        );
        $this->assertIsArray($log, 'the attempt is recorded for the audit trail');
        $this->assertSame('skipped', $log['status']);
        $this->assertStringContainsString('switched off', (string) $log['failure_reason']);
    }

    /** 3. A preference row with email_enabled = 1 queues as pending. */
    public function testEnabledPreferenceStillQueuesPendingEmail(): void
    {
        NotificationService::getInstance()->sendInApp(
            self::USER_ENABLED,
            'Payroll Released',
            'Your salary has been processed.',
            'success'
        );

        $log = db()->fetchOne(
            "SELECT * FROM notification_logs WHERE user_id = ? AND channel = 'email'",
            'i',
            [self::USER_ENABLED]
        );
        $this->assertIsArray($log);
        $this->assertSame('pending', $log['status'], 'migration 107 default must let this through');
    }

    /**
     * 4. The organisation rule: dispatch() adds email whenever in_app was
     * requested, even from a caller that passed [in_app] alone.
     */
    public function testDispatcherAddsEmailWheneverInAppIsRequested(): void
    {
        $result = (new NotificationDispatcher())->dispatch(
            self::USER_PLAIN,
            'EMP001@muwasco.test',
            '+254700000001',
            'meeting_invitation',
            [NotificationDispatcher::CHANNEL_IN_APP], // deliberately no email
            'meeting:9:invited:1',
            ['title' => 'Meeting invitation', 'body' => 'You are invited.', 'link' => '/meetings']
        );

        $this->assertArrayHasKey(NotificationDispatcher::CHANNEL_IN_APP, $result['queued']);
        $this->assertArrayHasKey(
            NotificationDispatcher::CHANNEL_EMAIL,
            $result['queued'],
            'in_app must imply email - the channel can never be forgotten'
        );

        // The bell was written synchronously; the email waits for the worker.
        $bells = (int) db()->fetchValue(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ?',
            'i',
            [self::USER_PLAIN]
        );
        $this->assertSame(1, $bells, 'exactly one bell, never one per channel');

        $emailLog = db()->fetchOne(
            "SELECT * FROM notification_logs WHERE user_id = ? AND channel = 'email'",
            'i',
            [self::USER_PLAIN]
        );
        $this->assertIsArray($emailLog);
        $this->assertSame('pending', $emailLog['status']);
    }

    /** 5. One bell, one email: the unique dedupe index rejects a re-queue. */
    public function testSameBellMirrorCannotBeQueuedTwice(): void
    {
        $id = NotificationService::getInstance()->sendInApp(
            self::USER_PLAIN,
            'Leave Approved',
            'Your leave request has been approved.',
            'success'
        );

        // Simulate a retry of the exact same mirror.
        $retry = (new NotificationDispatcher())->dispatch(
            self::USER_PLAIN,
            'EMP001@muwasco.test',
            '+254700000001',
            'in_app_mirror',
            [NotificationDispatcher::CHANNEL_EMAIL],
            'inapp:' . $id,
            ['title' => 'Leave Approved', 'body' => 'Your leave request has been approved.', 'link' => null]
        );

        $this->assertArrayHasKey('email', $retry['skipped']);
        $this->assertSame('Already queued', $retry['skipped']['email']);

        $count = (int) db()->fetchValue(
            "SELECT COUNT(*) FROM notification_logs WHERE user_id = ? AND channel = 'email'",
            'i',
            [self::USER_PLAIN]
        );
        $this->assertSame(1, $count, 'the bell must never be mailed twice');
    }

    /** 6. DelegateService's 'leave' category survives while mirroring. */
    public function testCategoryArgumentIsPreserved(): void
    {
        $id = NotificationService::getInstance()->sendInApp(
            self::USER_PLAIN,
            'Leave Delegation Assignment',
            'You have been assigned as delegate.',
            'delegate_assignment',
            null,
            'leave'
        );

        $bell = db()->fetchOne('SELECT * FROM notifications WHERE id = ?', 'i', [$id]);
        $this->assertSame('leave', $bell['category']);
        $this->assertSame('delegate_assignment', $bell['type']);

        $log = db()->fetchOne(
            "SELECT * FROM notification_logs WHERE user_id = ? AND channel = 'email'",
            'i',
            [self::USER_PLAIN]
        );
        $this->assertIsArray($log, 'the delegate bell is emailed too');
        $this->assertSame('pending', $log['status']);
    }

    /**
     * Scratch schema: the columns every query in the mirror path touches,
     * shaped exactly like production (025 + migration 094 dedupe_key +
     * migration 095 payload, with the (user_id, dedupe_key) unique index
     * that makes one-bell-one-email a database guarantee).
     */
    private function createSchema(): void
    {
        $m = $this->conn;

        $m->query('CREATE TABLE users (
            id int(11) NOT NULL AUTO_INCREMENT,
            email varchar(255) DEFAULT NULL,
            employee_id varchar(50) DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB');

        $m->query('CREATE TABLE employees (
            id int(11) NOT NULL AUTO_INCREMENT,
            employee_id varchar(50) DEFAULT NULL,
            email varchar(255) DEFAULT NULL,
            phone varchar(50) DEFAULT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB');

        $m->query('CREATE TABLE notifications (
            id int(11) NOT NULL AUTO_INCREMENT,
            user_id int(11) NOT NULL,
            title varchar(255) NOT NULL,
            message text NOT NULL,
            type varchar(100) NOT NULL,
            category varchar(100) NOT NULL DEFAULT \'general\',
            action_url varchar(500) DEFAULT NULL,
            is_read tinyint(1) DEFAULT 0,
            is_sent tinyint(1) DEFAULT 1,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id)
        ) ENGINE=InnoDB');

        $m->query('CREATE TABLE notification_preferences (
            id int(11) NOT NULL AUTO_INCREMENT,
            user_id int(11) NOT NULL,
            push_enabled tinyint(1) NOT NULL DEFAULT 1,
            sms_enabled tinyint(1) NOT NULL DEFAULT 1,
            email_enabled tinyint(1) NOT NULL DEFAULT 1,
            reminders_mandated tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id),
            UNIQUE KEY uq_pref_user (user_id)
        ) ENGINE=InnoDB');

        // 025 + 094 (dedupe_key, unique (user_id, dedupe_key)) + 095 (payload).
        $m->query('CREATE TABLE notification_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            employee_id INT UNSIGNED DEFAULT NULL,
            notification_type VARCHAR(60) NOT NULL DEFAULT \'attendance_clock_in_reminder\',
            channel VARCHAR(20) NOT NULL,
            stage VARCHAR(30) NOT NULL DEFAULT \'reminder_1\',
            dedupe_key VARCHAR(191) NULL DEFAULT NULL,
            payload TEXT NULL DEFAULT NULL,
            business_date DATE NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT \'pending\',
            recipient VARCHAR(200) DEFAULT NULL,
            provider_message_id VARCHAR(100) DEFAULT NULL,
            failure_reason VARCHAR(500) DEFAULT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            scheduled_at DATETIME DEFAULT NULL,
            sent_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_notification_once (user_id, dedupe_key),
            CONSTRAINT fk_nl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    private function seedPeople(): void
    {
        $m = $this->conn;

        foreach ([[1, 'EMP001'], [2, 'EMP002'], [3, 'EMP003']] as [$id, $empNo]) {
            $m->query("INSERT INTO employees (id, employee_id, email, phone)
                VALUES ({$id}, '{$empNo}', '{$empNo}@muwasco.test', '+25470000000{$id}')");
            $m->query("INSERT INTO users (id, email, employee_id, is_active)
                VALUES ({$id}, '{$empNo}@muwasco.test', '{$empNo}', 1)");
        }

        // Opted OUT of email - an explicit, honoured 0.
        $m->query('INSERT INTO notification_preferences (user_id, email_enabled) VALUES (2, 0)');
        // Explicitly ON - the shape migration 107 leaves behind.
        $m->query('INSERT INTO notification_preferences (user_id, email_enabled) VALUES (3, 1)');
    }
}

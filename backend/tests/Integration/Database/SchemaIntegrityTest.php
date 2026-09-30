<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Helpers\Database;
use PHPUnit\Framework\TestCase;

/**
 * Data-integrity assertions against the real schema.
 *
 * WHY THIS EXISTS
 *
 * Until now backend/tests/ did not exist. CI found zero *Test.php files,
 * printed "PHPUnit skipped - no tests to run" and the job passed green. Every
 * "tests pass" claim in this project's history was therefore vacuous. These
 * tests make the schema claims in docs/DATABASE_ARCHITECTURE.md and
 * docs/DB_HARDENING_REPORT.md machine-checked instead of asserted in prose.
 *
 * DESIGN RULE: every assertion encodes a fact MEASURED during the audit, or a
 * constraint the business actually requires. Nothing is asserted because it
 * is a common best practice. Where a deliberate exception exists the test says
 * so explicitly rather than silently passing.
 */
final class SchemaIntegrityTest extends TestCase
{
    private static ?Database $db = null;
    private static string $schema = 'muwasco';

    public static function setUpBeforeClass(): void
    {
        self::$db = Database::getInstance();
        self::$schema = (string) (config('database.connections.mysql.database') ?: 'muwasco');
    }

    private function conn(): \mysqli
    {
        return self::$db->getConnection();
    }

    /** @return array<int, array<string, mixed>> */
    private function query(string $sql): array
    {
        $result = $this->conn()->query($sql);
        if ($result === false) {
            $this->fail('Query failed: ' . $sql . ' -> ' . $this->conn()->error);
        }
        return $result->fetch_all(\MYSQLI_ASSOC) ?: [];
    }

    private function scalar(string $sql): mixed
    {
        $rows = $this->query($sql);
        return $rows === [] ? null : (array_values($rows[0])[0] ?? null);
    }

    /**
     * The schema uses MariaDB-only DDL (ALTER TABLE ... ADD COLUMN IF NOT
     * EXISTS), so running against MySQL 8 would test a different schema than
     * production runs. This pins the engine so a silent swap fails loudly.
     */
    public function testEngineIsMariaDb(): void
    {
        $version = (string) $this->scalar('SELECT VERSION()');
        $this->assertStringContainsStringIgnoringCase(
            'mariadb',
            $version,
            'Expected MariaDB. Found ' . $version . '. Several migrations use '
            . 'MariaDB-only syntax, so a MySQL 8 CI service validates a '
            . 'different schema than production.'
        );
    }

    public function testEveryTableUsesInnoDb(): void
    {
        $rows = $this->query(
            "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = '" . self::$schema . "' AND TABLE_TYPE = 'BASE TABLE'
               AND ENGINE <> 'InnoDB'"
        );
        $this->assertSame([], $rows, 'Non-InnoDB tables: ' . json_encode($rows));
    }

    /**
     * Migrations 099/102 removed these after a full dependency sweep. If any
     * reappears, something is recreating it and the audit result is no longer
     * trustworthy.
     */
    public function testConfirmedUnusedTablesRemainDropped(): void
    {
        $removed = [
            'security_logs', 'salary_bands', 'jdac_questionnaires', 'jdac_questions',
            'jdac_responses', 'strategies', 'absent_deductions', 'absent_exemptions',
            'employee_offices', 'notification_templates', 'workplan_objective_cycles',
            'appraisal_summary_cache', 'employee_otps', 'device_attempt_log',
            'employee_devices', 'password_reset_tokens', 'appraisal_score_archive',
            'employee_leave_brought_forward', 'leave_transactions_backup',
            'user_page_permissions_backup_015', 'user_notification_preferences',
            'payroll_periods', 'payroll_records',
        ];

        $in = "'" . implode("','", $removed) . "'";
        $rows = $this->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = '" . self::$schema . "' AND TABLE_NAME IN ($in)"
        );

        $this->assertSame(
            [],
            $rows,
            'Tables removed by migration 099/102 are present again: ' . json_encode($rows)
        );
    }

    /**
     * The join key here is employees.employee_id (a varchar staff code such as
     * 'MOW12'), NOT employees.id. Joining on employees.id reports 193 phantom
     * "orphans" that do not exist - a false positive already hit once during
     * this audit, and one that would otherwise trigger a destructive cleanup
     * of perfectly valid data.
     */
    public function testUsersResolveToAnEmployeeRecord(): void
    {
        $orphans = (int) $this->scalar(
            "SELECT COUNT(*) FROM users u
             LEFT JOIN employees e ON e.employee_id = u.employee_id
             WHERE u.employee_id IS NOT NULL AND e.id IS NULL"
        );
        $this->assertSame(0, $orphans, 'users.employee_id has no matching employees.employee_id');
    }

    /**
     * Pre-existing orphaned rows, measured 2026-09-29. These are NOT deleted
     * automatically: orphaned HR data must be reported, not silently removed,
     * and leave balances in particular affect entitlement history.
     *
     * This is a RATCHET, not a rubber stamp. The test passes while the count
     * stays at or below the baseline, so any NEW orphan fails immediately. The
     * baseline should only be lowered once remediation actually happens.
     *
     * * employee_leave_balances - 30 rows for employees 441/500/503/505, all
     *   in financial year 30. Those employee ids no longer exist.
     * * notifications - 32 rows for user_id 0 and 2, which no longer exist.
     *   These are inert: every read path filters `WHERE user_id = ?` with the
     *   authenticated user, so no user ever sees them.
     */
    private const KNOWN_ORPHANS = [
        'employee_leave_balances.employee_id' => 30,
        'notifications.user_id'               => 32,
    ];

    /** @dataProvider childParentProvider */
    public function testNoOrphanedRows(string $child, string $childCol, string $parent): void
    {
        $orphans = (int) $this->scalar(
            "SELECT COUNT(*) FROM `{$child}` c
             LEFT JOIN `{$parent}` p ON p.id = c.`{$childCol}`
             WHERE c.`{$childCol}` IS NOT NULL AND p.id IS NULL"
        );

        $key = "{$child}.{$childCol}";
        $baseline = self::KNOWN_ORPHANS[$key] ?? 0;
        $this->assertLessThanOrEqual(
            $baseline,
            $orphans,
            "{$key} now has {$orphans} orphaned rows (known baseline: {$baseline}). "
            . 'New orphans mean a parent row was deleted without cleaning up its '
            . 'children - see docs/DB_HARDENING_REPORT.md for the remediation plan.'
        );
    }

    /** @return array<string, array{0:string,1:string,2:string}> */
    public function childParentProvider(): array
    {
        return [
            'attendance -> employees'          => ['attendance', 'employee_id', 'employees'],
            'leave_applications -> employees'  => ['leave_applications', 'employee_id', 'employees'],
            'ai_messages -> conversations'     => ['ai_messages', 'conversation_id', 'ai_conversations'],
            'notifications -> users'           => ['notifications', 'user_id', 'users'],
            'appraisal_scores -> appraisals'   => ['appraisal_scores', 'employee_appraisal_id', 'employee_appraisals'],
            'leave_balances -> employees'      => ['employee_leave_balances', 'employee_id', 'employees'],
            'leave_balances -> financial year' => ['employee_leave_balances', 'financial_year_id', 'financial_years'],
        ];
    }

    /**
     * Financial amounts must never use floating point. Every monetary column
     * found during the audit is already DECIMAL; this locks that in.
     */
    public function testNoFloatingPointIsUsedForMoney(): void
    {
        $rows = $this->query(
            "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = '" . self::$schema . "'
               AND DATA_TYPE IN ('float','double')
               AND COLUMN_NAME NOT IN ('accuracy','latitude','longitude','lat','lng')"
        );
        $this->assertSame([], $rows, 'Floating-point money columns: ' . json_encode($rows));
    }

    /**
     * attendance.accuracy is the single float in the schema and is CORRECT:
     * it is a GPS accuracy radius in metres, not money. Asserted separately so
     * nobody "fixes" it later.
     */
    public function testGpsAccuracyRemainsFloat(): void
    {
        $type = $this->scalar(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = '" . self::$schema . "'
               AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'accuracy'"
        );
        $this->assertSame('float', $type);
    }

    public function testSalaryIsDecimal(): void
    {
        $type = $this->scalar(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = '" . self::$schema . "'
               AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'salary'"
        );
        $this->assertNotNull($type, 'employees.salary not found');
        $this->assertStringStartsWith('decimal', (string) $type, 'Salary must be DECIMAL, got ' . $type);
    }

    /**
     * Each index below was added because an EXPLAIN showed a full table scan
     * on a real query path. Losing one silently reintroduces that scan.
     *
     * @dataProvider requiredIndexProvider
     */
    public function testRequiredIndexExists(string $table, string $index): void
    {
        $found = (int) $this->scalar(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = '" . self::$schema . "'
               AND TABLE_NAME = '{$table}' AND INDEX_NAME = '{$index}'"
        );
        $this->assertGreaterThan(0, $found, "Missing index {$table}.{$index}");
    }

    /** @return array<string, array{0:string,1:string}> */
    public function requiredIndexProvider(): array
    {
        return [
            'users email (login path, was unindexed)'        => ['users', 'idx_users_email'],
            'users active+role (delegation/FY scope)'        => ['users', 'idx_users_active_role'],
            'employees dept+status (appraisal workflow)'     => ['employees', 'idx_employees_status_dept'],
            'employees national_id (create duplicate guard)' => ['employees', 'idx_employees_national_id'],
            'leave type (report breakdown)'                  => ['leave_applications', 'idx_leave_type_id'],
            'audit user+created (audit trail, no filesort)'  => ['audit_logs', 'idx_audit_user_created'],
            'leave balance employee+fy+type'                 => ['employee_leave_balances', 'idx_elb_emp_fy_type'],
            'attendance employee+date uniqueness'            => ['attendance', 'uk_attendance_employee_date'],
        ];
    }

    /**
     * A file can be recorded 'completed' while only part of it applied, which
     * is exactly how migration 041 lost two of its tables. run.php now walks
     * the multi_query result chain; this asserts no such record exists.
     */
    public function testNoMigrationIsRecordedFailed(): void
    {
        $rows = $this->query("SELECT migration, status FROM migrations WHERE status <> 'completed'");
        $this->assertSame([], $rows, 'Non-completed migrations: ' . json_encode($rows));
    }

    public function testEveryMigrationFileOnDiskIsRecorded(): void
    {
        $files = array_values(array_filter(
            scandir(BACKEND_PATH . '/database/migrations'),
            static fn ($f) => substr($f, -4) === '.sql'
        ));
        sort($files);

        $recorded = array_column(
            $this->query("SELECT migration FROM migrations WHERE status = 'completed'"),
            'migration'
        );

        $untracked = array_values(array_diff($files, $recorded));
        $this->assertSame(
            [],
            $untracked,
            'Migration files never recorded as completed (ledger no longer matches schema): '
            . implode(', ', $untracked)
        );
    }

    /**
     * Guards the exact condition that made a plain mysqldump unrestorable.
     *
     * attendance.attendance_date is a STORED generated column and 257 rows
     * carry clock_in = '0000-00-00'. On this server sql_mode makes cast()
     * yield NULL, so the unique key is satisfied. A restore that drops
     * NO_ZERO_DATE (which mysqldump's own prologue does) recomputes the literal
     * date 0000-00-00 and three rows for employee 383 collide.
     *
     * If this fails, the dump produced by backup.sh will not restore.
     */
    public function testZeroDateAttendanceRowsAreStillRepresentableAsNull(): void
    {
        $mode = (string) $this->scalar('SELECT @@SESSION.sql_mode');
        $this->assertStringContainsString(
            'NO_ZERO_DATE',
            $mode,
            'Session sql_mode lost NO_ZERO_DATE; a dump of this schema is no longer restorable.'
        );

        $rows = (int) $this->scalar(
            "SELECT COUNT(*) FROM attendance
             WHERE clock_in = '0000-00-00 00:00:00' AND attendance_date IS NOT NULL"
        );
        $this->assertSame(
            0,
            $rows,
            'Zero-date clock_in rows now materialise a real 0000-00-00 attendance_date. '
            . 'This is the state that makes mysqldump output unrestorable.'
        );
    }

    public function testNoPlaintextPasswordsAreStored(): void
    {
        // Built with single-quoted fragments on purpose: '$argon2id$' in a
        // double-quoted PHP string is a variable interpolation, not a literal
        // dollar sign, and the pattern silently becomes meaningless.
        $plain = (int) $this->scalar(
            "SELECT COUNT(*) FROM users
             WHERE password IS NOT NULL
               AND password NOT LIKE '" . '\$argon2id\$%' . "'
               AND password NOT LIKE '" . '\$2y\$%' . "'
               AND password NOT LIKE '" . '\$2a\$%' . "'
               AND password NOT LIKE '" . '\$argon2i\$%' . "'
               AND password NOT LIKE '" . '\$argon2b\$%' . "'"
        );
        $this->assertSame(0, $plain, 'Found passwords that are not a recognised hash');
    }

    /**
     * Delegation scope is authorization-critical, so it must be queryable
     * rather than opaque. It is stored as a JSON array; verified here to be
     * well-formed rather than silently null.
     */
    public function testDelegationPermissionsAreWellFormedJson(): void
    {
        foreach ($this->query('SELECT id, permissions FROM delegations') as $row) {
            $this->assertIsArray(
                json_decode((string) $row['permissions'], true),
                "delegations.id={$row['id']} has malformed permissions JSON: " . $row['permissions']
            );
        }
        $this->assertTrue(true);
    }
}

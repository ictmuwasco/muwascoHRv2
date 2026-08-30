<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ErrorTracking;

use PHPUnit\Framework\TestCase;
use App\Services\ErrorTracking\SeverityClassifier;

/**
 * Severity rules (§7) + expected-vs-unexpected categorization (§34).
 */
class SeverityClassifierTest extends TestCase
{
    public function test_database_infrastructure_is_critical(): void
    {
        $c = SeverityClassifier::classify('PDOException', 500, 'Employees');
        $this->assertSame('CRITICAL', $c['severity']);
        $this->assertSame('DATABASE_ERROR', $c['category']);

        $c2 = SeverityClassifier::classify('mysqli_sql_exception', 500, 'Reports');
        $this->assertSame('CRITICAL', $c2['severity']);
    }

    public function test_authentication_failures_are_low_when_client_caused(): void
    {
        $c = SeverityClassifier::classify('JwtException', 401, 'Authentication');
        $this->assertSame('LOW', $c['severity']);
        $this->assertSame('AUTHENTICATION', $c['category']);
    }

    public function test_business_critical_module_system_error_bumps_to_critical(): void
    {
        // Attendance clock-in failure = HIGH business impact (§7 example).
        $c = SeverityClassifier::classify('RuntimeException', 500, 'Attendance');
        $this->assertSame('CRITICAL', $c['severity']);
        $this->assertSame('SYSTEM_ERROR', $c['category']);
    }

    public function test_generic_module_system_error_is_high(): void
    {
        $c = SeverityClassifier::classify('LogicException', 500, 'Meetings');
        $this->assertSame('HIGH', $c['severity']);
    }

    public function test_expected_validation_error_is_low_and_categorized(): void
    {
        $c = SeverityClassifier::classify(null, 422, 'Leave');
        $this->assertSame('LOW', $c['severity']);
        $this->assertSame('VALIDATION', $c['category']);

        $nf = SeverityClassifier::classify(null, 404, 'Employees');
        $this->assertSame('NOT_FOUND', $nf['category']);
    }

    public function test_message_revealed_database_loss_escalates_to_critical(): void
    {
        $base = ['severity' => 'MEDIUM', 'category' => 'SYSTEM_ERROR'];
        $up = SeverityClassifier::classifyMessage('MySQL server has gone away while fetching roster', $base);

        $this->assertSame('CRITICAL', $up['severity']);
        $this->assertSame('DATABASE_ERROR', $up['category']);
    }

    public function test_severity_ordering_helpers(): void
    {
        $this->assertTrue(SeverityClassifier::isAtLeast('CRITICAL', 'HIGH'));
        $this->assertTrue(SeverityClassifier::isAtLeast('HIGH', 'HIGH'));
        $this->assertFalse(SeverityClassifier::isAtLeast('LOW', 'MEDIUM'));
        $this->assertTrue(SeverityClassifier::isValidSeverity('critical'));
        $this->assertFalse(SeverityClassifier::isValidSeverity('WAT'));
    }
}

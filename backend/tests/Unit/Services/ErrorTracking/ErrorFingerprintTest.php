<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ErrorTracking;

use PHPUnit\Framework\TestCase;
use App\Services\ErrorTracking\ErrorFingerprint;

/**
 * Grouping correctness: identical problems collapse into ONE group,
 * different throw-sites stay separated.
 */
class ErrorFingerprintTest extends TestCase
{
    public function test_identical_errors_produce_identical_hashes(): void
    {
        $a = ErrorFingerprint::make('Attendance', 'mysqli_sql_exception', 'Connection timed out', '/var/app/Svc.php');
        $b = ErrorFingerprint::make('attendance', 'mysqli_sql_exception', 'Connection timed out', '/var/app/svc.php');

        $this->assertSame($a['hash'], $b['hash']);
        $this->assertSame($a['fingerprint'], $b['fingerprint']);
    }

    public function test_dynamic_message_parts_are_normalized_away(): void
    {
        $a = ErrorFingerprint::make('Leave', 'RuntimeException', 'Employee #4821 not found', null);
        $b = ErrorFingerprint::make('Leave', 'RuntimeException', 'Employee #9912 not found', null);

        // Same throw-site shape, only ids differ -> same group.
        $this->assertSame($a['fingerprint'], $b['fingerprint']);
        $this->assertSame($a['hash'], $b['hash']);
    }

    public function test_uuids_are_stripped_from_messages(): void
    {
        $a = ErrorFingerprint::make('Meetings', 'LogicException', 'Meeting 550e8400-e29b-41d4-a716-446655440000 missing', null);
        $b = ErrorFingerprint::make('Meetings', 'LogicException', 'Meeting 11111111-2222-3333-4444-555555555555 missing', null);

        $this->assertSame($a['fingerprint'], $b['fingerprint']);
    }

    public function test_different_files_produce_different_groups(): void
    {
        $a = ErrorFingerprint::make('System', 'RuntimeException', 'boom', '/app/A.php');
        $b = ErrorFingerprint::make('System', 'RuntimeException', 'boom', '/app/B.php');

        $this->assertNotSame($a['hash'], $b['hash'], 'distinct throw-sites need distinct hashes');
    }

    public function test_readable_fingerprint_shape(): void
    {
        $parts = ErrorFingerprint::make(
            'Attendance Clock In',
            'PDOException',
            'Too many connections'
        );

        $this->assertMatchesRegularExpression('/^[a-z0-9_.]+$/', $parts['fingerprint']);
        $this->assertStringContainsString('pdo', $parts['fingerprint']); // PDOException -> readable 'pdo' bucket
        $this->assertSame(64, strlen($parts['hash']));
    }
}

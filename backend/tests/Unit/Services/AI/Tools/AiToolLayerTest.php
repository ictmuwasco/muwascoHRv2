<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI\Tools;

use App\Services\AI\Tools\AiToolContext;
use App\Services\AI\Tools\AiToolExecutor;
use App\Services\AI\Tools\AiToolInterface;
use App\Services\AI\Tools\AiToolRegistry;
use App\Services\AI\Tools\GetMyEmployeeProfileTool;
use Tests\TestCase;

/**
 * AiToolLayer — registry filtering, executor guards and sensitive-field
 * filtering for the Phase 5 controlled HR tools. No network, no provider.
 *
 * Place: backend/tests/Unit/Services/AI/Tools/AiToolLayerTest.php
 */
class AiToolLayerTest extends TestCase
{
    /** Context for a user with no employee record and (in CLI) no session. */
    private function context(): AiToolContext
    {
        return AiToolContext::forUser(999999);
    }

    public function testUnregisteredToolNameIsRejectedAsInvalid(): void
    {
        $result = AiToolExecutor::getInstance()->run($this->context(), 'notARegisteredTool', '{}');
        $this->assertSame('invalid', $result['status']);
        $this->assertArrayHasKey('error', $result['payload']);
        $this->assertArrayHasKey('arguments_json', $result);
        $this->assertArrayHasKey('latency_ms', $result);
    }

    public function testMalformedOrInjectedToolNameIsRejected(): void
    {
        $result = AiToolExecutor::getInstance()->run($this->context(), "bad name'; DROP TABLE x", '{}');
        $this->assertSame('invalid', $result['status']);

        $result2 = AiToolExecutor::getInstance()->run($this->context(), '', '{}');
        $this->assertSame('invalid', $result2['status']);
    }

    public function testPermissionGatedToolIsDeniedWithoutPermission(): void
    {
        // The CLI test process has no session, so Auth::hasPermission() is
        // false — the executor must return a STRUCTURED denial, never throw,
        // and never leak anything beyond the fixed user-safe message.
        $result = AiToolExecutor::getInstance()->run($this->context(), 'getMyLeaveBalance', '{}');
        $this->assertSame('denied', $result['status']);
        $this->assertSame('You are not authorised to view this data.', $result['payload']['error']);
    }

    public function testRegistryOffersNothingToUserWithoutEmployeeOrPermissions(): void
    {
        $definitions = AiToolRegistry::getInstance()->definitionsForContext($this->context());
        $this->assertSame([], $definitions);
    }

    public function testPermissionFreeToolStillRequiresAnEmployeeRecord(): void
    {
        AiToolRegistry::getInstance()->register(new class implements AiToolInterface {
            public function name(): string { return 'unitAlwaysAllowed'; }
            public function description(): string { return 'unit test tool'; }
            public function parameters(): array { return ['type' => 'object', 'properties' => [], 'required' => []]; }
            public function requiredPermission(): string { return ''; }
            public function execute(AiToolContext $ctx, array $args): array { return ['ok' => true]; }
            public function summarize(array $payload): string { return 'ok'; }
        });

        $result = AiToolExecutor::getInstance()->run($this->context(), 'unitAlwaysAllowed', '{}');
        $this->assertSame('denied', $result['status']);
        $this->assertStringContainsString('No employee record', $result['payload']['error']);
    }

    public function testProfileToolFiltersSensitiveFields(): void
    {
        $ctx = $this->context();
        $prop = new \ReflectionProperty(AiToolContext::class, 'employee');
        $prop->setAccessible(true);
        $prop->setValue($ctx, [
            'id' => 5, 'first_name' => 'Jane', 'last_name' => 'Doe',
            'employee_id' => 'EMP001', 'designation' => 'Officer',
            'department_name' => 'ICT', 'section_name' => 'Software',
            'employee_type' => 'officer', 'employment_type' => 'permanent',
            'employee_status' => 'active', 'hire_date' => '2020-01-01',
            // Sensitive fields that must NEVER appear in the payload:
            'national_id' => '12345678', 'phone' => '0700000000',
            'email' => 'jane.doe@example.com', 'date_of_birth' => '1990-01-01',
            'address' => '1 Secret Lane', 'next_of_kin' => 'someone', 'scale_id' => '5',
        ]);

        $payload = (new GetMyEmployeeProfileTool())->execute($ctx, []);
        $encoded = (string) json_encode($payload);

        $this->assertSame('Jane Doe', $payload['name']);
        foreach (['national_id', 'phone', 'email', 'date_of_birth', 'address', 'next_of_kin', 'scale_id'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $payload);
        }
        foreach (['12345678', '0700000000', 'jane.doe@example.com', '1990-01-01', '1 Secret Lane'] as $leak) {
            $this->assertStringNotContainsString($leak, $encoded);
        }
    }

    public function testAttendanceToolValidatesMonthAndExcludesSensitiveFields(): void
    {
        $ctx = $this->context();
        $prop = new \ReflectionProperty(AiToolContext::class, 'employee');
        $prop->setAccessible(true);
        $prop->setValue($ctx, ['id' => 777001, 'first_name' => 'Ann', 'last_name' => 'Lee']);

        $tool = new \App\Services\AI\Tools\GetMyAttendanceTool();

        // An injected month must be rejected; previous month is the fallback.
        $payload = $tool->execute($ctx, ['month' => "2026-13'; DROP TABLE attendance; --"]);
        $expected = date('Y-m', strtotime('first day of previous month'));
        $this->assertSame($expected, $payload['month']);
        $this->assertArrayHasKey('days', $payload);
        $this->assertArrayHasKey('summary', $payload);

        // Location/device/IP fields must never be part of the tool payload.
        $encoded = (string) json_encode($payload);
        foreach (['lat', 'lng', 'accuracy', 'ip_address', 'device_fingerprint'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $payload);
            $this->assertStringNotContainsString('"' . $forbidden . '"', $encoded);
        }
    }
}

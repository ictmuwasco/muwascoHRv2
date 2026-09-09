<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Workplan;

use App\Services\Workplan\WorkplanService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure (database-free) logic of WorkplanService —
 * the authoritative home of the workplan cascade state machine.
 *
 * These cover the cascade-level derivation rules that decide where a
 * workplan activity sits in the strategic cascade, and small display/
 * decoding helpers used by the controller-facing API.
 *
 * @covers \App\Services\Workplan\WorkplanService
 */
final class WorkplanServiceTest extends TestCase
{
    private WorkplanService $service;

    protected function setUp(): void
    {
        // Pure-logic tests only: the service is constructed with an
        // unconnected mysqli handle (mysqli_init) and never issues a query.
        $this->service = new WorkplanService(mysqli_init());
    }

    public function testSubsectionAssignmentDominatesTheCascadeLevel(): void
    {
        // Even with a section and a contract present, a subsection
        // assignment is the most specific level.
        $this->assertSame('subsection', $this->service->deriveLevel(10, 5, 7));
    }

    public function testSectionAssignmentWithoutSubsectionIsSectionLevel(): void
    {
        $this->assertSame('section', $this->service->deriveLevel(10, null, 7));
    }

    public function testDepartmentContractWithoutUnitIsDepartmentLevel(): void
    {
        $this->assertSame('department', $this->service->deriveLevel(null, null, 7));
    }

    public function testNoUnitAndNoContractIsOrganisationLevel(): void
    {
        $this->assertSame('organisation', $this->service->deriveLevel(null, null, 0));
    }

    public function testActorNameJoinsAvailableParts(): void
    {
        $this->assertSame('Jane Kamau', $this->service->actorName([
            'first_name' => 'Jane',
            'last_name' => 'Kamau',
            'surname' => '',
        ]));
    }

    public function testActorNameFallsBackToSystemWhenRowIsEmpty(): void
    {
        $this->assertSame('System', $this->service->actorName([
            'first_name' => '  ',
            'last_name' => null,
        ]));
    }

    public function testDecodeJsonFieldDecodesValidJson(): void
    {
        $this->assertSame(['a' => 1], $this->service->decodeJsonField('{"a":1}'));
        $this->assertSame([1, 2], $this->service->decodeJsonField('[1,2]'));
    }

    public function testDecodeJsonFieldPassesThroughNonJsonAndEmptyValues(): void
    {
        $this->assertNull($this->service->decodeJsonField(null));
        $this->assertNull($this->service->decodeJsonField(''));
        $this->assertSame('plain text', $this->service->decodeJsonField('plain text'));
    }
}

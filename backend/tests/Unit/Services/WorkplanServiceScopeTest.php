<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Workplan\WorkplanService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class WorkplanServiceScopeTest extends TestCase
{
    public function testDepartmentHeadSeesDepartmentAndSectionLevels(): void
    {
        [$where, $params] = $this->service()->viewScope($this->scope([
            'is_dept_head' => true,
            'department_id' => 7,
        ]), 'department');

        self::assertSame(
            "c.department_id = ? AND COALESCE(NULLIF(w.level, ''), CASE WHEN w.subsection_id IS NOT NULL THEN 'subsection' WHEN w.section_id IS NOT NULL THEN 'section' ELSE 'department' END) IN ('department', 'section')",
            $where
        );
        self::assertSame([7], $params);
    }

    public function testHrManagerUsesDepartmentHeadScope(): void
    {
        [$where, $params] = $this->service()->viewScope($this->scope([
            'role' => 'hr_manager',
            'is_hr' => true,
            'department_id' => 7,
        ]), 'department');

        self::assertStringContainsString("IN ('department', 'section')", $where);
        self::assertStringNotContainsString('created_by', $where);
        self::assertSame([7], $params);
    }

    public function testSectionHeadSeesOnlyItsDepartmentAndSectionThroughSubsectionLevels(): void
    {
        [$where, $params] = $this->service()->viewScope($this->scope([
            'is_section_head' => true,
            'department_id' => 7,
            'section_id' => 12,
        ]), 'section');

        self::assertSame(
            "w.section_id = ? AND c.department_id = ? AND COALESCE(NULLIF(w.level, ''), CASE WHEN w.subsection_id IS NOT NULL THEN 'subsection' WHEN w.section_id IS NOT NULL THEN 'section' ELSE 'department' END) IN ('section', 'subsection')",
            $where
        );
        self::assertSame([12, 7], $params);
    }

    public function testSubsectionHeadSeesOnlyItsSubsectionAtSubsectionLevel(): void
    {
        [$where, $params] = $this->service()->viewScope($this->scope([
            'is_sub_section_head' => true,
            'department_id' => 7,
            'subsection_id' => 1,
        ]), 'subsection');

        self::assertSame(
            "w.subsection_id = ? AND c.department_id = ? AND COALESCE(NULLIF(w.level, ''), CASE WHEN w.subsection_id IS NOT NULL THEN 'subsection' WHEN w.section_id IS NOT NULL THEN 'section' ELSE 'department' END) = 'subsection'",
            $where
        );
        self::assertSame([1, 7], $params);
    }

    public function testSectionHeadSubsectionViewKeepsDescendantSubsectionConstraint(): void
    {
        [$where, $params] = $this->service()->viewScope($this->scope([
            'is_section_head' => true,
            'department_id' => 7,
            'section_id' => 12,
        ]), 'subsection');

        self::assertSame(
            "w.subsection_id IN (SELECT id FROM subsections WHERE section_id = ?) AND c.department_id = ? AND COALESCE(NULLIF(w.level, ''), CASE WHEN w.subsection_id IS NOT NULL THEN 'subsection' WHEN w.section_id IS NOT NULL THEN 'section' ELSE 'department' END) = 'subsection'",
            $where
        );
        self::assertSame([12, 7], $params);
    }

    /**
     * The view-scope branches do not use the database connection, so these
     * tests can exercise their SQL and bind parameters without a DB server.
     */
    private function service(): WorkplanService
    {
        return (new ReflectionClass(WorkplanService::class))->newInstanceWithoutConstructor();
    }

    private function scope(array $overrides = []): array
    {
        return array_replace([
            'role' => 'employee',
            'is_super_admin' => false,
            'is_hr' => false,
            'is_dept_head' => false,
            'is_section_head' => false,
            'is_sub_section_head' => false,
            'department_id' => null,
            'section_id' => null,
            'subsection_id' => null,
        ], $overrides);
    }
}

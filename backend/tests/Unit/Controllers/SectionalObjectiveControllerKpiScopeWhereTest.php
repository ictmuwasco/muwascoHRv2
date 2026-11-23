<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\HR\SectionalObjectiveController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class SectionalObjectiveControllerKpiScopeWhereTest extends TestCase
{
    private function kpiScopeWhere(array $scope): array
    {
        $controller = (new ReflectionClass(SectionalObjectiveController::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SectionalObjectiveController::class, 'kpiScopeWhere');
        $method->setAccessible(true);

        return $method->invoke($controller, $scope);
    }

    public function testDeptHeadSeesKPIsWithPlainStaffAssigneeInItsSection(): void
    {
        $scope = [
            'role' => 'section_head',
            'department_id' => 2,
            'section_id' => 12,
            'is_section_head' => true,
            'is_sub_section_head' => false,
            'is_dept_head' => false,
        ];

        [$where, $params] = $this->kpiScopeWhere($scope);

        // A section head sees KPIs that have a plain staff assignee in the same
        // section (no subsection). The section_id filter and the FIND_IN_SET
        // EXISTS check are present, plus the employee-type exclusions.
        self::assertStringContainsString('pi.department_id = ?', $where);
        self::assertStringContainsString('FIND_IN_SET(ae.id, pi.assigned_to_employee_ids) > 0', $where);
        self::assertStringContainsString('ae.section_id = ?', $where);
        self::assertStringContainsString('COALESCE(ae.subsection_id, 0) = 0', $where);
        self::assertStringContainsString("'sub_section_head', 'subsection_head'", $where);
        self::assertSame([2, 12], $params);
    }

    public function testSectionHeadSeesNoKPIsWhenOnlySubsectionHeadIsAssigned(): void
    {
        $scope = [
            'role' => 'section_head',
            'department_id' => 2,
            'section_id' => 12,
            'is_section_head' => true,
            'is_sub_section_head' => false,
            'is_dept_head' => false,
        ];

        [$where, $params] = $this->kpiScopeWhere($scope);

        // The EXISTS clause requires a plain staff assignee (COALESCE(subsection_id,0)=0).
        // A KPI assigned only to a subsection head (subsection_id NOT NULL) should not match.
        self::assertStringContainsString('COALESCE(ae.subsection_id, 0) = 0', $where);
        // No subsection_id column reference in the static WHERE clause
        self::assertStringNotContainsString('pi.subsection_id = ?', $where);
    }

    public function testSubSectionHeadSeesKPIsWithAssigneesInItsSubsection(): void
    {
        $scope = [
            'role' => 'sub_section_head',
            'department_id' => 2,
            'section_id' => 12,
            'subsection_id' => 4,
            'is_section_head' => false,
            'is_sub_section_head' => true,
            'is_dept_head' => false,
        ];

        [$where, $params] = $this->kpiScopeWhere($scope);

        // A subsection head sees KPIs that have an assignee in the same subsection.
        self::assertStringContainsString('FIND_IN_SET(ae.id, pi.assigned_to_employee_ids) > 0', $where);
        self::assertStringContainsString('ae.subsection_id = ?', $where);
        self::assertSame([2, 4], $params);
    }

    public function testSubSectionHeadSeesNoKPIsWhenFirstAssigneeWasSectionStaff(): void
    {
        $scope = [
            'role' => 'sub_section_head',
            'department_id' => 2,
            'section_id' => 12,
            'subsection_id' => 4,
            'is_section_head' => false,
            'is_sub_section_head' => true,
            'is_dept_head' => false,
        ];

        [$where, $params] = $this->kpiScopeWhere($scope);

        // The subsection-head branch uses FIND_IN_SET on assigned_to_employee_ids,
        // not pi.subsection_id, so it correctly handles the case where the first
        // selected employee was section staff (no subsection_id).
        self::assertStringContainsString('FIND_IN_SET(ae.id, pi.assigned_to_employee_ids) > 0', $where);
        self::assertStringNotContainsString('pi.subsection_id = ?', $where);
    }

    public function testDeptHeadSeesKPIsWithAnyStaffAssigneeInItsDepartment(): void
    {
        $scope = [
            'role' => 'dept_head',
            'department_id' => 2,
            'section_id' => null,
            'is_section_head' => false,
            'is_sub_section_head' => false,
            'is_dept_head' => true,
        ];

        [$where, $params] = $this->kpiScopeWhere($scope);

        // A department head sees KPIs assigned to any employee in the department,
        // including section heads and section managers (but not subsection heads).
        self::assertStringContainsString('pi.department_id = ?', $where);
        self::assertStringContainsString('FIND_IN_SET(assigned_employee.id, pi.assigned_to_employee_ids) > 0', $where);
        self::assertStringContainsString('assigned_employee.employee_type IN', $where);
        self::assertSame([2, 2], $params);
    }

    public function testDeptHeadSeesSubSectionHeadsAssigneeKPIs(): void
    {
        $scope = [
            'role' => 'dept_head',
            'department_id' => 2,
            'section_id' => null,
            'is_section_head' => false,
            'is_sub_section_head' => false,
            'is_dept_head' => true,
        ];

        [$where, $params] = $this->kpiScopeWhere($scope);

        // The dept-head branch's EXISTS clause matches section heads and section
        // managers in the department (not subsection heads — they belong to a subsection).
        self::assertStringContainsString('assigned_employee.employee_type IN', $where);
        self::assertStringContainsString("('section_head', 'section_manager')", $where);
        self::assertSame([2, 2], $params);
    }
}

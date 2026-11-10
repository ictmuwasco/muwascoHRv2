<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\HR\SectionalObjectiveController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class SectionalObjectiveControllerAssignmentTest extends TestCase
{
    public function testDepartmentActivityMatchesEmployeesInItsDepartment(): void
    {
        self::assertTrue($this->matchesEmployeeUnit(
            ['department_id' => 4, 'section_id' => null, 'subsection_id' => null],
            ['department_id' => 4, 'section_id' => 3, 'subsection_id' => null, 'employee_type' => 'officer']
        ));
        self::assertFalse($this->matchesEmployeeUnit(
            ['department_id' => 4, 'section_id' => null, 'subsection_id' => null],
            ['department_id' => 5, 'section_id' => 3, 'subsection_id' => null, 'employee_type' => 'officer']
        ));
    }

    public function testDepartmentHeadCannotAssignDepartmentWideActivityToSectionHead(): void
    {
        self::assertFalse($this->matchesEmployeeUnit(
            ['department_id' => 4, 'section_id' => null, 'subsection_id' => null],
            ['department_id' => 4, 'section_id' => 3, 'subsection_id' => null, 'employee_type' => 'section_head']
        ));
    }

    public function testSectionActivityDoesNotMatchEmployeesInAnotherSection(): void
    {
        $activity = ['department_id' => 4, 'section_id' => 3, 'subsection_id' => null];

        self::assertTrue($this->matchesEmployeeUnit(
            $activity,
            ['department_id' => 4, 'section_id' => 3, 'subsection_id' => null, 'employee_type' => 'section_head']
        ));
        self::assertFalse($this->matchesEmployeeUnit(
            $activity,
            ['department_id' => 4, 'section_id' => 12, 'subsection_id' => null, 'employee_type' => 'section_head']
        ));
    }

    public function testSectionHeadCannotUseSubsectionActivityForSectionLevelKpi(): void
    {
        self::assertFalse($this->matchesEmployeeUnit(
            ['department_id' => 4, 'section_id' => 3, 'subsection_id' => 1],
            ['department_id' => 4, 'section_id' => 3, 'subsection_id' => null, 'employee_type' => 'section_head']
        ));
    }

    public function testSubsectionActivityDoesNotMatchEmployeesInAnotherSubsection(): void
    {
        $activity = ['department_id' => 4, 'section_id' => 3, 'subsection_id' => 1];

        self::assertTrue($this->matchesEmployeeUnit(
            $activity,
            ['department_id' => 4, 'section_id' => 3, 'subsection_id' => 1, 'employee_type' => 'officer']
        ));
        self::assertFalse($this->matchesEmployeeUnit(
            $activity,
            ['department_id' => 4, 'section_id' => 3, 'subsection_id' => 2, 'employee_type' => 'officer']
        ));
    }

    private function matchesEmployeeUnit(array $activity, array $employee): bool
    {
        $controller = (new ReflectionClass(SectionalObjectiveController::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(SectionalObjectiveController::class, 'activityMatchesEmployee');
        $method->setAccessible(true);

        return $method->invoke($controller, $activity, $employee);
    }
}

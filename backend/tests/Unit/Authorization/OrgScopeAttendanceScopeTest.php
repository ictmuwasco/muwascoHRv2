<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Helpers\OrgScope;
use PHPUnit\Framework\TestCase;

/**
 * Attendance DATA SCOPE tests (Phase: Role, Page & Permission restriction).
 *
 * Permission (attendance:view / attendance:manage) and data scope (WHICH
 * attendance records) are separate axes. These tests lock the scope logic
 * that AttendanceController and AttendanceDashboardService apply to every
 * attendance read:
 *
 *   all  — super admin, HR, Managing Director, PME/Audit leadership
 *   unit — department / section / sub-section heads (narrowed to own unit)
 *   own  — everyone else: OWN records only (officer, employee, unknown roles)
 *
 * Pure logic tests: scope arrays are constructed inline; no database needed.
 *
 * Place: backend/tests/Unit/Authorization/OrgScopeAttendanceScopeTest.php
 */
class OrgScopeAttendanceScopeTest extends TestCase
{
    /* --------------------------------------------------------------------
     * attendanceReadMode
     * -------------------------------------------------------------------- */

    public function testBroadRolesResolveToOrgWideScope(): void
    {
        $this->assertSame('all', OrgScope::attendanceReadMode(['role' => 'super_admin', 'is_super_admin' => true]));
        $this->assertSame('all', OrgScope::attendanceReadMode(['role' => 'hr_manager', 'is_hr' => true]));
        $this->assertSame('all', OrgScope::attendanceReadMode(['role' => 'managing_director']));
        $this->assertSame('all', OrgScope::attendanceReadMode(['role' => 'dept_head', 'is_pme_or_audit' => true]));
    }

    public function testHeadRolesResolveToUnitScope(): void
    {
        $this->assertSame('unit', OrgScope::attendanceReadMode(['role' => 'dept_head', 'is_dept_head' => true]));
        $this->assertSame('unit', OrgScope::attendanceReadMode(['role' => 'section_head', 'is_section_head' => true]));
        $this->assertSame('unit', OrgScope::attendanceReadMode(['role' => 'sub_section_head', 'is_sub_section_head' => true]));
    }

    public function testPlainRolesResolveToOwnScope(): void
    {
        $this->assertSame('own', OrgScope::attendanceReadMode(['role' => 'officer']));
        $this->assertSame('own', OrgScope::attendanceReadMode(['role' => 'employee']));
        $this->assertSame('own', OrgScope::attendanceReadMode(['role' => 'bod_chairman']));
        $this->assertSame('own', OrgScope::attendanceReadMode([]), 'Unknown role defaults to own-scope, never org-wide');
    }

    /* --------------------------------------------------------------------
     * attendanceWhere — SQL clause generation
     * -------------------------------------------------------------------- */

    public function testOwnScopePinsToOwnEmployeeId(): void
    {
        [$where, $params] = OrgScope::attendanceWhere(['role' => 'officer'], 42);

        $this->assertSame('a.employee_id = ?', $where);
        $this->assertSame([42], $params);
    }

    public function testOwnScopeWithUnresolvableIdentityDeniesEverything(): void
    {
        [$where, $params] = OrgScope::attendanceWhere(['role' => 'officer'], null);

        $this->assertSame('1=0', $where, 'Unresolvable identity must deny, never expose data');
        $this->assertSame([], $params);
    }

    public function testUnitScopeNarrowsSubSectionHeadToOwnUnit(): void
    {
        $scope = [
            'role'                => 'sub_section_head',
            'is_sub_section_head' => true,
            'department_id'       => 3,
            'section_id'          => 8,
            'subsection_id'       => 15,
        ];

        [$where, $params] = OrgScope::attendanceWhere($scope, null, [
            'department_id' => 'e.department_id',
            'section_id'    => 'e.section_id',
            'subsection_id' => 'e.subsection_id',
        ]);

        $this->assertSame('e.department_id = ? AND e.section_id = ? AND e.subsection_id = ?', $where);
        $this->assertSame([3, 8, 15], $params);
    }

    public function testUnitScopeWithUnresolvableUnitDeniesEverything(): void
    {
        $scope = ['role' => 'section_head', 'is_section_head' => true];

        [$where, $params] = OrgScope::attendanceWhere($scope, null);

        $this->assertSame('1=0', $where, 'Head with unresolvable unit must see NOTHING');
        $this->assertSame([], $params);
    }

    /* --------------------------------------------------------------------
     * attendanceEmployeeAllowed — IDOR guard for by-employee endpoints
     * -------------------------------------------------------------------- */

    public function testOwnScopeCallerMayOnlyReadOwnRecord(): void
    {
        $scope = ['role' => 'officer'];

        $this->assertTrue(OrgScope::attendanceEmployeeAllowed($scope, 42, ['id' => 42]));
        $this->assertFalse(OrgScope::attendanceEmployeeAllowed($scope, 42, ['id' => 43]), 'Officer must never read another employee');
        $this->assertFalse(OrgScope::attendanceEmployeeAllowed($scope, null, ['id' => 42]), 'No employee profile = no access');
    }

    public function testUnitScopeCallerIsPinnedToOwnUnit(): void
    {
        $scope = [
            'role'                => 'sub_section_head',
            'is_sub_section_head' => true,
            'department_id'       => 3,
            'section_id'          => 8,
            'subsection_id'       => 15,
        ];

        $inUnit       = ['id' => 90, 'department_id' => 3, 'section_id' => 8, 'subsection_id' => 15];
        $otherSub     = ['id' => 91, 'department_id' => 3, 'section_id' => 8, 'subsection_id' => 16];
        $otherSection = ['id' => 92, 'department_id' => 3, 'section_id' => 9, 'subsection_id' => 15];
        $otherDept    = ['id' => 93, 'department_id' => 4, 'section_id' => 8, 'subsection_id' => 15];

        $this->assertTrue(OrgScope::attendanceEmployeeAllowed($scope, null, $inUnit));
        $this->assertFalse(OrgScope::attendanceEmployeeAllowed($scope, null, $otherSub), 'Same section, other subsection = DENY');
        $this->assertFalse(OrgScope::attendanceEmployeeAllowed($scope, null, $otherSection), 'Other section = DENY');
        $this->assertFalse(OrgScope::attendanceEmployeeAllowed($scope, null, $otherDept), 'Other department = DENY');
    }

    public function testDepartmentHeadIsPinnedToOwnDepartment(): void
    {
        $scope = ['role' => 'dept_head', 'is_dept_head' => true, 'department_id' => 3];

        $this->assertTrue(OrgScope::attendanceEmployeeAllowed($scope, null, ['id' => 90, 'department_id' => 3]));
        $this->assertFalse(OrgScope::attendanceEmployeeAllowed($scope, null, ['id' => 91, 'department_id' => 4]), 'Other department = DENY');
    }

    public function testBroadScopeCallerMayReadAnyRecord(): void
    {
        $scope = ['role' => 'hr_manager', 'is_hr' => true];

        $this->assertTrue(OrgScope::attendanceEmployeeAllowed($scope, null, ['id' => 90]));
        $this->assertTrue(OrgScope::attendanceEmployeeAllowed($scope, null, ['id' => 91]));
    }

    public function testUnresolvableUnitForHeadDeniesByEmployeeLookup(): void
    {
        $scope = ['role' => 'dept_head', 'is_dept_head' => true];

        $this->assertFalse(
            OrgScope::attendanceEmployeeAllowed($scope, null, ['id' => 90, 'department_id' => 3]),
            'Head whose unit cannot be resolved must not fall through to org-wide'
        );
    }
}

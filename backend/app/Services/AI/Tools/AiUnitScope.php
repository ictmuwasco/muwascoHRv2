<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Helpers\OrgScope;

/**
 * AiUnitScope — resolves WHICH rows an AI tool may read for the caller.
 *
 * The application separates two axes everywhere: PERMISSION (may you open the
 * module at all — enforced by AiToolInterface::requiredPermission + the
 * registry/executor) and DATA SCOPE (WHICH rows within it). This helper owns
 * the second axis for the AI tool layer, expressed from the per-turn
 * AiToolContext so tools stay free of session coupling and remain testable:
 *
 *   - ORGANISATION-WIDE when the caller holds dashboard:hr_insights (seeded to
 *     hr_manager, managing_director, super_admin) or belongs to the PME/Audit
 *     oversight departments (OrgScope::isOversightDepartment()) — the same
 *     special case OrgScope applies to the rest of the app.
 *   - Otherwise narrowed to their OWN unit: department, plus section when the
 *     employee record carries one (mirrors OrgScope::scopeWhere()).
 *   - When no unit can be resolved the clause DENIES everything (1=0) rather
 *     than accidentally exposing organisation-wide rows.
 *
 * Usage (positional mysqli binding — scope params always go LAST):
 *
 *   [$scope, $params, $types] = AiUnitScope::where($ctx, 'e');
 *   $rows = $ctx->db()->fetchAll(
 *       'SELECT ... WHERE a.attendance_date BETWEEN ? AND ?' . $scope,
 *       'ss' . $types,
 *       array_merge([$from, $to], $params)
 *   );
 */
final class AiUnitScope
{
    /**
     * SQL tail (starting with ' AND ') restricting the query alias to the
     * caller's unit, the bound params and their mysqli type string.
     *
     * @return array{0: string, 1: array<int, int>, 2: string}
     */
    public static function where(AiToolContext $ctx, string $alias = 'e'): array
    {
        if (self::isOrgWide($ctx)) {
            return ['', [], ''];
        }

        $dept = $ctx->departmentId();
        $sec  = $ctx->sectionId();

        if ($dept === null && $sec === null) {
            // Unit unresolvable - deny instead of widening the result set.
            return [' AND 1=0', [], ''];
        }

        $clauses = [];
        $params  = [];
        $types   = '';
        if ($dept !== null) {
            $clauses[] = $alias . '.department_id = ?';
            $params[]  = $dept;
            $types    .= 'i';
        }
        if ($sec !== null) {
            $clauses[] = $alias . '.section_id = ?';
            $params[]  = $sec;
            $types    .= 'i';
        }

        return [' AND ' . implode(' AND ', $clauses), $params, $types];
    }

    /**
     * True when the caller may read beyond their own unit. Permission-driven
     * (per-user overrides included) so scope can be granted/revoked per user
     * without touching code.
     */
    public static function isOrgWide(AiToolContext $ctx): bool
    {
        if ($ctx->can('dashboard', 'hr_insights')) {
            return true;
        }

        return OrgScope::isOversightDepartment($ctx->departmentId());
    }

    /** Short, user-facing description of the caller's data reach. */
    public static function label(AiToolContext $ctx): string
    {
        if (self::isOrgWide($ctx)) {
            return 'the whole organisation';
        }
        if ($ctx->departmentId() === null && $ctx->sectionId() === null) {
            return 'no organisation unit (no HR data is available for this account)';
        }

        return $ctx->sectionId() !== null ? 'your section' : 'your department';
    }
}

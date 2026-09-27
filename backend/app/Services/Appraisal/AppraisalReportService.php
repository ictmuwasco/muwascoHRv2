<?php

declare(strict_types=1);

namespace App\Services\Appraisal;

use App\Helpers\Auth;
use App\Helpers\Database;

/**
 * AppraisalReportService - read model behind the "Completed Appraisals" page.
 *
 * Visibility is NOT re-implemented here. It already lives in
 * AppraisalWorkflowService (isBroadScope / mayReviewRow / currentEmployee) and
 * is exercised by the review queues, so a second copy could drift and quietly
 * widen access. This service DELEGATES the decision and only adds:
 *
 *   - a status filter over the same scoped query;
 *   - a SELF-SCOPE path for officers, who may read only their own records;
 *   - the indicator -> activity score fan-out the report needs;
 *   - the printable HTML shared by PDF, Word and print.
 *
 * Out-of-scope reads raise "not found" (never 403) so the endpoints cannot be
 * used to probe for other units' appraisals.
 */
class AppraisalReportService
{
    private \mysqli $db;
    private AppraisalWorkflowService $workflow;

    /** Default status for this page. */
    public const STATUS_COMPLETED = 'completed';

    /**
     * Statuses the page may be filtered to - the full workflow enum.
     *
     * The page is an ARCHIVE, not a review queue, so a supervisor looking for
     * "what has this person been through" needs every stage, not just the
     * three terminal ones. The empty string (exposed in the UI as "All
     * statuses") applies NO status predicate at all and is the default.
     */
    public const FILTERABLE_STATUSES = [
        'draft',
        'awaiting_employee',
        'submitted',
        'completed',
        'awaiting_submission',
        'pending_dept_approval',
        'under_review',
        'rejected',
        'cancelled',
    ];

    /** Sentinel meaning "do not filter by status" - the page default. */
    public const STATUS_ALL = '';

    /**
     * Upper bound on rows pulled into one analytics aggregation.
     *
     * completedList() clamps per_page to 100, so the analytics pass asks for
     * this and compares `total` against the row count to detect truncation
     * rather than silently reporting partial sums.
     */
    private const ANALYTICS_MAX_ROWS = 100;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->workflow = new AppraisalWorkflowService();
    }

    /**
     * True when the caller may only ever see their OWN appraisals.
     *
     * Officers hold `performance:feedback` (My Appraisals) but not
     * `performance:supervise`, so they are not a supervisor and must not get
     * any unit-wide view. They still need to download their own completed
     * appraisals, so this page accepts them - pinned to their own record.
     */
    public function isSelfScope(): bool
    {
        return !Auth::getInstance()->hasPermission('performance', 'supervise');
    }

    /**
     * The caller's own employee PK, or 0 when the account is not linked to an
     * active employee (in which case self-scope legitimately yields nothing).
     */
    private function ownEmployeeId(): int
    {
        $actor = $this->workflow->currentEmployee();
        return $actor ? (int) $actor['id'] : 0;
    }

    /**
     * May the caller see this specific appraisal?
     *
     * Self-scoped callers are matched against their own employee PK directly.
     * They deliberately do NOT go through the review rule, which rejects the
     * actor's own record by design (a reviewer must never review themselves) -
     * reusing it here would hide an officer's own appraisals entirely.
     */
    private function canSeeRow(array $row): bool
    {
        if ($this->isSelfScope()) {
            $own = $this->ownEmployeeId();
            $target = (int) ($row['employee_pk'] ?? $row['id'] ?? 0);
            return $own > 0 && $target === $own;
        }

        // Supervisors read an ARCHIVE of finished appraisals, not a review
        // queue, so the review rule's "never review yourself" guard must NOT
        // apply here: mayReviewRow() rejects the caller's own row outright,
        // which silently hid a section head's own completed appraisal even
        // though their scope query (department + section) matched it. The
        // scope predicate has already been applied in SQL, so admitting the
        // actor's own row adds nothing they could not already see.
        if ($this->workflow->mayReviewRow($row)) {
            return true;
        }

        $actor = $this->workflow->currentEmployee();
        if (!$actor) {
            return false;
        }
        $target = (int) ($row['employee_pk'] ?? $row['id'] ?? 0);
        return $target > 0 && $target === (int) $actor['id'];
    }

    /**
     * Paginated, scope-limited list for the Completed Appraisals page.
     *
     * @param  array<string,mixed> $filters cycle_id, department_id, section_id, status, search
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,filters:array<string,mixed>}
     */
    public function completedList(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        // A self-scoped caller who is not linked to an employee record can
        // never match a row, so short-circuit rather than running a query
        // that is guaranteed to return nothing.
        if ($this->isSelfScope() && $this->ownEmployeeId() <= 0) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'filters' => []];
        }

        $page = max(1, $page);
        $perPage = min(100, max(5, $perPage));
        $offset = ($page - 1) * $perPage;

        // "All statuses" (empty / absent) applies NO status predicate, so the
        // default view is the whole archive rather than a hard-coded 'completed'.
        // Any value outside the known enum is ignored rather than silently
        // coerced - a typo must not be able to fabricate a status that matches
        // nothing and look like an empty archive.
        $rawStatus = trim((string) ($filters['status'] ?? self::STATUS_ALL));
        $status = in_array($rawStatus, self::FILTERABLE_STATUSES, true) ? $rawStatus : self::STATUS_ALL;

        // The scope's OWN params must be carried into applyFilters: a scoped
        // role's clause contains a placeholder (e.g. e.department_id = ?) and
        // dropping it leaves more placeholders than bound values, which
        // mysqli rejects with ArgumentCountError. Org-wide roles return
        // ['1=1', []] and are unaffected.
        [$scopeWhere, $scopeParams] = $this->scopedWhere();
        [$where, $params] = $this->applyFilters($scopeWhere, $scopeParams, $filters, $status);

        $rows = $this->fetchRows($where, $params, $offset, $perPage);
        $visible = array_values(array_filter(
            $rows,
            fn (array $row): bool => $this->canSeeRow($row)
        ));

        // The visibility filter can only REMOVE rows, so a count taken before
        // it would be an upper bound. It is recounted exactly so the pager
        // cannot advertise phantom pages.
        $total = $this->countVisible($where, $params);

        return [
            'items'    => array_map([$this, 'presentRow'], $visible),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'filters'  => [
                'status'        => $status,
                'cycle_id'      => (int) ($filters['cycle_id'] ?? 0) ?: null,
                'department_id' => (int) ($filters['department_id'] ?? 0) ?: null,
                'section_id'    => (int) ($filters['section_id'] ?? 0) ?: null,
                'subsection_id' => (int) ($filters['subsection_id'] ?? 0) ?: null,
                'search'        => trim((string) ($filters['search'] ?? '')),
            ],
        ];
    }

    /**
     * Aggregate performance across the caller's scope.
     *
     * Deliberately reuses completedList()'s own pipeline - same scopedWhere(),
     * same applyFilters(), same canSeeRow() and the same score summaries - so
     * the headline number can never disagree with the table underneath it. A
     * separate, hand-written aggregate query is exactly how the two would drift.
     *
     * Averages are score-weighted (SUM(score) / SUM(max_score)), NOT the mean
     * of per-row percentages: an appraisal scored 1/1 must not count as much as
     * one scored 40/50, and averaging the percentages would say otherwise.
     *
     * @param  array<string,mixed> $filters Same shape as completedList().
     * @return array<string,mixed>
     */
    public function analytics(array $filters = []): array
    {
        // Analytics are a supervisory view; a self-scoped officer has nothing
        // to aggregate, so the caller is told so explicitly rather than being
        // handed a set of zeros that look like real findings.
        if ($this->isSelfScope()) {
            return ['available' => false];
        }

        $result = $this->completedList($filters, 1, self::ANALYTICS_MAX_ROWS);
        $rows = $result['items'];
        $total = (int) $result['total'];

        // completedList() caps the page, so a heavily-filtered query could be
        // truncated. Aggregating a truncated set would silently understate the
        // totals, so the caller is told when that happens.
        $truncated = $total > count($rows);

        $sum = 0.0;
        $max = 0.0;
        $scored = 0;
        $units = [
            'department' => [],
            'section'    => [],
            'subsection' => [],
            'employee'   => [],
            'cycle'      => [],
        ];

        foreach ($rows as $row) {
            $rowSum = (float) ($row['total_score'] ?? 0);
            $rowMax = (float) ($row['total_max_score'] ?? 0);
            $sum += $rowSum;
            $max += $rowMax;
            if ($rowMax > 0) {
                $scored++;
            }

            // Buckets are keyed by id when known so two units sharing a name are
            // still counted separately, and by name only as a fallback for rows
            // whose unit was never assigned.
            $this->bucket($units['department'], $row['department_id'] ?? null, $row['department_name'] ?? null, $rowSum, $rowMax);
            $this->bucket($units['section'], $row['section_id'] ?? null, $row['section_name'] ?? null, $rowSum, $rowMax);
            $this->bucket($units['subsection'], $row['subsection_id'] ?? null, $row['subsection_name'] ?? null, $rowSum, $rowMax);
            $this->bucket($units['employee'], $row['employee_pk'] ?? null, $row['employee_name'] ?? null, $rowSum, $rowMax, $row['employee_code'] ?? null);
            $this->bucket($units['cycle'], $row['appraisal_cycle_id'] ?? null, $row['cycle_name'] ?? null, $rowSum, $rowMax);
        }

        return [
            'available'   => true,
            'truncated'   => $truncated,
            'appraisals'  => $total,
            'scored'      => $scored,
            'overall'     => [
                'total_score'     => round($sum, 2),
                'total_max_score' => round($max, 2),
                'percentage'      => $max > 0 ? round(($sum / $max) * 100, 1) : null,
            ],
            'departments' => $this->rank($units['department']),
            'sections'    => $this->rank($units['section']),
            'subsections' => $this->rank($units['subsection']),
            'top_employee' => $this->best($units['employee']),
            'top_cycle'    => $this->best($units['cycle']),
        ];
    }

    /**
     * Add one row's score into a unit's running totals.
     *
     * @param array<string,array<string,mixed>> $buckets
     * @param string|null $extra Optional display detail (staff number).
     */
    private function bucket(array &$buckets, $id, ?string $name, float $score, float $max, ?string $extra = null): void
    {
        if ($name === null || $name === '') {
            return;
        }
        $key = $id !== null && $id !== '' ? 'id:' . $id : 'name:' . $name;
        if (!isset($buckets[$key])) {
            $buckets[$key] = [
                'id'              => $id !== null && $id !== '' ? (int) $id : null,
                'name'            => $name,
                'detail'          => $extra,
                'count'           => 0,
                'total_score'     => 0.0,
                'total_max_score' => 0.0,
            ];
        }
        if ($extra && empty($buckets[$key]['detail'])) {
            $buckets[$key]['detail'] = $extra;
        }
        $buckets[$key]['count']++;
        $buckets[$key]['total_score'] += $score;
        $buckets[$key]['total_max_score'] += $max;
    }

    /**
     * Turn running totals into a ranked, render-ready list.
     *
     * Units with no scores at all are dropped: a 0% average for a unit that was
     * simply never scored is a false finding, not a real one.
     *
     * @param  array<string,array<string,mixed>> $buckets
     * @return array<int,array<string,mixed>>
     */
    private function rank(array $buckets): array
    {
        $out = [];
        foreach ($buckets as $b) {
            if ((float) $b['total_max_score'] <= 0) {
                continue;
            }
            $out[] = [
                'id'         => $b['id'],
                'name'       => $b['name'],
                'detail'     => $b['detail'] ?: null,
                'count'      => (int) $b['count'],
                'percentage' => round(($b['total_score'] / $b['total_max_score']) * 100, 1),
            ];
        }
        usort($out, static function (array $a, array $b): int {
            // Percentage first, then volume so a single lucky appraisal cannot
            // outrank a sustained record, then name for a stable order.
            return [$b['percentage'], $b['count'], $a['name']] <=> [$a['percentage'], $a['count'], $b['name']];
        });
        return $out;
    }

    /**
     * The single best performer in a bucket, or null when nothing is scored.
     *
     * @param  array<string,array<string,mixed>> $buckets
     * @return array<string,mixed>|null
     */
    private function best(array $buckets): ?array
    {
        $ranked = $this->rank($buckets);
        return $ranked[0] ?? null;
    }

    /**
     * Count of visible rows, applying the same rule as the page so the pager
     * is accurate.
     */
    private function countVisible(string $where, array $params): int
    {
        // The search predicate in applyFilters() references `ac.name`, so this
        // query MUST join appraisal_cycles too. Without it a keyword search
        // made the count query fail on an unknown column while the page itself
        // loaded fine - a pager that reported the wrong total.
        $sql = "SELECT a.id, a.employee_id, a.appraiser_id, a.status, a.escalation_level,
                       e.id AS employee_pk, e.employee_type,
                       e.department_id, e.section_id, e.subsection_id
                FROM employee_appraisals a
                JOIN employees e ON e.id = a.employee_id
                LEFT JOIN appraisal_cycles ac ON ac.id = a.appraisal_cycle_id
                WHERE {$where}";
        $stmt = $this->db->prepare($sql);
        if ($params) {
            // Same rule as fetchRows(): the status ENUM binds as a string.
            // Binding it as an integer made the count 0 for every filter.
            $stmt->bind_param(self::callables($params), ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $count = 0;
        foreach ($rows as $row) {
            if ($this->canSeeRow($row)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Row shape the page renders.
     */
    private function presentRow(array $row): array
    {
        $row['employee_name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $row['appraiser_name'] = trim(($row['appraiser_first_name'] ?? '') . ' ' . ($row['appraiser_last_name'] ?? ''));
        // str_replace() needs (search, replace, subject). The two-argument call
        // raised ArgumentCountError for EVERY row, so completedList() always
        // died inside array_map() and the page fell back to its empty state -
        // a silent failure with no error banner, even with rows in scope.

        $row['status_label'] = ucwords(str_replace('_', ' ', (string) $row['status']));
        $row['score_percentage'] = ((float) ($row['total_max_score'] ?? 0)) > 0
            ? round(((float) $row['total_score'] / (float) $row['total_max_score']) * 100, 1)
            : 0.0;
        return $row;
    }
    /**
     * Base WHERE for report rows.
     *
     * PUBLIC because the company-wide Appraisal Report module
     * (App\Services\Reports\AppraisalPerformanceReportService) aggregates over
     * the same population and must pin to the identical scope. Re-deriving the
     * rule there would let the two drift, and a drifting scope is a data leak.
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    public function scopedWhere(): array
    {
        // Self-scope FIRST: the department clause below would otherwise hand an
        // officer every completed appraisal in their department.
        if ($this->isSelfScope()) {
            $own = $this->ownEmployeeId();
            return $own > 0 ? ['a.employee_id = ?', [$own]] : ['1=0', []];
        }
        if ($this->workflow->isBroadScope()) {
            return ['1=1', []];
        }

        $actor = $this->workflow->currentEmployee();
        if (!$actor) {
            return ['1=0', []];
        }

        $role = strtolower((string) (Auth::getInstance()->role() ?: ''));
        $departmentId = $actor['department_id'] ?? null;
        if ($departmentId === null) {
            return ['1=0', []];
        }
        $clauses = ['e.department_id = ?'];
        $params = [(int) $departmentId];

        $sectionId = $actor['section_id'] ?? null;
        $subsectionId = $actor['subsection_id'] ?? null;
        if ($role === 'section_head' && $sectionId !== null) {
            $clauses[] = 'e.section_id = ?';
            $params[] = (int) $sectionId;
        } elseif ($role === 'sub_section_head' && $sectionId !== null && $subsectionId !== null) {
            $clauses[] = 'e.section_id = ?';
            $params[] = (int) $sectionId;
            $clauses[] = 'e.subsection_id = ?';
            $params[] = (int) $subsectionId;
        } elseif ($role === 'manager' && $sectionId !== null) {
            $clauses[] = 'e.section_id = ?';
            $params[] = (int) $sectionId;
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * @param  array<int,mixed>  $baseParams Params already required by $scopeWhere.
     * @param  array<string,mixed> $filters
     * @return array{0:string,1:array<int,mixed>}
     */
    private function applyFilters(string $scopeWhere, array $baseParams, array $filters, string $status): array
    {
        $clauses = [$scopeWhere];
        $params = $baseParams;

        // Only narrow by status when one was actually chosen; the "All
        // statuses" default must leave the column unconstrained.
        if ($status !== self::STATUS_ALL) {
            $clauses[] = 'a.status = ?';
            $params[] = $status;
        }

        $cycleId = (int) ($filters['cycle_id'] ?? 0);
        if ($cycleId > 0) {
            $clauses[] = 'a.appraisal_cycle_id = ?';
            $params[] = $cycleId;
        }
        $departmentId = (int) ($filters['department_id'] ?? 0);
        if ($departmentId > 0) {
            $clauses[] = 'e.department_id = ?';
            $params[] = $departmentId;
        }
        $sectionId = (int) ($filters['section_id'] ?? 0);
        if ($sectionId > 0) {
            $clauses[] = 'e.section_id = ?';
            $params[] = $sectionId;
        }
        $subsectionId = (int) ($filters['subsection_id'] ?? 0);
        if ($subsectionId > 0) {
            $clauses[] = 'e.subsection_id = ?';
            $params[] = $subsectionId;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $clauses[] = '(e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ? OR ac.name LIKE ?)';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchRows(string $where, array $params, int $offset, int $limit): array
    {
        $sql = "SELECT a.id, a.status, a.employee_id, a.appraiser_id, a.appraisal_cycle_id,
                       a.submitted_at, a.created_at, a.employee_comment, a.employee_satisfied,
                       a.employee_comment_date, a.supervisors_comment, a.supervisors_comment_date,
                       a.dept_head_decision, a.dept_head_decision_date, a.escalation_level,
                       e.first_name, e.last_name, e.employee_id AS employee_code,
                       e.id AS employee_pk, e.employee_type,
                       e.department_id, e.section_id, e.subsection_id,
                       d.name AS department_name, s.name AS section_name, ss.name AS subsection_name,
                       ap.first_name AS appraiser_first_name, ap.last_name AS appraiser_last_name,
                       ac.name AS cycle_name, ac.start_date, ac.end_date
                FROM employee_appraisals a
                JOIN employees e ON e.id = a.employee_id
                LEFT JOIN departments d ON d.id = e.department_id
                LEFT JOIN sections s ON s.id = e.section_id
                LEFT JOIN subsections ss ON ss.id = e.subsection_id
                LEFT JOIN employees ap ON ap.id = a.appraiser_id
                LEFT JOIN appraisal_cycles ac ON ac.id = a.appraisal_cycle_id
                WHERE {$where}
                ORDER BY a.submitted_at DESC, a.id DESC
                LIMIT ? OFFSET ?";
        // Types must mirror the VALUES, not the columns' apparent nature.
        // The scope/status/search placeholders are NOT all integers: `a.status`
        // is an ENUM and binds as a string. Binding it as 'i' makes mysqli
        // cast 'completed' to 0, so the predicate became `a.status = 0` and
        // matched NOTHING - the page silently reported "0 records" for every
        // filter while the data was present and in scope. callables() derives
        // the type string from the same params that build the SQL, so the two
        // can never drift apart again.
        $types = self::callables($params);
        $types .= 'ii'; // LIMIT, OFFSET
        $bind = $params;
        $bind[] = $limit;
        $bind[] = $offset;
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$bind);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // The list renders a Score column, but the paged query above cannot
        // aggregate appraisal_scores without collapsing the LIMIT/pagination.
        // Fetch the per-appraisal totals in ONE follow-up query and merge them
        // in, otherwise every row fell through to 0/0 and rendered "0%".
        return $this->attachScoreSummaries($rows ?: []);
    }

    /**
     * Merge per-appraisal score totals onto the paged rows.
     *
     * Uses the same "newest row per (appraisal, indicator) wins" rule as
     * AppraisalWorkflowService::scoreSummaries(), so the list, the breakdown
     * and the review queues can never disagree about a total.
     *
     * @param  array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function attachScoreSummaries(array $rows): array
    {
        $ids = array_values(array_unique(array_map('intval', array_column($rows, 'id'))));
        if ($ids === []) {
            return $rows;
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT newest.employee_appraisal_id,
                       COALESCE(SUM(newest.score), 0) AS total_score,
                       COALESCE(SUM(p.max_score), 0) AS total_max_score
                FROM (
                    SELECT s.employee_appraisal_id, s.performance_indicator_id, s.score
                    FROM appraisal_scores s
                    LEFT JOIN appraisal_scores newer
                      ON newer.employee_appraisal_id = s.employee_appraisal_id
                     AND newer.performance_indicator_id = s.performance_indicator_id
                     AND (newer.updated_at > s.updated_at
                          OR (newer.updated_at = s.updated_at AND newer.id > s.id))
                    WHERE newer.id IS NULL
                ) newest
                JOIN performance_indicators p ON p.id = newest.performance_indicator_id
                WHERE newest.employee_appraisal_id IN ({$ph})
                GROUP BY newest.employee_appraisal_id";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $totals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $map = [];
        foreach ($totals as $total) {
            $map[(int) $total['employee_appraisal_id']] = $total;
        }
        foreach ($rows as $i => $row) {
            $sum = $map[(int) $row['id']] ?? null;
            $rows[$i]['total_score'] = (float) ($sum['total_score'] ?? 0);
            $rows[$i]['total_max_score'] = (float) ($sum['total_max_score'] ?? 0);
        }
        return $rows;
    }

    /**
     * mysqli type string for a parameter list.
     *
     * Every value produced by scopedWhere()/applyFilters() is either an
     * integer unit id or a non-numeric string (status enum, LIKE pattern).
     * Deciding per value - rather than assuming all-integer as the previous
     * `str_repeat('i', ...)` did - is what keeps ENUM and LIKE placeholders
     * bindable.
     *
     * @param  array<int,mixed> $params
     * @return string e.g. "iis"
     */
    private static function callables(array $params): string
    {
        $types = '';
        foreach ($params as $param) {
            $types .= is_int($param) || is_bool($param) ? 'i' : 's';
        }
        return $types;
    }

    /**
     * Full report payload for one appraisal.
     *
     * @return array<string,mixed>
     * @throws \DomainException when the caller may not see the appraisal
     */
    public function reportData(int $id): array
    {
        $stmt = $this->db->prepare(
            "SELECT a.id, a.status, a.employee_id, a.appraiser_id, a.appraisal_cycle_id,
                    a.submitted_at, a.created_at, a.employee_comment, a.employee_satisfied,
                    a.employee_comment_date, a.supervisors_comment, a.supervisors_comment_date,
                    a.dept_head_decision, a.dept_head_comment, a.dept_head_decision_date,
                    a.escalation_level,
                    e.first_name, e.last_name, e.employee_id AS employee_code,
                    e.id AS employee_pk, e.employee_type,
                    e.department_id, e.section_id, e.subsection_id,
                    d.name AS department_name, s.name AS section_name, ss.name AS subsection_name,
                    ap.first_name AS appraiser_first_name, ap.last_name AS appraiser_last_name,
                    ac.name AS cycle_name, ac.start_date, ac.end_date
             FROM employee_appraisals a
             JOIN employees e ON e.id = a.employee_id
             LEFT JOIN departments d ON d.id = e.department_id
             LEFT JOIN sections s ON s.id = e.section_id
             LEFT JOIN subsections ss ON ss.id = e.subsection_id
             LEFT JOIN employees ap ON ap.id = a.appraiser_id
             LEFT JOIN appraisal_cycles ac ON ac.id = a.appraisal_cycle_id
             WHERE a.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || !$this->canSeeRow($row)) {
            throw new \DomainException('Appraisal not found.');
        }

        $lines = $this->expandedScores($id);
        $total = 0.0;
        $max = 0.0;
        foreach ($lines as $line) {
            $total += (float) $line['score'];
            $max += (float) $line['max_score'];
        }

        $row['employee_name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $row['appraiser_name'] = trim(($row['appraiser_first_name'] ?? '') . ' ' . ($row['appraiser_last_name'] ?? ''));
        // Same two-argument str_replace() defect as presentRow(), duplicated
        // here. It threw ArgumentCountError on every report build, so the
        // breakdown endpoint and all three document exports (pdf/word/print)
        // returned 500 for every appraisal.
        $row['status_label'] = ucwords(str_replace('_', ' ', (string) $row['status']));
        $row['lines'] = $lines;
        $row['total_score'] = round($total, 2);
        $row['total_max_score'] = round($max, 2);
        $row['score_percentage'] = $max > 0 ? round(($total / $max) * 100, 1) : 0.0;

        return $row;
    }

    /**
     * Score breakdown expanded to one row per linked activity.
     *
     * An indicator's max_score is counted ONCE PER LINKED ACTIVITY, matching
     * the historical completed_appraisals report so previously issued
     * documents still reconcile.
     *
     * @return array<int,array<string,mixed>>
     */
    private function expandedScores(int $appraisalId): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.score, s.appraiser_comment, p.name AS indicator_name,
                    p.max_score, p.activity_ids
             FROM appraisal_scores s
             JOIN performance_indicators p ON p.id = s.performance_indicator_id
             WHERE s.employee_appraisal_id = ?
             ORDER BY p.name"
        );
        $stmt->bind_param('i', $appraisalId);
        $stmt->execute();
        $scores = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $lines = [];
        foreach ($scores as $score) {
            $activities = $this->activities((string) ($score['activity_ids'] ?? ''));
            $max = (float) $score['max_score'];
            if (!$activities) {
                $lines[] = $this->line((string) $score['indicator_name'], 'No activity linked', null, $max, $score);
                continue;
            }
            foreach ($activities as $activity) {
                $lines[] = $this->line(
                    (string) $score['indicator_name'],
                    (string) ($activity['objective'] ?? 'Activity'),
                    $activity['contract_name'] ?? null,
                    $max,
                    $score
                );
            }
        }
        return $lines;
    }

    /**
     * @param  array<string,mixed> $score
     * @return array<string,mixed>
     */
    private function line(string $indicator, string $activity, ?string $contract, float $max, array $score): array
    {
        return [
            'indicator_name'    => $indicator,
            'activity_name'     => $activity,
            'contract_name'     => $contract,
            'max_score'         => $max,
            'score'             => (float) ($score['score'] ?? 0),
            'appraiser_comment' => (string) ($score['appraiser_comment'] ?? ''),
        ];
    }

    /**
     * Resolve a comma-separated activity_ids list to workplan objectives.
     *
     * @return array<int,array<string,mixed>>
     */
    private function activities(string $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', $ids)), static fn (int $id): bool => $id > 0));
        if (!$ids) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT wo.id, wo.objective, pc.name AS contract_name
                FROM workplan_objectives wo
                LEFT JOIN performance_contracts pc ON pc.id = wo.performance_contract_id
                WHERE wo.id IN ({$ph})
                ORDER BY wo.objective";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows ?: [];
    }

    /**
     * Inline the corporate logo as a data URI.
     *
     * Resolved relative to this class so the lookup does not depend on the
     * working directory (which differs between CLI, Apache and tests). A
     * missing logo degrades to no image rather than breaking the export.
     */
    private function logoDataUri(): string
    {
        $data = @file_get_contents($this->logoPath());
        return $data === false ? '' : 'data:image/png;base64,' . base64_encode($data);
    }

    /**
     * True when this PHP runtime can actually rasterize an image.
     *
     * Dompdf hard-requires GD for image rendering, and the requirement is
     * checked at RENDER time, not at call time - so an export that embedded the
     * logo threw only after the whole report had been built. Probing up front
     * lets renderHtml() drop the image instead of failing the download.
     *
     * (PhpWord does not share this dependency, but it degrades on its own.)
     */
    private static function canRenderImages(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    /** Absolute path to the corporate logo, or '' when unavailable. */
    private function logoPath(): string
    {
        foreach ([
            dirname(__DIR__, 3) . '/public/assets/muwascologo.png',
            dirname(__DIR__, 4) . '/frontend/assets/muwascologo.png',
        ] as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }
        return '';
    }

    /** Performance band, mirroring the red/amber/green scale used in the UI. */
    private function band(float $percentage): string
    {
        if ($percentage < 50) return 'low';
        if ($percentage < 75) return 'medium';
        return 'high';
    }

    private function fmtNum(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.') ?: '0';
    }

    private function fmtDate(string $value): string
    {
        $ts = strtotime($value);
        return $ts ? date('d M Y', $ts) : '—';
    }

    private function fmtDateTime(string $value): string
    {
        $ts = strtotime($value);
        return $ts ? date('d M Y H:i', $ts) : '—';
    }

    /**
     * One optional comment panel. Returns '' when there is nothing to show so
     * the document never renders an empty box.
     */
    private function commentBlock(string $title, string $body, mixed $date): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }
        $e = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $stamp = !empty($date)
            ? '<small>Recorded: ' . $e($this->fmtDateTime((string) $date)) . '</small>'
            : '';
        return '<h2 class="section">' . $e($title) . '</h2>'
            . '<div class="comment"><p>' . nl2br($e($body)) . '</p>' . $stamp . '</div>';
    }

    /**
     * Render one appraisal as a self-contained HTML document.
     *
     * One template backs PDF and print so the two can never disagree. The logo
     * is inlined as a data URI: Dompdf runs with remote fetching disabled.
     *
     * @param array<string,mixed> $report Payload from reportData().
     */
    public function renderHtml(array $report): string
    {
        $e = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        // Dompdf needs the GD extension to rasterize ANY image - without it the
        // render throws "The PHP GD extension is required" and the whole export
        // 500s. A missing logo is cosmetic, a failed report is not, so only
        // embed it when the runtime can actually draw it; otherwise fall back
        // to the wordmark, which is already in the header below.
        $logo = self::canRenderImages() ? $this->logoDataUri() : '';
        $employeeName = $e(trim((string) ($report['employee_name'] ?? '')) ?: '—');
        $employeeCode = $e((string) ($report['employee_code'] ?? ''));
        $cycleName = $e((string) ($report['cycle_name'] ?? '—'));
        $department = $e((string) ($report['department_name'] ?? 'Not set'));
        $section = $e((string) ($report['section_name'] ?? 'Not set'));
        $appraiser = $e(trim((string) ($report['appraiser_name'] ?? '')) ?: '—');
        $statusLabel = $e((string) ($report['status_label'] ?? ''));

        $period = '—';
        if (!empty($report['start_date']) && !empty($report['end_date'])) {
            $period = $this->fmtDate((string) $report['start_date']) . ' – ' . $this->fmtDate((string) $report['end_date']);
        }
        $submitted = !empty($report['submitted_at'])
            ? $this->fmtDateTime((string) $report['submitted_at'])
            : 'Not submitted';

        $rows = '';
        foreach (($report['lines'] ?? []) as $line) {
            $max = (float) $line['max_score'];
            $score = (float) $line['score'];
            $pct = $max > 0 ? ($score / $max) * 100 : 0.0;
            $band = $this->band($pct);
            $contract = !empty($line['contract_name'])
                ? '<div class="subtle">' . $e((string) $line['contract_name']) . '</div>'
                : '';
            $rows .= '<tr>
                <td>' . $e((string) $line['activity_name']) . $contract . '</td>
                <td>' . $e((string) $line['indicator_name']) . '</td>
                <td class="num">' . $this->fmtNum($max) . '</td>
                <td class="num ' . $band . '">' . $this->fmtNum($score) . '</td>
                <td class="num ' . $band . '">' . $this->fmtNum($pct) . '%</td>
                <td>' . nl2br($e(trim((string) $line['appraiser_comment']) ?: '—')) . '</td>
            </tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6" class="empty">No scores have been recorded for this appraisal.</td></tr>';
        }

        $totalScore = (float) ($report['total_score'] ?? 0);
        $totalMax = (float) ($report['total_max_score'] ?? 0);
        $totalPct = (float) ($report['score_percentage'] ?? 0);
        $totalBand = $this->band($totalPct);

        $totals = '<tr class="totals">
            <td colspan="2"><strong>Total</strong></td>
            <td class="num"><strong>' . $this->fmtNum($totalMax) . '</strong></td>
            <td class="num ' . $totalBand . '"><strong>' . $this->fmtNum($totalScore) . '</strong></td>
            <td class="num ' . $totalBand . '"><strong>' . $this->fmtNum($totalPct) . '%</strong></td>
            <td></td>
        </tr>';

        $comments = $this->commentBlock('Employee comment', (string) ($report['employee_comment'] ?? ''), $report['employee_comment_date'] ?? null)
            . $this->commentBlock('Supervisor comment', (string) ($report['supervisors_comment'] ?? ''), $report['supervisors_comment_date'] ?? null)
            . $this->commentBlock(
                'Reviewer decision',
                trim(((string) ($report['dept_head_decision'] ?? '')) . ' ' . ((string) ($report['dept_head_comment'] ?? ''))),
                $report['dept_head_decision_date'] ?? null
            );

        $logoHtml = $logo === ''
            ? ''
            : '<img class="logo" src="' . $logo . '" alt="MURANGA WATER AND SANITATION COMPANY LTD">';

        return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Performance Appraisal Report</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10.5px; color: #1f2937; margin: 0; padding: 18px; line-height: 1.45; }
    .header { text-align: center; border-bottom: 2px solid #0f3d5c; padding-bottom: 12px; margin-bottom: 14px; }
    .logo { max-width: 110px; height: auto; margin-bottom: 6px; }
    .company { font-size: 16px; font-weight: bold; color: #0f3d5c; }
    .doctype { font-size: 13px; margin-top: 4px; color: #334155; }
    .cycle { font-size: 11.5px; margin-top: 2px; color: #475569; }
    .contact { font-size: 8.5px; color: #64748b; margin-top: 4px; }
    .meta { font-size: 8.5px; color: #64748b; margin-top: 6px; text-align: center; }
    h2.section { background: #0f3d5c; color: #fff; font-size: 11px; padding: 5px 9px; margin: 14px 0 6px; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; }
    .info th { background: #e8eef3; text-align: left; width: 20%; padding: 5px 7px; border: 1px solid #cbd5e1; font-size: 9.5px; }
    .info td { padding: 5px 7px; border: 1px solid #cbd5e1; }
    .scores th { background: #0f3d5c; color: #fff; text-align: left; padding: 5px 6px; border: 1px solid #0f3d5c; font-size: 9px; }
    .scores td { border: 1px solid #cbd5e1; padding: 5px 6px; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .subtle { color: #64748b; font-size: 8.5px; }
    .totals td { background: #ecfdf5; }
    .low { color: #b91c1c; font-weight: bold; }
    .medium { color: #b45309; font-weight: bold; }
    .high { color: #15803d; font-weight: bold; }
    .empty { text-align: center; color: #64748b; font-style: italic; }
    .comment { border: 1px solid #cbd5e1; background: #f8fafc; padding: 8px 10px; }
    .comment p { margin: 0; }
    .comment small { color: #64748b; font-size: 8.5px; }
    .footer { margin-top: 18px; padding-top: 8px; border-top: 1px solid #cbd5e1; text-align: center; font-size: 8.5px; color: #64748b; }
    .footer strong { color: #334155; }
</style>
</head>
<body>
    <div class="header">
        ' . $logoHtml . '
        <div class="company">MURANGA WATER AND SANITATION COMPANY LTD</div>
        <div class="doctype">Performance Appraisal Report</div>
        <div class="cycle">' . $cycleName . '</div>
        <div class="contact">P.O. Box 90461 - 80100, Muranga, Kenya &nbsp;|&nbsp; Tel: +254 (0) 41 2314209 &nbsp;|&nbsp; Email: info@muwasco.co.ke</div>
        <div class="meta">Employee: ' . $employeeName . ' (' . $employeeCode . ') &nbsp;&nbsp;|&nbsp;&nbsp; Status: ' . $statusLabel . ' &nbsp;&nbsp;|&nbsp;&nbsp; Generated: ' . date('d M Y H:i') . '</div>
    </div>

    <h2 class="section">Employee information</h2>
    <table class="info">
        <tr><th>Employee name</th><td>' . $employeeName . '</td><th>Employee ID</th><td>' . $employeeCode . '</td></tr>
        <tr><th>Department</th><td>' . $department . '</td><th>Section</th><td>' . $section . '</td></tr>
        <tr><th>Appraisal period</th><td>' . $period . '</td><th>Appraiser</th><td>' . $appraiser . '</td></tr>
        <tr><th>Submitted</th><td>' . $e($submitted) . '</td><th>Overall score</th><td class="num ' . $totalBand . '">' . $this->fmtNum($totalPct) . '%</td></tr>
    </table>

    <h2 class="section">Performance score breakdown</h2>
    <table class="scores">
        <thead>
            <tr>
                <th>Activity</th>
                <th>Performance indicator</th>
                <th class="num">Set score</th>
                <th class="num">Score</th>
                <th class="num">%</th>
                <th>Appraiser comment</th>
            </tr>
        </thead>
        <tbody>' . $rows . $totals . '</tbody>
    </table>

    ' . $comments . '

    <div class="footer">
        <p><strong>Confidential document &mdash; for official use only</strong></p>
        <p>Generated by the MURANGA WATER AND SANITATION COMPANY LTD HR Management System.</p>
        <p>&copy; ' . date('Y') . ' Muranga Water and Sanitation Company Ltd. All rights reserved.</p>
    </div>
</body>
</html>';
    }
}

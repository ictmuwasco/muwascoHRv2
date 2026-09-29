<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Helpers\Database;
use App\Services\Appraisal\AppraisalReportService;

/**
 * AppraisalPerformanceReportService
 *
 * Company-wide appraisal analytics for the Appraisal Reports page.
 *
 * Every figure on this page comes from ONE derived table - `scored` - built by
 * {@see baseSql()}. That is the whole point of the design: if summary, trends,
 * department averages and the employee table were each written as their own
 * query, they would each pick up the scope clause, the "newest score wins" rule
 * and the status filter at different moments, and the page would end up
 * cheerfully reporting three different numbers for the same thing.
 *
 * Reuses AppraisalReportService::scopedWhere() for authorisation rather than
 * re-deriving it. A report that quietly widened the scope of the appraisal
 * archive would be a data leak, not a cosmetic bug.
 *
 * Scope semantics, inherited unchanged: a self-scoped caller (no
 * `performance:supervise`) is pinned to their own appraisals, a section or
 * subsection head to their unit, and only broad-scope roles see the organisation.
 */
class AppraisalPerformanceReportService
{
    private \mysqli $db;
    private AppraisalReportService $scope;

    /**
     * Sort keys the employee table accepts.
     *
     * Mapped rather than interpolated: the sort column arrives from the query
     * string, and concatenating it straight into ORDER BY is SQL injection. The
     * '?' fallback is a deliberate deny, not a default.
     */
    private const SORTS = [
        'name'          => 'employee_name',
        'employee_code' => 'employee_code',
        'department'    => 'department_name',
        'appraisals'    => 'appraisals',
        'percentage'    => 'percentage',
        'total_score'   => 'total_score',
    ];

    /**
     * Minimum scored appraisals before an employee may be called a "performer".
     *
     * Without this, a single 95% appraisal would outrank someone with a dozen
     * consistent 80%s - technically correct, and completely misleading.
     */
    private const MIN_APPRAISALS = 2;

    /**
     * Sort keys the appraisal register accepts.
     *
     * Mapped rather than interpolated: the sort column arrives from the query
     * string, and concatenating it straight into ORDER BY is SQL injection.
     */
    private const REGISTER_SORTS = [
        'employee'   => 'employee_name',
        'code'       => 'e.employee_id',
        'department' => 'd.name',
        'cycle'      => 'ac.name',
        'status'     => 'a.status',
        'score'      => 'percentage',
        'points'     => 'total_score',
        'submitted'  => 'a.submitted_at',
    ];

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->scope = new AppraisalReportService();
    }

    /**
     * The FROM/JOIN half of the `scored` derived table, ending at the last join.
     *
     * Split from the select list on purpose: the grouped employee queries need
     * to supply their OWN aggregate columns, so the projection cannot be baked
     * in. The scope clause then applies unchanged to every projection.
     *
     * Column contract produced by the projections (referenced by name
     * throughout this class): appraisal_id, employee_pk, employee_code,
     * employee_name, employee_type, status, cycle_id, cycle_name, start_date,
     * end_date, submitted_at, department_id, department_name, section_id,
     * section_name, subsection_id, subsection_name, appraiser_name,
     * total_score, total_max_score, percentage.
     */
    private function baseFrom(): string
    {
        return "
            FROM employee_appraisals a
            JOIN employees e ON e.id = a.employee_id
            LEFT JOIN departments d   ON d.id  = e.department_id
            LEFT JOIN sections s      ON s.id  = e.section_id
            LEFT JOIN subsections ss  ON ss.id = e.subsection_id
            LEFT JOIN employees ap    ON ap.id = a.appraiser_id
            LEFT JOIN appraisal_cycles ac ON ac.id = a.appraisal_cycle_id
            -- Collapse repeat edits: the newest row per (appraisal, indicator)
            -- wins. Identical rule to AppraisalReportService, so a score revised
            -- after submission counts once, at its final value, everywhere.
            JOIN (
                SELECT s2.employee_appraisal_id, s2.performance_indicator_id, s2.score
                FROM appraisal_scores s2
                LEFT JOIN appraisal_scores newer
                  ON newer.employee_appraisal_id = s2.employee_appraisal_id
                 AND newer.performance_indicator_id = s2.performance_indicator_id
                 AND (newer.updated_at > s2.updated_at
                      OR (newer.updated_at = s2.updated_at AND newer.id > s2.id))
                WHERE newer.id IS NULL
            ) newest ON newest.employee_appraisal_id = a.id
            JOIN performance_indicators pi ON pi.id = newest.performance_indicator_id
        ";
    }

    /**
     * The display name expression.
     *
     * Used both in SELECT and in GROUP BY because MySQL's ONLY_FULL_GROUP_BY
     * refuses to group by a SELECT alias - the alias has to be repeated as the
     * underlying expression, so it is defined once here and reused.
     */
    private const NAME_EXPR = "TRIM(CONCAT_WS(' ', e.first_name, e.last_name, e.surname))";

    /**
     * Dimension (non-aggregate) columns of the `scored` table.
     *
     * Kept separate from the score aggregates so an aggregating query can
     * project its OWN sums. A combined projection cannot be reused for a
     * different SELECT list - the aggregates are already fixed in place.
     *
     * The name column is appended at call time via nameColumns() rather than
     * inline here: a class constant is a plain string, so `self::NAME_EXPR`
     * written inside one would be emitted into the SQL verbatim.
     */
    private const DIMENSIONS = "
        a.id AS appraisal_id,
        e.id AS employee_pk,
        e.employee_id AS employee_code,
        e.employee_type,
        a.status,
        a.appraisal_cycle_id AS cycle_id,
        ac.name AS cycle_name,
        ac.start_date,
        ac.end_date,
        a.submitted_at,
        e.department_id,
        d.name  AS department_name,
        e.section_id,
        s.name  AS section_name,
        e.subsection_id,
        ss.name AS subsection_name,
        TRIM(CONCAT_WS(' ', ap.first_name, ap.last_name, ap.surname)) AS appraiser_name
    ";

    /**
     * DIMENSIONS with the display-name column spliced in.
     */
    private function nameColumns(): string
    {
        return self::NAME_EXPR . ' AS employee_name, ' . self::DIMENSIONS;
    }

    /**
     * Score totals, aggregated to whatever grain the caller groups by.
     *
     * Exposed as a fragment so every slice - summary, trend, department,
     * employee - sums the exact same expressions. That is what makes the parts
     * reconcile to the whole.
     */
    private const SCORE_TOTALS = "
        COALESCE(SUM(newest.score), 0) AS total_score,
        COALESCE(SUM(pi.max_score), 0) AS total_max_score,
        ROUND(SUM(newest.score) / NULLIF(SUM(pi.max_score), 0) * 100, 1) AS percentage
    ";


    /**
     * Build the WHERE clause: the caller's scope, plus any report filters.
     *
     * @param  array<string,mixed> $filters
     * @return array{0:string,1:array<int,mixed>}
     */
    private function where(array $filters = []): array
    {
        // The scope clause and its params MUST travel together - dropping the
        // params leaves an unbound placeholder, which mysqli rejects loudly
        // (a good outcome, but an ugly one).
        [$scopeWhere, $params] = $this->scope->scopedWhere();

        $clauses = [$scopeWhere];

        // A status filter is a LIST, because "all stages except rejected" is a
        // question this page must be able to answer. Unknown values are dropped
        // rather than passed through, so a typo cannot manufacture a status.
        $statuses = array_values(array_filter(
            array_map('strval', (array) ($filters['status'] ?? [])),
            static fn (string $s): bool => in_array($s, AppraisalReportService::FILTERABLE_STATUSES, true)
        ));
        if ($statuses !== []) {
            $clauses[] = 'a.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            foreach ($statuses as $s) {
                $params[] = $s;
            }
        }

        // $column is qualified with its table so the predicate is unambiguous in
        // the grouped queries, where several joins expose a `name` column.
        foreach ([
            'cycle_id'      => 'a.appraisal_cycle_id',
            'department_id' => 'e.department_id',
            'section_id'    => 'e.section_id',
            'subsection_id' => 'e.subsection_id',
        ] as $filter => $column) {
            $id = (int) ($filters[$filter] ?? 0);
            if ($id > 0) {
                $clauses[] = "{$column} = ?";
                $params[] = $id;
            }
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $clauses[] = '(' . self::NAME_EXPR . ' LIKE ? OR e.employee_id LIKE ? OR ac.name LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * Run a query over the `scored` derived table.
     *
     * $columns is the caller's OWN projection: aggregating slices supply the
     * dimensions they group by plus SCORE_TOTALS, so the sums land at the right
     * grain. The tail carries GROUP BY / HAVING / ORDER BY.
     *
     * @param string $extraWhere Extra AND-ed predicate, always a literal
     *                           (caller-supplied values are NEVER passed here).
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    private function query(string $columns, string $tail, array $filters, string $extraWhere = ''): array
    {
        $sql = $this->buildSql($columns, $tail, $filters, $extraWhere);
        $stmt = $this->db->prepare($sql[0]);
        // A broad-scope caller with no filters produces a WHERE with no
        // placeholders. bind_param() rejects an empty type string outright
        // (ValueError), so the call is skipped entirely in that case.
        if ($sql[1] !== []) {
            $stmt->bind_param($this->types($sql[1]), ...$sql[1]);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows ?: [];
    }

    /**
     * Assemble a full statement and its bound parameters.
     *
     * @param  array<string,mixed> $filters
     * @return array{0:string,1:array<int,mixed>}
     */
    private function buildSql(string $columns, string $tail, array $filters, string $extraWhere = ''): array
    {
        [$where, $params] = $this->where($filters);
        if ($extraWhere !== '') {
            $where .= ' AND ' . $extraWhere;
        }
        return ['SELECT ' . $columns . $this->baseFrom() . " WHERE {$where} {$tail}", $params];
    }

    /**
     * mysqli bind types derived from the actual bound values.
     *
     * Types must mirror the VALUES, not the column. A cycle id and a staff
     * number are both strings in the source data, and binding a numeric-looking
     * code as 'i' silently truncates a leading zero.
     *
     * @param array<int,mixed> $params
     */
    private function types(array $params): string
    {
        $types = '';
        foreach ($params as $p) {
            $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
        }
        return $types;
    }

    /**
     * Ranked lists for the filter dropdowns.
     *
     * Narrowed to the caller's scope so a section head is never offered a
     * department they cannot filter into - offering it would be a control that
     * silently does nothing.
     */
    public function options(): array
    {
        $cycles = $this->query(
            $this->nameColumns(),
            'GROUP BY cycle_id, cycle_name ORDER BY start_date DESC',
            []
        );

        $types = [];
        foreach ($this->query(
            // Qualified: the appraiser join (ap) also carries an employee_type,
            // so the bare column name is ambiguous in the field list.
            'e.employee_type',
            'GROUP BY e.employee_type ORDER BY e.employee_type',
            [],
            'e.employee_type IS NOT NULL'
        ) as $r) {
            $types[] = [
                'value' => (string) $r['employee_type'],
                'label' => ucfirst((string) $r['employee_type']),
            ];
        }

        return [
            'statuses'       => AppraisalReportService::FILTERABLE_STATUSES,
            'cycles'         => array_map(static fn (array $c): array => [
                'id'         => (int) ($c['cycle_id'] ?? 0),
                'name'       => (string) ($c['cycle_name'] ?? 'Unassigned'),
                'start_date' => $c['start_date'] ?? null,
                'end_date'   => $c['end_date'] ?? null,
            ], $cycles),
            'departments'    => $this->ref('department_id', 'd.name'),
            'sections'       => $this->ref('section_id', 's.name'),
            'subsections'    => $this->ref('subsection_id', 'ss.name'),
            'employee_types' => $types,
        ];
    }

    /**
     * Distinct values of one unit column, limited to the caller's scope.
     *
     * @param string $idCol   employees-side foreign key, e.g. 'department_id'
     * @param string $nameCol matching lookup-table name column
     * @return array<int,array{id:int,name:string}>
     */
    private function ref(string $idCol, string $nameCol): array
    {
        $rows = $this->query(
            "e.{$idCol} AS unit_id, {$nameCol} AS unit_name",
            "GROUP BY e.{$idCol}, {$nameCol} ORDER BY {$nameCol} ASC",
            [],
            "e.{$idCol} IS NOT NULL AND {$nameCol} IS NOT NULL"
        );
        return array_map(static fn (array $r): array => [
            'id'   => (int) $r['unit_id'],
            'name' => (string) $r['unit_name'],
        ], $rows);
    }

    /**
     * Headline KPIs.
     *
     * `average_percentage` is score-weighted (SUM/SUM) so it matches every other
     * figure on the page; the mean of per-appraisal percentages would drift from
     * them and quietly contradict the department table underneath.
     */
    public function summary(array $filters = []): array
    {
        // The tail is appended straight after the WHERE, so 'GROUP BY 1' alone
        // collapses the whole set to a single summary row.
        // No GROUP BY: every selected column is an aggregate, so MySQL returns
        // exactly one row. `GROUP BY 1` would resolve to the first alias and be
        // rejected under ONLY_FULL_GROUP_BY.
        //
        // COUNT(DISTINCT a.id), never COUNT(*): the join fans one appraisal out
        // to one row per scored indicator, so COUNT(*) would report the number
        // of SCORE LINES and claim 124 appraisals where there are 39.
        $row = $this->query(
            'COUNT(DISTINCT a.id) AS scored_appraisals,
             COUNT(DISTINCT e.id) AS employees_scored,
             COUNT(DISTINCT a.appraisal_cycle_id) AS cycles_covered,'
            . self::SCORE_TOTALS,
            '',
            $filters
        )[0] ?? [];

        $scored = (int) ($row['scored_appraisals'] ?? 0);
        $sum = (float) ($row['total_score'] ?? 0);
        $max = (float) ($row['total_max_score'] ?? 0);

        $bands = ['needs_improvement' => 0, 'meets' => 0, 'strong' => 0, 'exemplary' => 0];
        foreach ($this->bands($filters) as $b) {
            $bands[(string) $b['band']] = (int) $b['count'];
        }

        return [
            'scored_appraisals'  => $scored,
            'employees_scored'   => (int) ($row['employees_scored'] ?? 0),
            'cycles_covered'     => (int) ($row['cycles_covered'] ?? 0),
            'total_score'        => round($sum, 2),
            'total_max_score'    => round($max, 2),
            'average_percentage' => $max > 0 ? round($sum / $max * 100, 1) : null,
            'exemplary'          => $bands['exemplary'],
            'strong_performer'   => $bands['strong'],
            'meets_expectations' => $bands['meets'],
            'needs_improvement'  => $bands['needs_improvement'],
        ];
    }

    /**
     * Count scored appraisals per performance band.
     *
     * Needs a derived table because the CASE reads the `percentage` alias, and
     * MySQL will not let a SELECT list reference an alias defined alongside it in
     * the same list. The inner query therefore reduces to one row per appraisal
     * first, and the outer query buckets those rows.
     *
     * @param  array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    private function bands(array $filters): array
    {
        [$where, $params] = $this->where($filters);
        $inner = 'SELECT ' . self::SCORE_TOTALS . $this->baseFrom()
               . " WHERE {$where} GROUP BY a.id";
        $sql = "SELECT CASE
                        WHEN percentage >= 90 THEN 'exemplary'
                        WHEN percentage >= 75 THEN 'strong'
                        WHEN percentage >= 50 THEN 'meets'
                        ELSE 'needs_improvement'
                    END AS band,
                    COUNT(*) AS count
                FROM ({$inner}) pct
                GROUP BY band";

        $stmt = $this->db->prepare($sql);
        if ($params !== []) {
            $stmt->bind_param($this->types($params), ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows ?: [];
    }

    /**
     * Score trend across appraisal cycles, OLDEST first.
     *
     * Chart axes read left-to-right, so ordering oldest-first here saves the
     * frontend from reversing a series that arrives newest-first - and getting
     * that backwards would render a steadily improving company as a decline.
     */
    public function trends(array $filters = []): array
    {
        $points = [];
        foreach ($this->query(
            "a.appraisal_cycle_id AS cycle_id, ac.name AS cycle_name,
             MIN(ac.start_date) AS min_start, MAX(ac.end_date) AS max_end,
             COUNT(DISTINCT a.id) AS appraisals,
             COUNT(DISTINCT e.id) AS employees,"
            . self::SCORE_TOTALS,
            'GROUP BY a.appraisal_cycle_id, ac.name
             ORDER BY min_start ASC, a.appraisal_cycle_id ASC',
            $filters
        ) as $r) {
            $max = (float) $r['total_max_score'];
            $points[] = [
                'cycle_id'   => (int) $r['cycle_id'],
                'label'      => (string) $r['cycle_name'],
                'start_date' => $r['min_start'] ?? null,
                'end_date'   => $r['max_end'] ?? null,
                'appraisals' => (int) $r['appraisals'],
                'employees'  => (int) $r['employees'],
                'percentage' => $max > 0 ? round((float) $r['total_score'] / $max * 100, 1) : null,
            ];
        }
        return $points;
    }

    /**
     * Average score per organisational unit - department, section or subsection.
     *
     * The SAME method serves all three tiers so the three lists are guaranteed
     * to use identical maths. Units with no points available are dropped rather
     * than shown as 0%: a department nobody has appraised yet is not a failing
     * department, and reporting it as one would be a false finding.
     *
     * @return array<int,array<string,mixed>>
     */
    public function byUnit(string $tier, array $filters = []): array
    {
        // $column is the employees-side foreign key; $alias is the lookup table
        // joined in baseFrom() that gives it a human-readable name.
        [$column, $alias] = match ($tier) {
            'department' => ['department_id', 'd'],
            'section'    => ['section_id', 's'],
            'subsection' => ['subsection_id', 'ss'],
            default      => ['department_id', 'd'],
        };

        $rows = $this->query(
            "e.{$column} AS unit_id, {$alias}.name AS unit_name,
             COUNT(DISTINCT a.id) AS appraisals,
             COUNT(DISTINCT e.id) AS employees,"
            . self::SCORE_TOTALS,
            "GROUP BY e.{$column}, {$alias}.name",
            $filters,
            "e.{$column} IS NOT NULL AND {$alias}.name IS NOT NULL"
        );

        $out = [];
        foreach ($rows as $r) {
            $max = (float) $r['total_max_score'];
            if ($max <= 0) {
                continue;
            }
            $out[] = [
                'id'         => (int) $r['unit_id'],
                'name'       => (string) $r['unit_name'],
                'appraisals' => (int) $r['appraisals'],
                'employees'  => (int) $r['employees'],
                'percentage' => round((float) $r['total_score'] / $max * 100, 1),
            ];
        }

        // Best first. Ties broken by volume then name so the order is stable
        // between loads rather than shuffling on every render.
        usort($out, static fn (array $a, array $b): int =>
            [$b['percentage'], $b['appraisals'], $a['name']] <=> [$a['percentage'], $a['appraisals'], $b['name']]);
        return $out;
    }

    /**
     * Average score per workflow status.
     *
     * @return array<int,array<string,mixed>>
     */
    public function byStatus(array $filters = []): array
    {
        $rows = $this->query(
            'a.status, COUNT(DISTINCT a.id) AS count',
            'GROUP BY a.status ORDER BY count DESC',
            $filters
        );
        return array_map(static fn (array $r): array => [
            'status' => (string) $r['status'],
            'label'  => ucwords(str_replace('_', ' ', (string) $r['status'])),
            'count'  => (int) $r['count'],
        ], $rows);
    }

    /**
     * Best and worst performers, ranked across everyone in scope.
     *
     * Only employees with at least MIN_APPRAISALS appraisals can appear. Naming
     * a single 90% appraisal as the company's "best performer" would be an
     * artefact of one lucky cycle, not a finding.
     */
    public function performers(array $filters = [], int $limit = 10): array
    {
        $rows = $this->query(
            'e.id AS employee_pk, e.employee_id AS employee_code,'
            . self::NAME_EXPR . ' AS employee_name,
             e.employee_type, d.name AS department_name, s.name AS section_name,
             COUNT(DISTINCT a.id) AS appraisals,'
            . self::SCORE_TOTALS,
            'GROUP BY e.id, e.employee_id, ' . self::NAME_EXPR . ', e.employee_type,
                      d.name, s.name
             HAVING SUM(pi.max_score) > 0 AND COUNT(DISTINCT a.id) >= ' . self::MIN_APPRAISALS . '
             ORDER BY (SUM(newest.score) / NULLIF(SUM(pi.max_score), 0)) DESC, COUNT(DISTINCT a.id) DESC',
            $filters
        );

        $all = [];
        foreach ($rows as $r) {
            $max = (float) $r['total_max_score'];
            $all[] = [
                'employee_pk'   => (int) $r['employee_pk'],
                'employee_code' => (string) $r['employee_code'],
                'employee_name' => (string) $r['employee_name'],
                'employee_type' => $r['employee_type'] ?: null,
                'department'    => $r['department_name'] ?: null,
                'section'       => $r['section_name'] ?: null,
                'appraisals'    => (int) $r['appraisals'],
                'percentage'    => round((float) $r['total_score'] / $max * 100, 1),
            ];
        }

        return [
            // Both ends come from the SAME ranked list, so "best" and "worst" can
            // never be computed against different populations.
            'top'    => array_slice($all, 0, $limit),
            'bottom' => $limit > 0 && count($all) > $limit
                ? array_reverse(array_slice($all, -$limit))
                : [],
        ];
    }

    /**
     * Per-employee summary, paginated and sortable.
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public function employees(array $filters = [], int $page = 1, int $perPage = 25, string $sort = 'percentage', string $dir = 'desc'): array
    {
        $perPage = min(100, max(10, $perPage));
        $orderCol = self::SORTS[$sort] ?? 'avg_pct';
        // dir is validated against a whitelist too: 'desc; DROP TABLE' is a
        // perfectly legal string that is not a sort direction.
        $orderDir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        [$where, $params] = $this->where($filters);

        // The employee table needs its OWN projection: one row per person, not
        // per appraisal, so the score sums must land at that grain for both the
        // display and the sort order.
        $columns = '
            e.id AS employee_pk, e.employee_id AS employee_code,'
            . self::NAME_EXPR . ' AS employee_name,
            e.employee_type, d.name AS department_name, s.name AS section_name,
            COUNT(DISTINCT a.id) AS appraisals,'
            . self::SCORE_TOTALS;

        $groupBy = '
            GROUP BY e.id, e.employee_id, ' . self::NAME_EXPR . ', e.employee_type,
                     d.name, s.name
            HAVING SUM(pi.max_score) > 0
        ';

        // Count and page over the SAME grouped population, by counting rows out of
        // the very same derived table. A separate count query would have to
        // re-derive the "has scores" rule, and that is exactly where a pager drifts.
        $countSql = 'SELECT COUNT(*) AS c FROM ('
            . 'SELECT e.id AS employee_pk ' . $this->baseFrom() . " WHERE {$where} {$groupBy}"
            . ') tally';
        $countStmt = $this->db->prepare($countSql);
        if ($params !== []) {
            $countStmt->bind_param($this->types($params), ...$params);
        }
        $countStmt->execute();
        $countRow = $countStmt->get_result()->fetch_assoc();
        $total = (int) ($countRow['c'] ?? 0);
        $countStmt->close();

        if ($total === 0) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'last_page' => 1];
        }

        // Clamped to the real range, so deleting rows on the last page cannot
        // strand the user on an empty page 4 of 3.
        $page = max(1, min($page, (int) ceil($total / $perPage)));
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT ' . $columns . $this->baseFrom() . " WHERE {$where} {$groupBy}"
             . " ORDER BY {$orderCol} {$orderDir}, employee_name ASC LIMIT ? OFFSET ?";

        $bind = $params;
        $bind[] = $perPage;
        $bind[] = $offset;
        // types() derives a type for EVERY element of $bind, including the two
        // ints just appended - so no extra 'ii' is added here, or the type string
        // and the value count disagree.
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($this->types($bind), ...$bind);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $items = [];
        foreach ($rows as $r) {
            $max = (float) $r['total_max_score'];
            $items[] = [
                'employee_pk'   => (int) $r['employee_pk'],
                'employee_code' => (string) $r['employee_code'],
                'employee_name' => (string) $r['employee_name'],
                'employee_type' => $r['employee_type'] ?: null,
                'department'    => $r['department_name'] ?: null,
                'section'       => $r['section_name'] ?: null,
                'appraisals'    => (int) $r['appraisals'],
                'total_score'   => round((float) $r['total_score'], 2),
                'total_max'     => round($max, 2),
                'percentage'    => $max > 0 ? round((float) $r['total_score'] / $max * 100, 1) : null,
            ];
        }

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Every scored appraisal, one row each, paginated and sortable.
     *
     * This is the grain the page's filters actually describe. The employee
     * summary is an aggregate OF this data, so without this endpoint a filter
     * narrowed the charts but had no table to visibly act on.
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int}
     */
    public function appraisals(array $filters = [], int $page = 1, int $perPage = 25, string $sort = 'submitted_at', string $dir = 'desc'): array
    {
        $perPage = min(200, max(10, $perPage));
        $orderCol = self::REGISTER_SORTS[$sort] ?? 'a.submitted_at';
        // Whitelisted: 'desc; DROP TABLE' is a legal string that is not a sort
        // direction.
        $orderDir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        [$where, $params] = $this->where($filters);

        $columns = '
            a.id AS appraisal_id,
            e.employee_id AS employee_code,'
            . self::NAME_EXPR . ' AS employee_name,
            e.employee_type,
            d.name AS department_name,
            s.name AS section_name,
            ss.name AS subsection_name,
            ac.name AS cycle_name,
            a.status,
            a.submitted_at,'
            . self::SCORE_TOTALS;

        // Every non-aggregated column is repeated in the GROUP BY: under
        // ONLY_FULL_GROUP_BY, grouping by a.id alone is not enough once joined
        // tables contribute their own columns to the projection.
        $groupBy = '
            GROUP BY a.id, e.employee_id, ' . self::NAME_EXPR . ', e.employee_type,
                     d.name, s.name, ss.name, ac.name, a.status, a.submitted_at
            HAVING SUM(pi.max_score) > 0
        ';

        // Counted from the very same derived table so the pager can never
        // advertise rows the page query will not return.
        $countSql = 'SELECT COUNT(*) AS c FROM ('
            . 'SELECT a.id AS appraisal_id ' . $this->baseFrom() . " WHERE {$where} {$groupBy}"
            . ') tally';
        $countStmt = $this->db->prepare($countSql);
        if ($params !== []) {
            $countStmt->bind_param($this->types($params), ...$params);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
        $countStmt->close();

        if ($total === 0) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'last_page' => 1];
        }

        $page = max(1, min($page, (int) ceil($total / $perPage)));
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT ' . $columns . $this->baseFrom() . " WHERE {$where} {$groupBy}"
             . " ORDER BY {$orderCol} {$orderDir}, a.id DESC LIMIT ? OFFSET ?";

        $bind = $params;
        $bind[] = $perPage;
        $bind[] = $offset;
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($this->types($bind), ...$bind);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $items = [];
        foreach ($rows as $r) {
            $max = (float) $r['total_max_score'];
            $status = (string) $r['status'];
            $items[] = [
                'appraisal_id'   => (int) $r['appraisal_id'],
                'employee_code'  => (string) $r['employee_code'],
                'employee_name'  => (string) $r['employee_name'],
                'employee_type'  => $r['employee_type'] ?: null,
                'department'     => $r['department_name'] ?: null,
                'section'        => $r['section_name'] ?: null,
                'subsection'     => $r['subsection_name'] ?: null,
                'cycle'          => $r['cycle_name'] ?: null,
                'status'         => $status,
                'status_label'   => ucwords(str_replace('_', ' ', $status)),
                'submitted_at'   => $r['submitted_at'] ?: null,
                'total_score'    => round((float) $r['total_score'], 2),
                'total_max'      => round($max, 2),
                'percentage'     => $max > 0 ? round((float) $r['total_score'] / $max * 100, 1) : null,
            ];
        }

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Plain-language findings derived from the same aggregates the page shows.
     *
     * Every sentence is generated from a number that is already on screen, so an
     * insight can never assert something the charts do not show.
     */
    public function insights(array $filters = []): array
    {
        $s = $this->summary($filters);
        $out = [];

        if ($s['scored_appraisals'] === 0) {
            return ['No scored appraisals fall within the current scope and filters.'];
        }

        if ($s['average_percentage'] !== null) {
            $pct = $s['average_percentage'];
            $out[] = sprintf(
                'Average performance across %d scored appraisal(s) is %s%%.',
                $s['scored_appraisals'],
                $pct
            );

            if ($pct >= 75) {
                $out[] = 'Performance is strong overall and sits in the "strong" band or above.';
            } elseif ($pct < 50) {
                $out[] = 'Average performance is below the 50% threshold - the cohort needs review.';
            }
        }

        $total = $s['scored_appraisals'];
        if ($s['needs_improvement'] > 0) {
            $out[] = sprintf(
                '%d appraisal(s) (%s%%) scored below 50%% and need attention.',
                $s['needs_improvement'],
                round($s['needs_improvement'] / $total * 100)
            );
        }

        // Cycle-over-cycle movement, oldest to newest. Needs two points before a
        // direction can be claimed at all.
        $points = $this->trends($filters);
        $scored = array_values(array_filter($points, static fn (array $p): bool => $p['percentage'] !== null));
        if (count($scored) >= 2) {
            $first = $scored[0];
            $last = $scored[count($scored) - 1];
            $delta = round($last['percentage'] - $first['percentage'], 1);
            $word = $delta > 0 ? 'improved' : ($delta < 0 ? 'declined' : 'held steady');
            $out[] = sprintf(
                'Average score %s by %s points from %s (%s%%) to %s (%s%%).',
                $word,
                abs($delta),
                $first['label'],
                $first['percentage'],
                $last['label'],
                $last['percentage']
            );
        }

        // The widest spread between the best and worst unit.
        $departments = $this->byUnit('department', $filters);
        if (count($departments) >= 2) {
            $best = $departments[0];
            $worst = $departments[count($departments) - 1];
            $out[] = sprintf(
                '%s leads at %s%%, while %s trails at %s%% - a gap of %s points.',
                $best['name'],
                $best['percentage'],
                $worst['name'],
                $worst['percentage'],
                round($best['percentage'] - $worst['percentage'], 1)
            );
        }

        return $out;
    }

    /**
     * Flat CSV of the appraisal register, honouring the active filters.
     *
     * Mirrors the table on screen column for column. Streams every matching
     * appraisal, not just the current page: a report export that silently stops
     * at 25 rows is worse than no export.
     */
    public function exportRegister(array $filters = []): string
    {
        $rows = $this->query(
            'a.id AS appraisal_id,
             e.employee_id AS employee_code,'
            . self::NAME_EXPR . ' AS employee_name,
             e.employee_type, d.name AS department_name, s.name AS section_name,
             ss.name AS subsection_name, ac.name AS cycle_name, a.status, a.submitted_at,'
            . self::SCORE_TOTALS,
            'GROUP BY a.id, e.employee_id, ' . self::NAME_EXPR . ', e.employee_type,
                      d.name, s.name, ss.name, ac.name, a.status, a.submitted_at
             HAVING SUM(pi.max_score) > 0
             ORDER BY a.submitted_at DESC, a.id DESC',
            $filters
        );

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, [
            'Appraisal ID', 'Staff No', 'Employee', 'Type', 'Department', 'Section',
            'Subsection', 'Cycle', 'Status', 'Submitted', 'Score', 'Out Of', 'Percentage',
        ]);
        foreach ($rows as $r) {
            $max = (float) $r['total_max_score'];
            fputcsv($handle, [
                (int) $r['appraisal_id'],
                $r['employee_code'],
                $r['employee_name'],
                $r['employee_type'] ?: '',
                $r['department_name'] ?: '',
                $r['section_name'] ?: '',
                $r['subsection_name'] ?: '',
                $r['cycle_name'] ?: '',
                ucwords(str_replace('_', ' ', (string) $r['status'])),
                $r['submitted_at'] ?: '',
                round((float) $r['total_score'], 2),
                round($max, 2),
                $max > 0 ? round((float) $r['total_score'] / $max * 100, 1) : '',
            ]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);
        return $csv;
    }
}

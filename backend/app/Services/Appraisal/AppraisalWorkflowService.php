<?php

declare(strict_types=1);

namespace App\Services\Appraisal;

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\OrgScope;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\Security\SecurityEventService;

/** Performance appraisal workflow and scope service. */
class AppraisalWorkflowService
{
    private \mysqli $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function currentEmployee(): ?array
    {
        $userId = Auth::getInstance()->id();
        if ($userId <= 0) {
            return null;
        }

        $sql = "SELECT e.*, d.name AS department_name, s.name AS section_name,
                       ss.name AS subsection_name
                FROM users u
                JOIN employees e ON e.employee_id = u.employee_id
                LEFT JOIN departments d ON d.id = e.department_id
                LEFT JOIN sections s ON s.id = e.section_id
                LEFT JOIN subsections ss ON ss.id = e.subsection_id
                WHERE u.id = ? AND u.is_active = 1 AND e.employee_status = 'active'
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return $row;
        }

        // Compatibility fallback for the single legacy account without a
        // business-number link; the email must resolve to one active employee.
        $sql = str_replace(
            ['e.employee_id = u.employee_id', ' LIMIT 1'],
            ['e.email = u.email', ''],
            $sql
        );
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $matches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return count($matches) === 1 ? $matches[0] : null;
    }

    public function currentRole(): string
    {
        return strtolower((string) (Auth::getInstance()->role() ?: ($_SESSION['user_role'] ?? '')));
    }

    public function isBroadScope(): bool
    {
        return in_array($this->currentRole(), ['hr_manager', 'super_admin', 'managing_director', 'director', 'md'], true);
    }

    public function isApprover(): bool
    {
        return $this->isBroadScope()
            || in_array($this->currentRole(), ['dept_head', 'head_of_department', 'department_head'], true)
            || Auth::getInstance()->hasPermission('performance', 'approve');
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private function scopeClause(): array
    {
        if ($this->isBroadScope()) {
            return ['1=1', []];
        }
        $scope = OrgScope::current();
        $departmentId = $scope['department_id'] ?? null;
        if ($departmentId === null) {
            return ['1=0', []];
        }

        $role = $this->currentRole();
        $sectionId = $scope['section_id'] ?? null;
        $subsectionId = $scope['subsection_id'] ?? null;
        $clauses = ['e.department_id = ?'];
        $params = [(int) $departmentId];
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

    private const ROLE_LEVELS = [
        'super_admin' => 110, 'managing_director' => 100, 'md' => 100,
        'director' => 90, 'hr_manager' => 85,
        'dept_head' => 80, 'head_of_department' => 80, 'department_head' => 80, 'head' => 80,
        'manager' => 70, 'section_head' => 60, 'section_manager' => 60,
        'sub_section_head' => 50, 'subsection_head' => 50,
        'senior' => 40, 'officer' => 30, 'staff' => 20, 'intern' => 10,
    ];

    private function roleLevel(string $role): int
    {
        return self::ROLE_LEVELS[strtolower($role)] ?? 0;
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private function targetScopeClause(array $actor): array
    {
        $clauses = ['e.department_id = ?'];
        $params = [(int) $actor['department_id']];
        $role = $this->currentRole();
        if ($role === 'section_head' && $actor['section_id'] !== null) {
            $clauses[] = 'e.section_id = ?';
            $params[] = (int) $actor['section_id'];
        } elseif ($role === 'sub_section_head' && $actor['section_id'] !== null && $actor['subsection_id'] !== null) {
            $clauses[] = 'e.section_id = ?';
            $params[] = (int) $actor['section_id'];
            $clauses[] = 'e.subsection_id = ?';
            $params[] = (int) $actor['subsection_id'];
        } elseif ($role === 'manager' && $actor['section_id'] !== null) {
            $clauses[] = 'e.section_id = ?';
            $params[] = (int) $actor['section_id'];
        }
        return [implode(' AND ', $clauses), $params];
    }

    private function canSuperviseTarget(array $target): bool
    {
        $actor = $this->currentEmployee();
        if (!$actor) {
            return $this->isBroadScope();
        }
        $targetEmployeeId = (int) ($target['employee_pk'] ?? $target['id'] ?? $target['employee_id'] ?? 0);
        if ($targetEmployeeId <= 0 || $targetEmployeeId === (int) $actor['id']) {
            return false;
        }
        if ($this->isBroadScope()) {
            return true;
        }
        $actorLevel = $this->roleLevel($this->currentRole());
        $targetLevel = $this->roleLevel((string) ($target['employee_type'] ?? ''));
        // Department heads supervise section/subsection heads, not officers or peers.
        if ($actorLevel >= 80 && $actorLevel < 100 && ($targetLevel < 50 || $targetLevel >= 80)) {
            return false;
        }
        if ($targetLevel >= $actorLevel) {
            return false;
        }
        [$where, $params] = $this->targetScopeClause($actor);
        $stmt = $this->db->prepare("SELECT 1 FROM employees e WHERE e.id = ? AND {$where} LIMIT 1");
        $values = array_merge([$targetEmployeeId], $params);
        $stmt->bind_param(str_repeat('i', count($values)), ...$values);
        $stmt->execute();
        $allowed = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        return $allowed;
    }

    private function canAccessRegularTarget(array $target): bool
    {
        if (!$this->canSuperviseTarget($target)) {
            return false;
        }
        if ($this->isBroadScope()) {
            return true;
        }
        $actor = $this->currentEmployee();
        return $actor && (int) ($target['appraiser_id'] ?? 0) === (int) $actor['id'];
    }

    /**
     * Public wrapper around the private canReviewTarget().
     *
     * AppraisalReportService reuses the review-queue visibility rules rather
     * than re-implementing them, but the method itself is private. This exposes
     * it deliberately instead of widening the original to `public`, so the
     * internal callers keep reading as review-queue internals.
     *
     * @param  array<string,mixed> $row A row shaped like the appraisal queue
     *                                  rows (employee_pk, employee_type,
     *                                  escalation_level, ...).
     */
    public function mayReviewRow(array $row): bool
    {
        return $this->canReviewTarget($row);
    }

    private function canReviewTarget(array $target): bool
    {
        $actor = $this->currentEmployee();
        if (!$actor) {
            return $this->isBroadScope();
        }
        $targetEmployeeId = (int) ($target['employee_pk'] ?? $target['id'] ?? $target['employee_id'] ?? 0);
        if ($targetEmployeeId <= 0 || $targetEmployeeId === (int) $actor['id']) {
            return false;
        }
        if ($this->isBroadScope()) {
            return true;
        }
        $escalationLevel = strtolower((string) ($target['escalation_level'] ?? ''));
        if ($escalationLevel === 'managing_director' && !in_array($this->currentRole(), ['hr_manager', 'super_admin', 'managing_director', 'director', 'md'], true)) {
            return false;
        }
        [$where, $params] = $this->targetScopeClause($actor);
        $stmt = $this->db->prepare("SELECT 1 FROM employees e WHERE e.id = ? AND {$where} LIMIT 1");
        $values = array_merge([$targetEmployeeId], $params);
        $stmt->bind_param(str_repeat('i', count($values)), ...$values);
        $stmt->execute();
        $allowed = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        return $allowed;
    }

    private function assertReviewAccess(int $appraisalId): array
    {
        $appraisal = $this->assertRawAppraisal($appraisalId);
        if (!$this->isApprover() || !$this->canReviewTarget($appraisal)) {
            AuditService::getInstance()->log(AuditService::MODULE_PERFORMANCE, AuditService::ACTION_APPRAISAL_ACCESS_DENIED, 'Appraisal review access denied by organizational scope.', ['target_type' => 'EmployeeAppraisal', 'target_id' => $appraisalId, 'status' => AuditService::STATUS_DENIED]);
            throw new \DomainException('Appraisal not found.');
        }
        return $appraisal;
    }

    private function assertRawAppraisal(int $appraisalId): array
    {
        $stmt = $this->db->prepare("SELECT a.*, e.id AS employee_pk, e.employee_type, e.department_id, e.section_id, e.subsection_id FROM employee_appraisals a JOIN employees e ON e.id = a.employee_id WHERE a.id = ? LIMIT 1");
        $stmt->bind_param('i', $appraisalId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            SecurityEventService::getInstance()->record(SecurityEventService::IDOR_ENUMERATION, SecurityEventService::SEVERITY_MEDIUM, 45, ['user_id' => Auth::getInstance()->id(), 'resource_type' => 'EmployeeAppraisal', 'resource_id' => $appraisalId, 'response_status' => 404, 'action_taken' => SecurityEventService::ACTION_DENIED]);
            throw new \DomainException('Appraisal not found.');
        }
        return $row;
    }

    private function canDecideTarget(array $target): bool
    {
        if (!$this->isApprover() || !$this->canReviewTarget($target)) {
            return false;
        }
        $level = strtolower((string) ($target['escalation_level'] ?? ''));
        if ($level === 'managing_director' && !in_array($this->currentRole(), ['hr_manager', 'super_admin', 'managing_director', 'director', 'md'], true)) {
            return false;
        }
        return true;
    }

    private function fetchEmployee(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT e.*, d.name AS department_name, s.name AS section_name,
                    ss.name AS subsection_name
             FROM employees e
             LEFT JOIN departments d ON d.id = e.department_id
             LEFT JOIN sections s ON s.id = e.section_id
             LEFT JOIN subsections ss ON ss.id = e.subsection_id
             WHERE e.id = ? AND e.employee_status = 'active' LIMIT 1"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function assertAppraiserPermission(int $appraisalId): array
    {
        $stmt = $this->db->prepare(
            "SELECT a.*, e.id AS employee_pk, e.employee_type, e.department_id, e.section_id, e.subsection_id,
                    e.first_name, e.last_name, e.employee_id AS employee_code,
                    ac.name AS cycle_name, ac.start_date, ac.end_date,
                    ap.first_name AS appraiser_first_name, ap.last_name AS appraiser_last_name
             FROM employee_appraisals a
             JOIN employees e ON e.id = a.employee_id
             LEFT JOIN appraisal_cycles ac ON ac.id = a.appraisal_cycle_id
             LEFT JOIN employees ap ON ap.id = a.appraiser_id
             WHERE a.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $appraisalId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            SecurityEventService::getInstance()->record(
                SecurityEventService::IDOR_ENUMERATION,
                SecurityEventService::SEVERITY_MEDIUM,
                45,
                ['user_id' => Auth::getInstance()->id(), 'resource_type' => 'EmployeeAppraisal', 'resource_id' => $appraisalId, 'response_status' => 404, 'action_taken' => SecurityEventService::ACTION_DENIED]
            );
            throw new \DomainException('Appraisal not found.');
        }
        if (!$this->canAccessRegularTarget($row)) {
            AuditService::getInstance()->log(AuditService::MODULE_PERFORMANCE, AuditService::ACTION_APPRAISAL_ACCESS_DENIED, 'Appraisal access denied by role or appraiser assignment.', ['target_type' => 'EmployeeAppraisal', 'target_id' => $appraisalId, 'status' => AuditService::STATUS_DENIED]);
            throw new \DomainException('Appraisal not found.');
        }
        return $row;
    }

    private function audit(int $appraisalId, string $action, string $description, array $options = []): void
    {
        AuditService::getInstance()->log(AuditService::MODULE_PERFORMANCE, $action, $description, array_merge(['target_type' => 'EmployeeAppraisal', 'target_id' => $appraisalId], $options));
    }

    private function activityNames(string $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', $ids))));
        if (!$ids) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT wo.id, wo.objective AS name, pc.name AS contract_name
             FROM workplan_objectives wo
             LEFT JOIN performance_contracts pc ON pc.id = wo.performance_contract_id
             WHERE wo.id IN ({$ph}) ORDER BY wo.objective"
        );
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows ?: [];
    }

    private function indicatorsForEmployee(int $employeeId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, name, max_score, activity_ids, role, department_id,
                    section_id, subsection_id, is_active
             FROM performance_indicators
             WHERE is_active = 1 AND FIND_IN_SET(?, assigned_to_employee_ids) > 0
             ORDER BY name"
        );
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as &$row) {
            $row['activities'] = $this->activityNames((string) ($row['activity_ids'] ?? ''));
            $row['max_score'] = (float) $row['max_score'];
        }
        unset($row);
        return $rows ?: [];
    }

    private function listEmployees(): array
    {
        $actor = $this->currentEmployee();
        if (!$actor) {
            return [];
        }
        [$where, $params] = $this->scopeClause();
        $types = str_repeat('i', count($params));
        $sql = "SELECT e.id, e.employee_id, e.first_name, e.last_name, e.employee_type,
                       e.department_id, e.section_id, e.subsection_id,
                       d.name AS department_name, s.name AS section_name
                FROM employees e
                LEFT JOIN departments d ON d.id = e.department_id
                LEFT JOIN sections s ON s.id = e.section_id
                WHERE e.employee_status = 'active' AND {$where} AND e.id <> ?";
        $params[] = (int) $actor['id'];
        $types .= 'i';
        $sql .= ' ORDER BY e.first_name, e.last_name';
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // The role hierarchy is applied in a single in-memory pass so the
        // picker does not issue one authorization query per employee (N+1).
        //
        // CRITICAL: this must stay the SAME rule as canSuperviseTarget(), the
        // check create() runs on submit. The two used to disagree: the picker
        // admitted anything ranked below the actor, while canSuperviseTarget()
        // additionally refuses officers and peers for a department head. A dept
        // head's dropdown therefore offered 16 people, 14 of which were then
        // rejected with "Employee not found" on Create - the user was offered
        // a choice that could never succeed. Filtering through the authoritative
        // rule means the dropdown only ever shows people who can actually be
        // appraised.
        $visible = [];
        foreach ($rows ?: [] as $row) {
            if ($this->canSuperviseTarget($row)) {
                $visible[] = $row;
            }
        }
        return $visible;
    }

    private function scoreSummaries(array $rows): array
    {
        $ids = array_values(array_unique(array_map('intval', array_column($rows, 'id'))));
        if (!$ids) {
            return [];
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
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['employee_appraisal_id']] = $row;
        }
        return $map;
    }

    private function decorateRows(array $rows): array
    {
        $summaries = $this->scoreSummaries($rows);
        foreach ($rows as &$row) {
            $row['employee_name'] = trim(($row['first_name'] ?? $row['employee_first_name'] ?? '') . ' ' . ($row['last_name'] ?? $row['employee_last_name'] ?? ''));
            $row['appraiser_name'] = trim(($row['appraiser_first_name'] ?? '') . ' ' . ($row['appraiser_last_name'] ?? ''));
            $row['status_label'] = ucwords(str_replace('_', ' ', (string) $row['status']));
            $row['satisfaction_label'] = $row['employee_comment_date'] === null
                ? 'Awaiting employee feedback'
                : ((int) $row['employee_satisfied'] === 1 ? 'Satisfied' : 'Not satisfied');
            $summary = $summaries[(int) $row['id']] ?? ['total_score' => 0, 'total_max_score' => 0];
            $row['total_score'] = (float) $summary['total_score'];
            $row['total_max_score'] = (float) $summary['total_max_score'];
            $row['score_percentage'] = $row['total_max_score'] > 0
                ? round(($row['total_score'] / $row['total_max_score']) * 100, 1) : 0;
        }
        unset($row);
        return $rows;
    }

    private function baseAppraisalQuery(string $where, array $params, string $types): array
    {
        $sql = "SELECT a.id, a.status, a.employee_id, a.appraiser_id,
                       a.appraisal_cycle_id, a.employee_comment, a.employee_satisfied,
                       a.employee_comment_date, a.supervisors_comment,
                       a.supervisors_comment_date, a.submitted_at, a.created_at,
                       a.escalated_to_dept_head, a.escalation_level, a.escalated_date,
                       a.dept_head_decision, a.dept_head_comment, a.dept_head_decision_date,
                       e.first_name, e.last_name, e.id AS employee_pk, e.employee_id AS employee_code,
                       e.employee_type, e.email AS employee_email,
                       d.name AS department_name, s.name AS section_name,
                       ss.name AS subsection_name,
                       ap.first_name AS appraiser_first_name, ap.last_name AS appraiser_last_name,
                       ac.name AS cycle_name, ac.start_date, ac.end_date
                FROM employee_appraisals a
                JOIN employees e ON e.id = a.employee_id
                LEFT JOIN departments d ON d.id = e.department_id
                LEFT JOIN sections s ON s.id = e.section_id
                LEFT JOIN subsections ss ON ss.id = e.subsection_id
                LEFT JOIN employees ap ON ap.id = a.appraiser_id
                LEFT JOIN appraisal_cycles ac ON ac.id = a.appraisal_cycle_id
                WHERE {$where} ORDER BY a.created_at DESC, a.id DESC";
        $stmt = $this->db->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows ?: [];
    }

    public function list(string $tab): array
    {
        if ($tab === 'regular' && !Auth::getInstance()->hasPermission('performance', 'supervise')) {
            throw new \RuntimeException('You do not have supervisory appraisal permission.');
        }
        if (in_array($tab, ['pending', 'escalated', 'rejected'], true) && !$this->isApprover()) {
            throw new \RuntimeException('You do not have approval permission.');
        }
        [$where, $params] = $this->scopeClause();
        $statusWhere = match ($tab) {
            'regular' => "a.status IN ('draft','awaiting_employee','awaiting_submission')",
            'pending' => "a.status = 'pending_dept_approval'",
            'escalated' => "a.status = 'under_review' AND a.employee_satisfied = 0 AND (a.dept_head_decision IS NULL OR a.dept_head_decision = '')",
            'rejected' => "a.status = 'rejected'",
            default => '1=0',
        };
        $where .= " AND {$statusWhere}";
        $rows = $this->baseAppraisalQuery($where, $params, str_repeat('i', count($params)));
        $filter = $tab === 'regular' ? 'canAccessRegularTarget' : 'canReviewTarget';
        $rows = array_values(array_filter($rows, fn (array $row): bool => $this->{$filter}($row)));
        return $this->decorateRows($rows);
    }

    public function workspace(): array
    {
        if (!Auth::getInstance()->hasPermission('performance', 'supervise')) {
            throw new \RuntimeException('You do not have supervisory appraisal permission.');
        }
        $rows = $this->list('regular');
        $pending = $this->isApprover() ? $this->list('pending') : [];
        $escalated = $this->isApprover() ? $this->list('escalated') : [];
        $rejected = $this->isApprover() ? $this->list('rejected') : [];
        $cycles = $this->db->query("SELECT id, name, start_date, end_date, status FROM appraisal_cycles WHERE status = 'active' ORDER BY start_date DESC")->fetch_all(MYSQLI_ASSOC);
        return [
            'employees' => $this->listEmployees(),
            'cycles' => $cycles ?: [],
            'counts' => ['regular' => count($rows), 'pending' => count($pending), 'escalated' => count($escalated), 'rejected' => count($rejected)],
            'permissions' => [
                'supervise' => Auth::getInstance()->hasPermission('performance', 'supervise'),
                'score' => Auth::getInstance()->hasPermission('performance', 'score'),
                'approve' => $this->isApprover(),
            ],
        ];
    }

    public function detail(int $id, string $tab = 'regular'): array
    {
        if (in_array($tab, ['pending', 'escalated', 'rejected'], true) && !$this->isApprover()) {
            throw new \RuntimeException('You do not have approval permission.');
        }
        $appraisal = $tab === 'regular'
            ? $this->assertAppraiserPermission($id)
            : $this->assertReviewAccess($id);
        $appraisal['employee_name'] = trim(($appraisal['first_name'] ?? '') . ' ' . ($appraisal['last_name'] ?? ''));
        $appraisal['appraiser_name'] = trim(($appraisal['appraiser_first_name'] ?? '') . ' ' . ($appraisal['appraiser_last_name'] ?? ''));
        $appraisal['status_label'] = ucwords(str_replace('_', ' ', (string) $appraisal['status']));
        $indicators = $this->indicatorsForEmployee((int) $appraisal['employee_id']);
        $stmt = $this->db->prepare(
            "SELECT s.id, s.performance_indicator_id, s.score, s.appraiser_comment,
                    s.created_at, s.updated_at
             FROM appraisal_scores s WHERE s.employee_appraisal_id = ?
             ORDER BY s.performance_indicator_id, s.updated_at, s.id"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $scoreRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $byIndicator = [];
        foreach ($scoreRows as $score) {
            $byIndicator[(int) $score['performance_indicator_id']] = $score;
        }
        foreach ($indicators as &$indicator) {
            $score = $byIndicator[(int) $indicator['id']] ?? null;
            $indicator['score'] = $score !== null && $score['score'] !== null ? (float) $score['score'] : null;
            $indicator['appraiser_comment'] = $score['appraiser_comment'] ?? '';
            $indicator['score_id'] = $score['id'] ?? null;
        }
        unset($indicator);
        $total = array_sum(array_map(static fn ($row) => (float) ($row['score'] ?? 0), $indicators));
        $max = array_sum(array_map(static fn ($row) => (float) $row['max_score'], $indicators));
        $appraisal['can_edit_scores'] = (bool) ($actor = $this->currentEmployee())
            && (int) $appraisal['appraiser_id'] === (int) $actor['id']
            && Auth::getInstance()->hasPermission('performance', 'score');
        $appraisal['indicators'] = $indicators;
        $appraisal['scores'] = $scoreRows;
        $appraisal['total_score'] = $total;
        $appraisal['total_max_score'] = $max;
        $appraisal['score_percentage'] = $max > 0 ? round(($total / $max) * 100, 1) : 0;
        $this->audit($id, AuditService::ACTION_APPRAISAL_ACCESSED, 'Supervisor viewed appraisal detail.', ['metadata' => ['employee_id' => (int) $appraisal['employee_id']]]);
        return $appraisal;
    }
    public function create(array $data): int
    {
        $employeeId = (int) ($data['employee_id'] ?? 0);
        $cycleId = (int) ($data['appraisal_cycle_id'] ?? 0);
        if ($employeeId <= 0 || $cycleId <= 0) {
            throw new \InvalidArgumentException('Employee and appraisal cycle are required.');
        }
        $employee = $this->fetchEmployee($employeeId);
        if (!$employee) {
            throw new \InvalidArgumentException('Selected employee does not exist.');
        }
        // Out-of-scope is reported as 404, not 403, ON PURPOSE: a 403 would
        // confirm the employee exists, turning this endpoint into a probe for
        // staff outside your unit. The status must stay 404 - only the wording
        // is fixed. "Employee not found" was technically true but practically
        // useless: the employee came straight from the scope-filtered dropdown
        // moments earlier, so the user could not tell whether they had picked
        // wrong or hit a bug. This says what actually happened and what to do.
        if (!$this->canSuperviseTarget($employee)) {
            throw new \DomainException(
                'That employee is outside your appraisal scope. You can only appraise staff '
                . 'in your own organisational unit - pick someone from the list above.'
            );
        }

        $cycleStmt = $this->db->prepare("SELECT id FROM appraisal_cycles WHERE id = ? AND status = 'active' LIMIT 1");
        $cycleStmt->bind_param('i', $cycleId);
        $cycleStmt->execute();
        $cycle = $cycleStmt->get_result()->fetch_assoc();
        $cycleStmt->close();
        if (!$cycle) {
            throw new \InvalidArgumentException('Select an active appraisal cycle.');
        }

        $dup = $this->db->prepare("SELECT id FROM employee_appraisals WHERE employee_id = ? AND appraisal_cycle_id = ? AND status NOT IN ('cancelled','completed','rejected') LIMIT 1");
        $dup->bind_param('ii', $employeeId, $cycleId);
        $dup->execute();
        $existing = $dup->get_result()->fetch_assoc();
        $dup->close();
        if ($existing) {
            throw new \RuntimeException('An active appraisal already exists for this employee and cycle.');
        }

        $actor = $this->currentEmployee();
        $appraiserId = $actor ? (int) $actor['id'] : 0;
        if ($appraiserId <= 0) {
            throw new \RuntimeException('Your account is not linked to an active employee record.');
        }
        $stmt = $this->db->prepare(
            "INSERT INTO employee_appraisals
             (employee_id, employee_department_id, appraiser_id, appraisal_cycle_id,
              status, employee_satisfied, supervisors_comment, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'draft', 0, '', NOW(), NOW())"
        );
        $departmentId = $employee['department_id'] !== null ? (int) $employee['department_id'] : null;
        $stmt->bind_param('isii', $employeeId, $departmentId, $appraiserId, $cycleId);
        $stmt->execute();
        $id = (int) $this->db->insert_id;
        $stmt->close();
        $this->audit($id, AuditService::ACTION_APPRAISAL_CREATED, 'Created performance appraisal draft.', ['new_values' => ['status' => 'draft', 'employee_id' => $employeeId, 'cycle_id' => $cycleId]]);
        return $id;
    }

    public function saveScores(int $id, array $data): void
    {
        $appraisal = $this->assertAppraiserPermission($id);
        $actor = $this->currentEmployee();
        if (!$actor || (int) $appraisal['appraiser_id'] !== (int) $actor['id']) {
            throw new \RuntimeException('Only the assigned appraiser can save these scores.');
        }
        if (!in_array($appraisal['status'], ['draft', 'awaiting_employee'], true)) {
            throw new \RuntimeException('Scores can only be saved before employee feedback is submitted.');
        }
        $scores = $data['scores'] ?? [];
        $comments = $data['comments'] ?? [];
        $supervisorComment = trim((string) ($data['supervisor_comment'] ?? ''));
        if ($supervisorComment === '') {
            throw new \InvalidArgumentException('Supervisor comment is required.');
        }
        if (mb_strlen($supervisorComment) > 5000) {
            throw new \InvalidArgumentException('Supervisor comments must be 5,000 characters or fewer.');
        }
        if (!is_array($scores) || !$scores) {
            throw new \InvalidArgumentException('Scores are required.');
        }
        if (!is_array($comments)) {
            throw new \InvalidArgumentException('KPI comments must be supplied as a collection.');
        }

        $indicators = [];
        foreach ($this->indicatorsForEmployee((int) $appraisal['employee_id']) as $indicator) {
            $indicators[(int) $indicator['id']] = $indicator;
        }
        $missingIds = array_diff(array_keys($indicators), array_map('intval', array_keys($scores)));
        if ($missingIds) {
            throw new \InvalidArgumentException('A score is required for every assigned KPI.');
        }
        $this->db->begin_transaction();
        try {
            foreach ($scores as $indicatorId => $value) {
                $indicatorId = (int) $indicatorId;
                if (!isset($indicators[$indicatorId])) {
                    throw new \InvalidArgumentException('A selected KPI is not assigned to this employee.');
                }
                if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
                    throw new \InvalidArgumentException("Score for KPI {$indicatorId} must be numeric.");
                }
                $score = (float) $value;
                $max = (float) $indicators[$indicatorId]['max_score'];
                if (!is_finite($score) || $score < 0 || $score > $max) {
                    throw new \InvalidArgumentException("Score for KPI {$indicatorId} must be between 0 and {$max}.");
                }
                $comment = trim((string) ($comments[$indicatorId] ?? ''));
                if (mb_strlen($comment) > 5000) {
                    throw new \InvalidArgumentException('KPI comments must be 5,000 characters or fewer.');
                }
                $existing = $this->db->prepare("SELECT id FROM appraisal_scores WHERE employee_appraisal_id = ? AND performance_indicator_id = ? ORDER BY updated_at DESC, id DESC LIMIT 1");
                $existing->bind_param('ii', $id, $indicatorId);
                $existing->execute();
                $existingRow = $existing->get_result()->fetch_assoc();
                $existing->close();
                if ($existingRow) {
                    $existingId = (int) $existingRow['id'];
                    $stmt = $this->db->prepare("UPDATE appraisal_scores SET score = ?, appraiser_comment = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->bind_param('dsi', $score, $comment, $existingId);
                } else {
                    $stmt = $this->db->prepare("INSERT INTO appraisal_scores (employee_appraisal_id, performance_indicator_id, score, appraiser_comment) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param('iids', $id, $indicatorId, $score, $comment);
                }
                $stmt->execute();
                $stmt->close();
            }
            $stmt = $this->db->prepare("UPDATE employee_appraisals SET supervisors_comment = ?, supervisors_comment_date = NOW(), status = 'awaiting_employee', updated_at = NOW() WHERE id = ? AND status IN ('draft','awaiting_employee')");
            $stmt->bind_param('si', $supervisorComment, $id);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                $statusCheck = $this->db->prepare("SELECT status FROM employee_appraisals WHERE id = ? FOR UPDATE");
                $statusCheck->bind_param('i', $id);
                $statusCheck->execute();
                $current = $statusCheck->get_result()->fetch_assoc();
                $statusCheck->close();
                if (!$current || !in_array($current['status'], ['draft', 'awaiting_employee'], true)) {
                    throw new \RuntimeException('This appraisal no longer accepts score changes.');
                }
            }
            $stmt->close();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        $this->audit($id, AuditService::ACTION_APPRAISAL_SCORE_SAVED, 'Saved appraisal scores and supervisor comment.', ['metadata' => ['score_count' => count($scores), 'status' => 'awaiting_employee']]);
        $employee = $this->fetchEmployee((int) $appraisal['employee_id']);
        if ($employee) {
            $this->notifyEmployee($employee, 'Performance appraisal ready for your feedback', 'Your supervisor has completed the appraisal scoring. Please review it and submit your feedback.', 'info', '/appraisal/my');
        }
    }


    private function assertOwner(int $id): array
    {
        $actor = $this->currentEmployee();
        if (!$actor) {
            throw new \RuntimeException('Your account is not linked to an active employee record.');
        }
        $stmt = $this->db->prepare("SELECT a.*, e.employee_type, e.department_id, e.section_id, e.subsection_id FROM employee_appraisals a JOIN employees e ON e.id = a.employee_id WHERE a.id = ? AND a.employee_id = ? LIMIT 1");
        $actorEmployeeId = (int) $actor['id'];
        $stmt->bind_param('ii', $id, $actorEmployeeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            SecurityEventService::getInstance()->record(SecurityEventService::IDOR_ENUMERATION, SecurityEventService::SEVERITY_MEDIUM, 45, ['user_id' => Auth::getInstance()->id(), 'resource_type' => 'EmployeeAppraisal', 'resource_id' => $id, 'response_status' => 404, 'action_taken' => SecurityEventService::ACTION_DENIED]);
            throw new \DomainException('Appraisal not found.');
        }
        return $row;
    }

    public function myAppraisals(): array
    {
        $actor = $this->currentEmployee();
        if (!$actor) {
            throw new \RuntimeException('Your account is not linked to an active employee record.');
        }
        $stmt = $this->db->prepare(
            "SELECT a.*, ac.name AS cycle_name, ac.start_date, ac.end_date,
                    e.first_name AS first_name, e.last_name AS last_name,
                    e.employee_id AS employee_code, e.employee_type,
                    ap.first_name AS appraiser_first_name, ap.last_name AS appraiser_last_name
             FROM employee_appraisals a JOIN appraisal_cycles ac ON ac.id = a.appraisal_cycle_id
             JOIN employees e ON e.id = a.employee_id
             LEFT JOIN employees ap ON ap.id = a.appraiser_id
             WHERE a.employee_id = ? AND a.status <> 'cancelled'
             ORDER BY ac.start_date DESC, a.id DESC"
        );
        $actorEmployeeId = (int) $actor['id'];
        $stmt->bind_param('i', $actorEmployeeId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as &$row) {
            $row['appraiser_name'] = trim(($row['appraiser_first_name'] ?? '') . ' ' . ($row['appraiser_last_name'] ?? ''));
            $row['employee_name'] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            $row['status_label'] = ucwords(str_replace('_', ' ', $row['status']));
            $row['satisfaction_label'] = $row['employee_comment_date'] === null ? 'Awaiting your feedback' : ((int) $row['employee_satisfied'] === 1 ? 'Satisfied' : 'Not satisfied');
        }
        unset($row);
        return $this->decorateRows($rows ?: []);
    }

    public function myDetail(int $id): array
    {
        $appraisal = $this->assertOwner($id);
        $indicators = $this->indicatorsForEmployee((int) $appraisal['employee_id']);
        $stmt = $this->db->prepare("SELECT performance_indicator_id, score, appraiser_comment FROM appraisal_scores WHERE employee_appraisal_id = ? ORDER BY performance_indicator_id, updated_at, id");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $scoreRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $byIndicator = [];
        foreach ($scoreRows as $score) {
            $byIndicator[(int) $score['performance_indicator_id']] = $score;
        }
        foreach ($indicators as &$indicator) {
            $score = $byIndicator[(int) $indicator['id']] ?? null;
            $indicator['score'] = $score !== null && $score['score'] !== null ? (float) $score['score'] : null;
            $indicator['appraiser_comment'] = $score['appraiser_comment'] ?? '';
        }
        unset($indicator);
        $max = array_sum(array_map(static fn ($row) => (float) $row['max_score'], $indicators));
        $total = array_sum(array_map(static fn ($row) => (float) ($row['score'] ?? 0), $indicators));
        $appraisal['indicators'] = $indicators;
        $appraisal['total_score'] = $total;
        $appraisal['total_max_score'] = $max;
        $appraisal['score_percentage'] = $max > 0 ? round(($total / $max) * 100, 1) : 0;
        return $appraisal;
    }

    private function requiresManagingDirectorEscalation(array $employee): bool
    {
        return in_array(strtolower((string) ($employee['employee_type'] ?? '')), [
            'section_head', 'section_manager', 'dept_head', 'head_of_department',
            'department_head', 'head', 'managing_director', 'director', 'md',
        ], true);
    }

    private function escalationRecipient(array $employee): ?array
    {
        $employeeId = (int) ($employee['id'] ?? 0);
        if ($this->requiresManagingDirectorEscalation($employee)) {
            $sql = "SELECT e.* FROM employees e WHERE e.employee_status = 'active' AND e.employee_type IN ('managing_director','director','md') AND e.id <> ? ORDER BY e.id LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('i', $employeeId);
        } else {
            $sql = "SELECT e.* FROM employees e WHERE e.employee_status = 'active' AND e.department_id = ? AND e.employee_type IN ('dept_head','head_of_department','department_head','manager','head') ORDER BY e.id LIMIT 1";
            $employeeDepartmentId = (int) ($employee['department_id'] ?? 0);
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('i', $employeeDepartmentId);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return $row;
        }
        $sql = "SELECT e.* FROM employees e WHERE e.employee_status = 'active' AND e.employee_type = 'hr_manager' AND e.id <> ? ORDER BY e.id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function escalationLevelForRecipient(?array $recipient): string
    {
        if (!$recipient) {
            return 'hr';
        }
        $type = strtolower((string) ($recipient['employee_type'] ?? ''));
        if (in_array($type, ['managing_director', 'director', 'md'], true)) {
            return 'managing_director';
        }
        if ($type === 'hr_manager') {
            return 'hr';
        }
        return 'department_head';
    }

    private function approvalRecipient(array $employee): ?array
    {
        $employeeId = (int) ($employee['id'] ?? 0);
        $type = strtolower((string) ($employee['employee_type'] ?? ''));
        if (in_array($type, ['managing_director', 'director', 'md'], true)) {
            $sql = "SELECT e.* FROM employees e WHERE e.employee_status = 'active' AND e.employee_type IN ('managing_director','director','md') AND e.id <> ? ORDER BY e.id LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('i', $employeeId);
        } else {
            $sql = "SELECT e.* FROM employees e WHERE e.employee_status = 'active' AND e.department_id = ? AND e.employee_type IN ('dept_head','head_of_department','department_head','manager','head') AND e.id <> ? ORDER BY e.id LIMIT 1";
            $departmentId = (int) ($employee['department_id'] ?? 0);
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('ii', $departmentId, $employeeId);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return $row;
        }
        $sql = "SELECT e.* FROM employees e WHERE e.employee_status = 'active' AND e.employee_type = 'hr_manager' AND e.id <> ? ORDER BY e.id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function notifyEmployee(array $employee, string $title, string $message, string $type, string $link = '/appraisal/my'): void
    {
        $employeeId = (int) $employee['id'];
        $stmt = $this->db->prepare("SELECT u.id, u.email FROM users u JOIN employees e ON e.employee_id = u.employee_id WHERE e.id = ? AND u.is_active = 1 LIMIT 1");
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$user) {
            return;
        }
        try {
            NotificationService::getInstance()->sendInApp((int) $user['id'], $title, $message, $type, $link);
            if (!empty($user['email'])) {
                NotificationService::getInstance()->sendEmail($user['email'], $title, '<p>' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>');
            }
        } catch (\Throwable $e) {
            \logger()->warning('Appraisal notification failed', ['employee_id' => (int) $employee['id'], 'error' => $e->getMessage()]);
        }
    }

    public function submitEmployeeFeedback(int $id, array $data): void
    {
        $appraisal = $this->assertOwner($id);
        if ($appraisal['status'] !== 'awaiting_employee') {
            throw new \RuntimeException('Feedback is not currently required for this appraisal.');
        }
        $comment = trim((string) ($data['employee_comment'] ?? ''));
        if ($comment === '') {
            throw new \InvalidArgumentException('Please enter your feedback.');
        }
        if (mb_strlen($comment) > 5000) {
            throw new \InvalidArgumentException('Feedback must be 5,000 characters or fewer.');
        }
        if (!array_key_exists('employee_satisfied', $data)) {
            throw new \InvalidArgumentException('Select whether you are satisfied.');
        }
        $satisfiedValue = $data['employee_satisfied'];
        if (!in_array($satisfiedValue, [0, 1, '0', '1'], true)) {
            throw new \InvalidArgumentException('Select whether you are satisfied.');
        }
        $satisfied = (int) $satisfiedValue;
        $status = $satisfied === 1 ? 'pending_dept_approval' : 'under_review';
        $recipient = $satisfied === 1
            ? $this->approvalRecipient($appraisal)
            : $this->escalationRecipient($appraisal);
        $escalationLevel = $satisfied === 1
            ? null
            : $this->escalationLevelForRecipient($recipient);
        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare("UPDATE employee_appraisals SET employee_comment = ?, employee_satisfied = ?, employee_comment_date = NOW(), status = ?, escalation_level = ?, escalated_to_dept_head = ?, escalated_date = ?, updated_at = NOW() WHERE id = ? AND employee_id = ? AND status = 'awaiting_employee'");
            $escalated = $satisfied === 0 ? 1 : 0;
            $escalatedDate = $satisfied === 0 ? date('Y-m-d H:i:s') : null;
            $appraisalEmployeeId = (int) $appraisal['employee_id'];
            $stmt->bind_param('sissisii', $comment, $satisfied, $status, $escalationLevel, $escalated, $escalatedDate, $id, $appraisalEmployeeId);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                throw new \RuntimeException('This appraisal feedback was already submitted.');
            }
            $stmt->close();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        $action = $satisfied === 1 ? AuditService::ACTION_APPRAISAL_FEEDBACK_SUBMITTED : AuditService::ACTION_APPRAISAL_ESCALATED;
        $this->audit($id, $action, $satisfied === 1 ? 'Employee submitted satisfied feedback.' : 'Employee submitted dissatisfied feedback and escalated the appraisal.', ['new_values' => ['status' => $status, 'employee_satisfied' => $satisfied], 'metadata' => ['comment_present' => true, 'comment_length' => mb_strlen($comment), 'escalation_level' => $escalationLevel]]);
        $employee = $this->fetchEmployee((int) $appraisal['employee_id']);
        if ($recipient) {
            $this->notifyEmployee($recipient, $satisfied === 1 ? 'Appraisal approval required' : 'Appraisal escalation review required', $satisfied === 1 ? 'An employee submitted satisfied appraisal feedback. The appraisal is ready for approval.' : 'An employee submitted dissatisfied appraisal feedback. Please review the escalated appraisal.', $satisfied === 1 ? 'info' : 'warning', '/strategy/performance-appraisals');
        }
        if ($employee && (!$recipient || (int) $recipient['id'] !== (int) $employee['id'])) {
            $this->notifyEmployee($employee, $satisfied === 1 ? 'Appraisal feedback received' : 'Appraisal escalated for review', $satisfied === 1 ? 'Your appraisal feedback was submitted and is now awaiting approval.' : 'Your appraisal was escalated for management review.', $satisfied === 1 ? 'success' : 'warning');
        }
    }

    public function decide(int $id, array $data): void
    {
        if (!$this->isApprover()) {
            throw new \RuntimeException('You do not have approval permission.');
        }
        $appraisal = $this->assertReviewAccess($id);
        if (!$this->canDecideTarget($appraisal)) {
            throw new \DomainException('Appraisal not found.');
        }
        $status = (string) $appraisal['status'];
        if (!in_array($status, ['pending_dept_approval', 'under_review'], true)) {
            throw new \RuntimeException('This appraisal is not awaiting a reviewer decision.');
        }
        $decision = strtolower(trim((string) ($data['decision'] ?? '')));
        if (!in_array($decision, ['approve', 'reject', 'return_for_revision', 'requires_meeting'], true)) {
            throw new \InvalidArgumentException('Select a valid appraisal decision.');
        }
        $comment = trim((string) ($data['comment'] ?? ''));
        if ($decision !== 'approve' && $comment === '') {
            throw new \InvalidArgumentException('A comment is required for this decision.');
        }
        if (mb_strlen($comment) > 5000) {
            throw new \InvalidArgumentException('Decision comments must be 5,000 characters or fewer.');
        }
        $reviewer = $this->currentEmployee();
        if (!$reviewer) {
            throw new \RuntimeException('Your account is not linked to an active employee record.');
        }
        $newStatus = $decision === 'approve' ? 'completed' : 'rejected';
        $label = match ($decision) {
            'approve' => 'Approved',
            'reject' => 'Rejected',
            'return_for_revision' => 'Returned for Revision',
            default => 'Meeting Required',
        };
        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare(
                "UPDATE employee_appraisals
                 SET status = ?, dept_head_decision = ?, dept_head_comment = ?,
                     dept_head_reviewer_id = ?, dept_head_decision_date = NOW(),
                     submitted_at = CASE WHEN ? = 'completed' THEN NOW() ELSE submitted_at END,
                     updated_at = NOW()
                 WHERE id = ? AND status IN ('pending_dept_approval','under_review')"
            );
            $reviewerId = (int) $reviewer['id'];
            $stmt->bind_param('sssisi', $newStatus, $label, $comment, $reviewerId, $newStatus, $id);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                throw new \RuntimeException('This appraisal was already decided.');
            }
            $stmt->close();
            if ($decision !== 'approve') {
                $stmt = $this->db->prepare(
                    "INSERT INTO appraisal_revision_log
                     (original_appraisal_id, employee_id, appraisal_cycle_id, appraiser_id,
                      reviewer_id, decision, reviewer_comment, final_status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
                );
                $final = $decision === 'reject' ? 'rejected' : $decision;
                $revisionEmployeeId = (int) $appraisal['employee_id'];
                $revisionCycleId = (int) $appraisal['appraisal_cycle_id'];
                $revisionAppraiserId = (int) $appraisal['appraiser_id'];
                $revisionReviewerId = (int) $reviewer['id'];
                $stmt->bind_param('iiiiisss', $id, $revisionEmployeeId, $revisionCycleId, $revisionAppraiserId, $revisionReviewerId, $label, $comment, $final);
                $stmt->execute();
                $stmt->close();
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
        $action = $decision === 'approve' ? AuditService::ACTION_APPRAISAL_APPROVED : ($decision === 'reject' ? AuditService::ACTION_APPRAISAL_REJECTED : ($decision === 'return_for_revision' ? AuditService::ACTION_APPRAISAL_REVISION_REQUESTED : AuditService::ACTION_APPRAISAL_MEETING_REQUESTED));
        $this->audit($id, $action, 'Reviewer recorded appraisal decision: ' . $label, ['new_values' => ['status' => $newStatus, 'decision' => $label], 'metadata' => ['comment_present' => $comment !== '', 'comment_length' => mb_strlen($comment), 'decision' => $decision]]);
        $employee = $this->fetchEmployee((int) $appraisal['employee_id']);
        if ($employee) {
            $this->notifyEmployee($employee, 'Appraisal decision: ' . $label, 'Your appraisal decision is: ' . $label . '.', $decision === 'approve' ? 'success' : 'warning');
        }
    }
}

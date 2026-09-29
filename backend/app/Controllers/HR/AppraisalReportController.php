<?php

declare(strict_types=1);

namespace App\Controllers\HR;

use App\Controllers\BaseController;
use App\Helpers\Database;
use App\Helpers\OrgScope;
use App\Services\Appraisal\AppraisalReportService;
use App\Services\AuditService;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * AppraisalReportController - read-only "Completed Appraisals" endpoints.
 *
 * Every action delegates the visibility decision to AppraisalReportService /
 * AppraisalWorkflowService, so this class only maps HTTP onto the service and
 * streams the resulting document.
 */
class AppraisalReportController extends BaseController
{
    private AppraisalReportService $reports;

    public function __construct()
    {
        $this->reports = new AppraisalReportService();
    }

    /**
     * The Completed Appraisals page is readable by two kinds of caller:
     *
     *  - supervisors (`performance:supervise`) see their whole authorised
     *    scope, chosen server-side by AppraisalReportService;
     *  - everyone else (`performance:feedback`, i.e. officers and staff) sees
     *    ONLY their own completed appraisals, to download.
     *
     * Accepting `feedback` here is what removes the 403 officers hit; the
     * self-scope is enforced in the service, never by trusting the client.
     */
    private function requireCompletedAppraisalAccess(): void
    {
        if (
            !$this->hasPermission('performance', 'supervise')
            && !$this->hasPermission('performance', 'feedback')
        ) {
            $this->forbidden('You do not have permission to view completed appraisals.');
        }
    }

    /**
     * GET /api/appraisals/completed - filtered, paginated, scope-limited list.
     */
    public function completedAction(): void
    {
        $this->requireCompletedAppraisalAccess();
        $this->respond(function (): void {
            $result = $this->reports->completedList(
                [
                    'status'        => $_GET['status'] ?? null,
                    'cycle_id'      => $_GET['cycle_id'] ?? null,
                    'department_id' => $_GET['department_id'] ?? null,
                    'section_id'    => $_GET['section_id'] ?? null,
                    'subsection_id' => $_GET['subsection_id'] ?? null,
                    'search'        => $_GET['search'] ?? $_GET['q'] ?? null,
                ],
                max(1, (int) ($_GET['page'] ?? 1)),
                (int) ($_GET['per_page'] ?? 20)
            );
            $this->success($result);
        });
    }

    /**
     * GET /api/appraisals/completed/filters - dropdown options for the page.
     *
     * Supervisors only. Officers get a self-scoped list with no filters, so
     * there is nothing for them to choose from - and department/section
     * lookups are deliberately not exposed to them.
     *
     * Department/section options are narrowed to the caller's own scope, so a
     * section head is never offered a department they cannot filter into.
     */
    public function filtersAction(): void
    {
        $this->requirePermission('performance', 'supervise');

        $this->respond(function (): void {
            $scope = $this->filterScope();
            $this->success([
                'statuses'    => AppraisalReportService::FILTERABLE_STATUSES,
                'cycles'      => $this->cycles(),
                'departments' => $this->scopedReference('departments', 'd.name', 'e.department_id = d.id', $scope),
                'sections'    => $this->scopedReference('sections', 's.name', 'e.section_id = s.id', $scope),
                'subsections' => $this->scopedReference('subsections', 'ss.name', 'e.subsection_id = ss.id', $scope),
                // Which tier the caller actually owns. The UI uses it to show
                // only the filters that can still narrow the result: a
                // subsection head has nothing to choose between departments,
                // so offering them one is noise, not control.
                'scope'       => $scope,
            ]);
        });
    }

    /**
     * GET /api/appraisals/completed/analytics - headline performance figures.
     *
     * Supervisors only, and it accepts the same filter set as completedAction()
     * so the headline numbers always describe exactly the rows the table is
     * showing. Officers are refused rather than served an empty dashboard:
     * `available: false` exists for the self-scoped service path, but a direct
     * call from a non-supervisor is a 403 because they may not see unit-wide
     * performance at all.
     */
    public function analyticsAction(): void
    {
        $this->requirePermission('performance', 'supervise');

        $this->respond(function (): void {
            $this->success($this->reports->analytics([
                'status'        => $_GET['status'] ?? null,
                'cycle_id'      => $_GET['cycle_id'] ?? null,
                'department_id' => $_GET['department_id'] ?? null,
                'section_id'    => $_GET['section_id'] ?? null,
                'subsection_id' => $_GET['subsection_id'] ?? null,
                'search'        => $_GET['search'] ?? $_GET['q'] ?? null,
            ]));
        });
    }

    /**
     * The caller's organisational tier and the unit ids it is pinned to.
     *
     * @return array{level:string,organisation:bool,department_id:?int,section_id:?int,subsection_id:?int}
     */
    private function filterScope(): array
    {
        $role = strtolower((string) (\App\Helpers\Auth::getInstance()->role() ?: ($_SESSION['user_role'] ?? '')));
        $org  = OrgScope::current();

        $departmentId = isset($org['department_id']) && $org['department_id'] !== null ? (int) $org['department_id'] : null;
        $sectionId    = isset($org['section_id']) && $org['section_id'] !== null ? (int) $org['section_id'] : null;
        $subsectionId = isset($org['subsection_id']) && $org['subsection_id'] !== null ? (int) $org['subsection_id'] : null;

        // Organisation-wide roles may pick any unit, so every tier is offered.
        $organisation = in_array($role, ['hr_manager', 'super_admin', 'managing_director', 'director', 'md'], true);
        if ($organisation) {
            return [
                'level' => 'organisation',
                'organisation' => true,
                'department_id' => null,
                'section_id' => null,
                'subsection_id' => null,
            ];
        }

        // Otherwise the DEEPEST unit the caller owns decides the tier, and the
        // shallower units are pinned - a section head's own department is not
        // a choice, it is a fact about them.
        if ($subsectionId !== null) {
            $level = 'subsection';
        } elseif ($sectionId !== null) {
            $level = 'section';
        } else {
            $level = 'department';
        }

        return [
            'level' => $level,
            'organisation' => false,
            'department_id' => $departmentId,
            'section_id' => $sectionId,
            'subsection_id' => $subsectionId,
        ];
    }

    /** @return array<int,array{id:int,name:string,start_date:string|null,end_date:string|null}> */
    private function cycles(): array
    {
        $db = Database::getInstance()->getConnection();
        $rows = $db->query(
            "SELECT id, name, start_date, end_date FROM appraisal_cycles ORDER BY start_date DESC"
        )->fetch_all(MYSQLI_ASSOC);
        return $rows ?: [];
    }

    /**
     * Distinct lookup rows joined through `employees` so the caller's
     * organisational scope applies to the dropdown options too.
     *
     * The scope is PINNED, not merely a default: every tier the caller does not
     * own contributes a fixed predicate, so a section head is offered only
     * their own department, a subsection head only their own section, and so
     * on. The legacy `is_pme_or_audit` bypass is deliberately NOT honoured
     * here - it exists for the strategy/contracts module, and reusing it let a
     * PME department head see "Technical" in the appraisal filters even though
     * their scope query could never return a Technical row.
     *
     * @param  array{level:string,organisation:bool,department_id:?int,section_id:?int,subsection_id:?int} $scope
     * @return array<int,array{id:int,name:string}>
     */
    private function scopedReference(string $table, string $nameColumn, string $join, array $scope): array
    {
        $alias = match ($table) {
            'departments'  => 'd',
            'sections'     => 's',
            'subsections'  => 'ss',
            default        => null,
        };
        if ($alias === null) {
            return [];
        }

        $clauses = [];
        $params = [];

        if (empty($scope['organisation'])) {
            if (!empty($scope['department_id'])) {
                $clauses[] = 'e.department_id = ?';
                $params[] = (int) $scope['department_id'];
            } else {
                // No department resolved: the caller owns nothing selectable,
                // so offer nothing rather than the whole company.
                $clauses[] = '1=0';
            }
            if (!empty($scope['section_id'])) {
                $clauses[] = 'e.section_id = ?';
                $params[] = (int) $scope['section_id'];
            }
            if (!empty($scope['subsection_id'])) {
                $clauses[] = 'e.subsection_id = ?';
                $params[] = (int) $scope['subsection_id'];
            }
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
        // The table MUST be aliased, and every reference must then use that
        // alias. Without it MySQL raises "Unknown column 'd.name' in 'field
        // list'" for EVERY supervisor, and respond() maps that to a 500 - which
        // is what surfaced as a permanently empty filter dropdown on a page
        // the user was otherwise fully authorised to open. Once the alias
        // exists the bare table name is no longer a valid qualifier either, so
        // the SELECT list uses the alias too.
        $sql = "SELECT DISTINCT {$alias}.id, {$nameColumn} AS name
                FROM {$table} {$alias}
                JOIN employees e ON {$join}
                {$where}
                ORDER BY name";
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare($sql);
        if ($params) {
            $stmt->bind_param(str_repeat('i', count($params)), ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows ?: [];
    }

    /**
     * GET /api/appraisals/{id}/report - JSON payload for the in-app drill-down.
     */
    public function showAction(int $id): void
    {
        $this->requireCompletedAppraisalAccess();
        $this->respond(function () use ($id): void {
            $this->success($this->reports->reportData($id));
        });
    }

    /**
     * GET /api/appraisals/{id}/report?format=pdf|word|print
     *
     * The payload is fetched through reportData(), which re-checks that the
     * caller may see this appraisal - a user cannot export an id they are not
     * allowed to read simply by guessing it.
     */
    public function downloadAction(int $id): void
    {
        $this->requireCompletedAppraisalAccess();
        $format = strtolower(trim((string) ($_GET['format'] ?? 'pdf')));
        if (!in_array($format, ['pdf', 'word', 'print'], true)) {
            $this->error('Unsupported report format.', 400, 'INVALID_FORMAT');
        }

        try {
            $report = $this->reports->reportData($id);
        } catch (\DomainException $e) {
            // Out-of-scope reads are indistinguishable from "no such row", so
            // this endpoint cannot be used to enumerate other units.
            $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            \logger()->error('Appraisal report generation failed', ['error' => $e->getMessage(), 'id' => $id]);
            $this->error('The report could not be generated.', 500, 'REPORT_FAILED');
        }

        $html = $this->reports->renderHtml($report);
        $this->logExport($id, $format, $report);

        // The shared helpers wrap bodies in JSON; documents are streamed raw,
        // so the response is emitted here instead of through success().
        //
        // bootstrap.php opens ob_start() for the whole request, so
        // headers_sent() is already TRUE by the time we get here and every
        // PDF/Word/Print download aborted with a 500 here - before Dompdf or
        // PhpWord was ever invoked. Discard the buffer and close it so the
        // streaming helpers can set Content-Type/Content-Disposition freely.
        if (ob_get_level() > 0) {
            ob_clean();
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }
        if (headers_sent()) {
            $this->error('Output already started.', 500, 'REPORT_FAILED');
        }

        match ($format) {
            'pdf'   => $this->streamPdf($html, $this->filename($report, $format)),
            'word'  => $this->streamWord($report, $this->filename($report, $format)),
            default => $this->streamPrint($html),
        };
    }

    /** @param array<string,mixed> $report */
    private function filename(array $report, string $format): string
    {
        $code = preg_replace('/[^A-Za-z0-9_-]+/', '', (string) ($report['employee_code'] ?? '')) ?: 'appraisal';
        $cycle = preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($report['cycle_name'] ?? '')) ?: 'cycle';
        $ext = $format === 'pdf' ? 'pdf' : 'docx';
        return "MUWASCO_Appraisal_{$code}_{$cycle}.{$ext}";
    }

    /** @param array<string,mixed> $report */
    private function logExport(int $id, string $format, array $report): void
    {
        try {
            AuditService::getInstance()->log(
                AuditService::MODULE_PERFORMANCE,
                AuditService::ACTION_APPRAISAL_ACCESSED,
                "Appraisal report exported ({$format}).",
                [
                    'target_type' => 'EmployeeAppraisal',
                    'target_id'   => $id,
                    'status'      => AuditService::STATUS_SUCCESS,
                    'metadata'    => ['format' => $format, 'employee_code' => $report['employee_code'] ?? null],
                ]
            );
        } catch (\Throwable $e) {
            // Auditing must never block a legitimate download.
            \logger()->warning('Appraisal export audit failed', ['error' => $e->getMessage()]);
        }
    }


    private function streamPdf(string $html, string $filename): void
    {
        if (!class_exists(Dompdf::class)) {
            $this->error('PDF generation is unavailable on this server.', 503, 'PDF_UNAVAILABLE');
        }

        $options = new Options();
        $options->setIsRemoteEnabled(false);   // the logo is an inline data URI
        $options->setIsHtml5ParserEnabled(true);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $dompdf->stream($filename, ['Attachment' => true, 'compress' => true]);
        exit;
    }

    /**
     * Word export - a genuine .docx via PhpWord.
     *
     * Built from the SAME reportData() payload as the PDF (not by scraping
     * the HTML), so the two documents cannot drift. Requires ext-gd for the
     * logo and phpoffice/phpword 1.4 (the 1.3 line pins phpoffice/math 0.2.0,
     * which carries CVE-2025-48882).
     *
     * @param array<string,mixed> $report
     */
    private function streamWord(array $report, string $filename): void
    {
        if (!class_exists(\PhpOffice\PhpWord\PhpWord::class)) {
            $this->error('Word generation is unavailable on this server.', 503, 'DOCX_UNAVAILABLE');
        }

        $word = new \PhpOffice\PhpWord\PhpWord();
        $section = $word->createSection();
        // PhpWord 1.4 moved the paragraph alignment constants out of
        // Style\Paragraph (which now only declares LINE_HEIGHT) and into the
        // SimpleType\Jc enum. Referencing Paragraph::ALIGN_CENTER raised
        // "Undefined constant" and failed EVERY Word export with a 500.
        $align = \PhpOffice\PhpWord\SimpleType\Jc::CENTER;
        $e = static fn (?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $logoPath = $this->logoPath();
        if ($logoPath !== '') {
            try {
                $section->addImage($logoPath, ['width' => 90, 'height' => 60, 'alignment' => $align]);
            } catch (\Throwable $e) {
                \logger()->warning('Appraisal logo could not be embedded in the Word document', ['error' => $e->getMessage()]);
            }
        }
        $section->addText('MURANGA WATER AND SANITATION COMPANY LTD', ['bold' => true, 'size' => 16, 'color' => '0F3D5C', 'alignment' => $align]);
        $section->addText('Performance Appraisal Report', ['size' => 13, 'color' => '334155', 'alignment' => $align]);
        $section->addText($e((string) ($report['cycle_name'] ?? '')), ['size' => 11, 'color' => '475569', 'alignment' => $align]);
        $section->addText(
            'P.O. Box 90461 - 80100, Muranga, Kenya | Tel: +254 (0) 41 2314209 | Email: info@muwasco.co.ke',
            ['size' => 8, 'color' => '64748B', 'alignment' => $align]
        );
        $section->addText(
            'Employee: ' . $e((string) ($report['employee_name'] ?? '')) . ' (' . $e((string) ($report['employee_code'] ?? ''))
            . ')   |   Status: ' . $e((string) ($report['status_label'] ?? '')) . '   |   Generated: ' . date('d M Y H:i'),
            ['size' => 8, 'color' => '64748B', 'alignment' => $align]
        );
        $section->addTextBreak(1);

        $section->addText('Employee information', ['bold' => true, 'size' => 11, 'color' => 'FFFFFF', 'shading' => '0F3D5C']);
        $period = (!empty($report['start_date']) && !empty($report['end_date']))
            ? $this->shortDate((string) $report['start_date']) . ' - ' . $this->shortDate((string) $report['end_date'])
            : '-';
        $submitted = !empty($report['submitted_at']) ? $this->shortDateTime((string) $report['submitted_at']) : 'Not submitted';
        $overall = (float) ($report['score_percentage'] ?? 0);

        $info = $section->addTable(['borderSize' => 4, 'borderColor' => 'CBD5E1', 'cellMargin' => 60]);
        $r = $info->addRow();
        $r->addCell(1800)->addText('Employee name', ['bold' => true, 'size' => 9]);
        $r->addCell(2600)->addText($e((string) ($report['employee_name'] ?? '')), ['size' => 9]);
        $r->addCell(1800)->addText('Employee ID', ['bold' => true, 'size' => 9]);
        $r->addCell(1800)->addText($e((string) ($report['employee_code'] ?? '')), ['size' => 9]);

        $r = $info->addRow();
        $r->addCell(1800)->addText('Department', ['bold' => true, 'size' => 9]);
        $r->addCell(2600)->addText($e((string) ($report['department_name'] ?? 'Not set')), ['size' => 9]);
        $r->addCell(1800)->addText('Section', ['bold' => true, 'size' => 9]);
        $r->addCell(1800)->addText($e((string) ($report['section_name'] ?? 'Not set')), ['size' => 9]);

        $r = $info->addRow();
        $r->addCell(1800)->addText('Appraisal period', ['bold' => true, 'size' => 9]);
        $r->addCell(2600)->addText($period, ['size' => 9]);
        $r->addCell(1800)->addText('Appraiser', ['bold' => true, 'size' => 9]);
        $r->addCell(1800)->addText($e((string) ($report['appraiser_name'] ?? '-')), ['size' => 9]);

        $r = $info->addRow();
        $r->addCell(1800)->addText('Submitted', ['bold' => true, 'size' => 9]);
        $r->addCell(2600)->addText($e($submitted), ['size' => 9]);
        $r->addCell(1800)->addText('Overall score', ['bold' => true, 'size' => 9]);
        $r->addCell(1800)->addText($this->trimNum($overall) . '%', ['size' => 9, 'bold' => true, 'color' => $this->bandColor($overall)]);
        $section->addTextBreak(1);

        $section->addText('Performance score breakdown', ['bold' => true, 'size' => 11, 'color' => 'FFFFFF', 'shading' => '0F3D5C']);
        $lines = $report['lines'] ?? [];

        $scores = $section->addTable(['borderSize' => 4, 'borderColor' => 'CBD5E1', 'cellMargin' => 60]);
        $head = $scores->addRow();
        foreach (['Activity', 'Performance indicator', 'Set score', 'Score', '%', 'Appraiser comment'] as $heading) {
            $head->addCell(null, ['shading' => '0F3D5C'])->addText($heading, ['bold' => true, 'size' => 8, 'color' => 'FFFFFF']);
        }
        if (!$lines) {
            $scores->addRow()->addCell(null, ['colspan' => 6])
                ->addText('No scores have been recorded for this appraisal.', ['size' => 9, 'italic' => true, 'color' => '64748B']);
        }
        foreach ($lines as $line) {
            $max = (float) $line['max_score'];
            $score = (float) $line['score'];
            $pct = $max > 0 ? ($score / $max) * 100 : 0.0;
            $cell = $scores->addRow();
            $activity = $e((string) $line['activity_name']);
            if (!empty($line['contract_name'])) {
                $activity .= "\n" . $e((string) $line['contract_name']);
            }
            $cell->addCell()->addText($activity, ['size' => 8]);
            $cell->addCell()->addText($e((string) $line['indicator_name']), ['size' => 8]);
            $cell->addCell(900)->addText($this->trimNum($max), ['size' => 8]);
            $cell->addCell(900)->addText($this->trimNum($score), ['size' => 8, 'bold' => true, 'color' => $this->bandColor($pct)]);
            $cell->addCell(800)->addText($this->trimNum($pct) . '%', ['size' => 8, 'bold' => true, 'color' => $this->bandColor($pct)]);
            $cell->addCell()->addText(trim((string) $line['appraiser_comment']) ?: '-', ['size' => 8]);
        }

        $totalRow = $scores->addRow();
        $totalRow->addCell(null, ['colspan' => 2, 'shading' => 'ECFDF5'])->addText('Total', ['bold' => true, 'size' => 8]);
        $totalRow->addCell(900, ['shading' => 'ECFDF5'])->addText($this->trimNum((float) ($report['total_max_score'] ?? 0)), ['bold' => true, 'size' => 8]);
        $totalRow->addCell(900, ['shading' => 'ECFDF5'])->addText($this->trimNum((float) ($report['total_score'] ?? 0)), ['bold' => true, 'size' => 8, 'color' => $this->bandColor($overall)]);
        $totalRow->addCell(800, ['shading' => 'ECFDF5'])->addText($this->trimNum($overall) . '%', ['bold' => true, 'size' => 8, 'color' => $this->bandColor($overall)]);
        $totalRow->addCell(null, ['shading' => 'ECFDF5'])->addText('');

        foreach ([
            'Employee comment'   => [(string) ($report['employee_comment'] ?? ''), $report['employee_comment_date'] ?? null],
            'Supervisor comment' => [(string) ($report['supervisors_comment'] ?? ''), $report['supervisors_comment_date'] ?? null],
            'Reviewer decision'  => [
                trim(((string) ($report['dept_head_decision'] ?? '')) . ' ' . ((string) ($report['dept_head_comment'] ?? ''))),
                $report['dept_head_decision_date'] ?? null,
            ],
        ] as $title => [$body, $date]) {
            if (trim($body) === '') {
                continue;
            }
            $section->addTextBreak(1);
            $section->addText((string) $title, ['bold' => true, 'size' => 11, 'color' => 'FFFFFF', 'shading' => '0F3D5C']);
            $section->addText($e(trim($body)), ['size' => 9, 'shading' => 'F8FAFC']);
            if (!empty($date)) {
                $section->addText('Recorded: ' . $this->shortDateTime((string) $date), ['size' => 8, 'color' => '64748B']);
            }
        }

        $section->addTextBreak(2);
        $section->addText('CONFIDENTIAL DOCUMENT - FOR OFFICIAL USE ONLY', ['bold' => true, 'size' => 8, 'color' => '334155', 'alignment' => $align]);
        $section->addText('Generated by the MURANGA WATER AND SANITATION COMPANY LTD HR Management System.', ['size' => 8, 'color' => '64748B', 'alignment' => $align]);
        $section->addText('(c) ' . date('Y') . ' Muranga Water and Sanitation Company Ltd. All rights reserved.', ['size' => 8, 'color' => '64748B', 'alignment' => $align]);

        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;
        $writer = \PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007');
        $writer->save($tmp);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        readfile($tmp);
        @unlink($tmp);
        exit;
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

    private function bandColor(float $percentage): string
    {
        if ($percentage < 50) return 'B91C1C';
        if ($percentage < 75) return 'B45309';
        return '15803D';
    }

    private function trimNum(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.') ?: '0';
    }

    private function shortDate(string $value): string
    {
        $ts = strtotime($value);
        return $ts ? date('d M Y', $ts) : '-';
    }

    private function shortDateTime(string $value): string
    {
        $ts = strtotime($value);
        return $ts ? date('d M Y H:i', $ts) : '-';
    }

    /** Browser print view (the UI opens this in a new tab). */
    private function streamPrint(string $html): void
    {
        $html = str_replace(
            '</body>',
            '<script>window.onload=function(){window.print();}</script></body>',
            $html
        );
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo $html;
        exit;
    }

    /** @param callable():void $operation */
    private function respond(callable $operation): void
    {
        try {
            $operation();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 422, 'VALIDATION_ERROR');
        } catch (\DomainException $e) {
            $this->notFound($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage(), 403, 'APPRAISAL_FORBIDDEN');
        } catch (\Throwable $e) {
            \logger()->error('Completed appraisals query failed', ['error' => $e->getMessage()]);
            $this->error('Completed appraisals could not be loaded.', 500, 'QUERY_FAILED');
        }
    }
}


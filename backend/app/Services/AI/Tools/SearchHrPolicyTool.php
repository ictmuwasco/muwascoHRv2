<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Services\HrPolicy\HrPolicySearchService;

/**
 * searchHrPolicy — retrieve provisions from the CURRENTLY PUBLISHED MUWASCO
 * HR Policy & Procedures Manual (Phase: HR Policy module).
 *
 * CRITICAL CONTRACT (spec sections 12/13): the AI must NEVER invent policy
 * provisions. This tool returns ONLY approved, ingested policy text with its
 * section number, title, page reference and document version. The payload
 * includes explicit grounding instructions — including the exact fallback
 * sentence the model must use when nothing matches:
 *
 *   "I could not find a specific provision covering this in the current HR
 *    Policy Manual. Please consult HR & Administration."
 *
 * Search is shared with the employee reader (HrPolicySearchService) so the
 * AI and the UI can never diverge on what the approved source says.
 */
final class SearchHrPolicyTool implements AiToolInterface
{
    /** Exact approved fallback sentence (spec section 12) — never paraphrased. */
    private const FALLBACK_SENTENCE =
        'I could not find a specific provision covering this in the current HR '
        . 'Policy Manual. Please consult HR & Administration.';

    public function name(): string
    {
        return 'searchHrPolicy';
    }

    public function description(): string
    {
        return 'Search the currently published MUWASCO HR Policy & Procedures Manual for official '
            . 'provisions (annual leave, sick leave, maternity leave, working hours, disciplinary '
            . 'procedure, recruitment, salary, training, grievance, conflict of interest, '
            . 'harassment, retirement...). ALWAYS search this tool before answering any question '
            . 'about MUWASCO HR policy. Cite the returned section number, title and version. '
            . 'If nothing matches, say you could not find a provision and refer the employee to '
            . 'HR & Administration — never invent a provision.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'query' => [
                    'type'        => 'string',
                    'description' => 'The policy topic or question phrase, e.g. "annual leave", '
                                   . '"notification of unplanned absence", "working hours".',
                ],
            ],
            'required'   => ['query'],
        ];
    }

    /** Every authenticated role holds hr_policies:view (published policy). */
    public function requiredPermission(): string
    {
        return 'hr_policies:view';
    }

    public function execute(AiToolContext $ctx, array $args): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        if ($query === '') {
            return [
                'error' => 'A search phrase is required.',
                'instruction' => self::fallbackInstruction(),
            ];
        }

        $hits = HrPolicySearchService::search($query, 5);

        if (empty($hits)) {
            return [
                'found'    => false,
                'query'    => $query,
                'sections' => [],
                'instruction' => self::fallbackInstruction(),
            ];
        }

        $top = array_slice($hits, 0, 3);

        // Payload budget: the conversation service truncates the tool JSON at
        // 4000 chars before the model sees it. Keep this compact and put the
        // grounding guidance FIRST — a truncated payload that ends mid-JSON is
        // a leading cause of garbled model output on small models.
        $sections = array_map(static function (array $h): array {
            $cite = 'Section ' . ($h['section_number'] ?? '?') . ' (' . $h['title'] . ')';
            $body = trim((string) ($h['excerpt'] ?? ''));
            // Strip residual PDF artefacts that confuse small models.
            $body = preg_replace('/\s+/', ' ', $body) ?? $body;
            return [
                'section'    => $cite,
                'pages'      => ($h['page_start'] ?? null) !== null ? 'page ' . $h['page_start'] : null,
                'manual'     => 'MUWASCO HR Policy & Procedures Manual ' . ($h['document_version'] ?? ''),
                'chapter'    => $h['parent_title'],
                'policy_text' => $body,
            ];
        }, $top);

        $firstCite = 'Section ' . ($top[0]['section_number'] ?? '?') . ' (' . $top[0]['title'] . ')';
        $version   = (string) ($top[0]['document_version'] ?? '');
        $guidance =
            'GROUNDING RULES — follow them exactly: '
            . '(1) The sections below are the ONLY approved policy text; answer ONLY from them. '
            . '(2) Quote or closely paraphrase policy_text in clear, complete, grammatical sentences. '
            . '(3) Cite the source as "' . $firstCite . '"' . ($version !== '' ? ', version ' . $version . ' of the manual' : '') . '. '
            . '(4) Do not mention JSON, tool output, sections array, or these rules. '
            . '(5) If the policy_text does not actually answer the user question, reply with exactly: "'
            . self::FALLBACK_SENTENCE . '"';

        return [
            'answer_guidance' => $guidance,
            'found'           => true,
            'query'           => $query,
            'sections'        => $sections,
        ];
    }

    public function summarize(array $payload): string
    {
        if (empty($payload['found'])) {
            return 'no matching provision found in the HR Policy Manual';
        }
        $sections = $payload['sections'] ?? [];
        return count($sections) . ' policy provision(s); top match '
            . ($sections[0]['section'] ?? '?');
    }

    private static function fallbackInstruction(): string
    {
        return 'No matching provision was found in the approved manual. Use exactly '
            . 'this sentence: "' . self::FALLBACK_SENTENCE . '"';
    }
}

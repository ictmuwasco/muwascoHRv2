<?php

declare(strict_types=1);

namespace App\Services\HrPolicy;

/**
 * HrPolicySearchService â€” server-side full-policy search.
 *
 * Operates ONLY on hr_policy_sections of PUBLISHED, non-deleted documents â€”
 * it can never expose employee records or unpublished drafts.
 *
 * Single implementation shared by the employee search endpoint AND the HR AI
 * tool (SearchHrPolicyTool) so both surfaces always cite the same source.
 *
 * Match strategy (small dataset â€” deterministic LIKE-style matching):
 *   1. exact/prefix section-number match  (e.g. "12.7.1", "12.7")
 *   2. phrase match in title              (e.g. "annual leave")
 *   3. phrase match in content
 *   4. ALL-terms match in title+content   (each term must appear somewhere)
 */
class HrPolicySearchService
{
    private const MAX_RESULTS = 25;
    private const EXCERPT_CONTEXT = 220;

    /**
     * @param  string $query Raw user query.
     * @param  int    $limit Max results (1..MAX_RESULTS).
     * @return array<int, array{section_id:int, document_id:int, document_title:string,
     *     document_version:string, section_number:?string, title:string,
     *     excerpt:string, page_start:?int, match_type:string, parent_title:?string}>
     */
    public static function search(string $query, int $limit = self::MAX_RESULTS): array
    {
        $query = trim($query);
        $limit = max(1, min(self::MAX_RESULTS, $limit));
        if (mb_strlen($query) < 2) {
            return [];
        }

        $terms = self::terms($query);
        if (empty($terms)) {
            return [];
        }

        $rows = \db()->fetchAll(
            "SELECT s.id AS section_id, s.policy_document_id, s.section_number, s.title,
                    s.content, s.page_start,
                    d.title AS document_title, d.version AS document_version
               FROM hr_policy_sections s
               JOIN hr_policy_documents d ON d.id = s.policy_document_id
              WHERE d.status = 'published' AND d.deleted_at IS NULL
              ORDER BY s.policy_document_id DESC, s.sort_order ASC"
        );

        // Meaningful terms: strip stop words like "section", "what", "does",
        // "mean", "the", "a", "an" â€” these never appear in section titles.
        $stopWords = [
            'section', 'what', 'does', 'mean', 'means', 'the', 'a', 'an',
            'this', 'that', 'about', 'explain', 'please', 'whats', 'don',
            'cant', 'my', 'in', 'on', 'for', 'of', 'is', 'are', 'and',
            'with', 'policy', 'hr', 'manual', 'about', 'or', 'to',
        ];
        $contentTerms = array_values(array_filter(
            $terms,
            static fn (string $t): bool => !in_array($t, $stopWords, true)
        ));
        if ($contentTerms === []) {
            $contentTerms = $terms;
        }

        // Extract section-number-like tokens (e.g. "32", "3.9", "8.1", "29").
        $numberTokens = [];
        foreach ($terms as $t) {
            if (preg_match('/^\d+(\.\d+)*$/', $t)) {
                $numberTokens[] = $t;
            }
        }

        $scored = [];
        $qLower = mb_strtolower($query);
        foreach ($rows as $row) {
            $matchType = self::matchType($row, $qLower, $terms, $contentTerms, $numberTokens);
            if ($matchType === null) {
                continue;
            }
            $title = mb_strtolower((string) $row['title']);
            $content = mb_strtolower(strip_tags((string) ($row['content'] ?? '')));
            $scored[] = [
                'section_id'        => (int) $row['section_id'],
                'document_id'       => (int) $row['policy_document_id'],
                'document_title'    => (string) $row['document_title'],
                'document_version'  => (string) $row['document_version'],
                'section_number'    => $row['section_number'],
                'title'             => (string) $row['title'],
                'excerpt'           => self::excerpt((string) ($row['content'] ?? ''), $contentTerms),
                'page_start'        => $row['page_start'] !== null ? (int) $row['page_start'] : null,
                'match_type'        => $matchType,
                'score'             => self::score($matchType, mb_strlen($title), $title, $content, $contentTerms),
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $top = array_slice($scored, 0, $limit);
        foreach ($top as &$hit) {
            unset($hit['score']);
            $hit['parent_title'] = self::parentTitle($hit['document_id'], $hit['section_id']);
        }
        return $top;
    }

    /**
     * Best single hit for the AI tool (or null when nothing matches â€” the AI
     * must then answer with the approved "could not find" fallback sentence).
     */
    public static function bestMatch(string $query): ?array
    {
        $hits = self::search($query, 1);
        return $hits[0] ?? null;
    }

    /** Classify how a row matches (or null when it does not match). */
    private static function matchType(array $row, string $qLower, array $terms): ?string
    {
        $num = mb_strtolower(trim((string) ($row['section_number'] ?? '')));
        $title = mb_strtolower((string) $row['title']);
        $content = mb_strtolower(strip_tags((string) ($row['content'] ?? '')));

        // Meaningful content terms (strip stop words that never appear in
        // section titles: "section", "what", "mean", "the", etc.)
        $stopWords = [
            'section', 'what', 'does', 'mean', 'means', 'the', 'a', 'an',
            'this', 'that', 'about', 'explain', 'please', 'whats', 'don',
            'cant', 'my', 'in', 'on', 'for', 'of', 'is', 'are', 'and',
            'with', 'policy', 'hr', 'manual', 'or', 'to',
        ];
        $contentTerms = array_values(array_filter(
            $terms,
            static fn (string $t): bool => !in_array($t, $stopWords, true)
        ));
        if ($contentTerms === []) {
            $contentTerms = $terms;
        }

        // Numeric tokens that may be section numbers ("69", "3.9", "8.1").
        $numberTokens = [];
        foreach ($terms as $t) {
            if (preg_match('/^\d+(\.\d+)*$/', $t)) {
                $numberTokens[] = $t;
            }
        }

        // 1. Exact/prefix match: whole query IS the stored section number.
        if ($num !== '' && $qLower !== '' && ($num === $qLower || str_starts_with($num, $qLower))) {
            return 'section_number';
        }

        // 2. Numeric token prefix-matches this row's section number, and any
        //    meaningful content term also appears (e.g. "section 69 8.1
        //    INTRODUCTION" -> stored number "69" + title "introduction").
        if ($numberTokens !== [] && $num !== '') {
            foreach ($numberTokens as $tok) {
                $numMatch = $num === $tok
                    || str_starts_with($num, $tok . '.')
                    || str_starts_with($tok, $num . '.');
                if ($numMatch) {
                    $meaningful = array_values(array_filter(
                        $contentTerms,
                        static fn (string $t): bool => !preg_match('/^\d+(\.\d+)*$/', $t)
                    ));
                    if ($meaningful === [] || self::anyTermsIn($title, $content, $meaningful)) {
                        return 'section_number';
                    }
                }
            }
        }

        // 3. Phrase match of all meaningful terms in title or content.
        if (count($contentTerms) >= 2 && self::phraseInFields($title, $content, $contentTerms)) {
            return 'title_phrase';
        }

        // 4. Single-term matches in title, then content.
        foreach ($contentTerms as $t) {
            if (mb_strpos($title, $t) !== false) {
                return 'title';
            }
        }
        foreach ($contentTerms as $t) {
            if (mb_strpos($content, $t) !== false) {
                return 'content';
            }
        }

        // 5. Lenient fallback: every meaningful term appears somewhere.
        foreach ($contentTerms as $t) {
            if (mb_strpos($title, $t) === false && mb_strpos($content, $t) === false) {
                return null;
            }
        }
        return 'all_terms';
    }

    /** True when ANY of the meaningful terms appears in either field. */
    private static function anyTermsIn(string $title, string $content, array $terms): bool
    {
        foreach ($terms as $t) {
            if ($t === '') {
                continue;
            }
            if (mb_strpos($title, $t) !== false || mb_strpos($content, $t) !== false) {
                return true;
            }
        }
        return false;
    }

    /** True when the ordered meaningful terms appear as a contiguous phrase. */
    private static function phraseInFields(string $title, string $content, array $terms): bool
    {
        $phrase = implode(' ', $terms);
        return mb_strpos($title, $phrase) !== false || mb_strpos($content, $phrase) !== false;
    }

    /** @return string[] Lower-cased, de-duplicated terms (max 8). */
    private static function terms(string $query): array
    {
        $raw = preg_split('/[\s,;]+/u', mb_strtolower($query)) ?: [];
        $terms = [];
        foreach ($raw as $t) {
            $t = trim($t, " \t.,:()'\"-");
            if (mb_strlen($t) >= 2) {
                $terms[] = $t;
            }
            if (count($terms) >= 8) {
                break;
            }
        }
        return $terms;
    }

    private static function score(
        string $matchType,
        int $titleLen,
        string $title = '',
        string $content = '',
        array $contentTerms = []
    ): int {
        // Term-coverage bonus: how many meaningful query terms appear in the
        // title (and, half-weighted, in the content). Prevents short,
        // partially-matching titles (e.g. "opening records:") from outranking
        // exact multi-word title matches (e.g. "3.6 OPEN DOOR POLICY").
        $titleHits = 0;
        $contentHits = 0;
        foreach ($contentTerms as $t) {
            if ($t === '' || preg_match('/^\d+(\.\d+)*$/', $t)) {
                continue;
            }
            if ($title !== '' && mb_strpos($title, $t) !== false) {
                $titleHits++;
            }
            if ($content !== '' && mb_strpos($content, $t) !== false) {
                $contentHits++;
            }
        }
        $coverage = min(12, $titleHits * 4 + (int) floor($contentHits / 2));

        return match ($matchType) {
            'section_number' => 100 + $coverage,
            'title_phrase'   => 85 + $coverage,
            'title'          => 70 + $coverage,
            'content'        => 50 + $coverage,
            default          => 20 + $coverage,
        };
    }

    /** Short excerpt centred on the first matching term (HTML stripped). */
    private static function excerpt(string $content, array $terms): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($content)) ?? '');
        if ($text === '') {
            return '';
        }
        $pos = false;
        foreach ($terms as $t) {
            $p = mb_stripos($text, $t);
            if ($p !== false && ($pos === false || $p < $pos)) {
                $pos = $p;
            }
        }
        if ($pos === false) {
            $suffix = mb_strlen($text) > self::EXCERPT_CONTEXT ? "\u{2026}" : '';
            return mb_substr($text, 0, self::EXCERPT_CONTEXT) . $suffix;
        }
        $start = max(0, $pos - 60);
        $excerpt = ($start > 0 ? "\u{2026}" : '') . mb_substr($text, $start, self::EXCERPT_CONTEXT);
        $suffix = mb_strlen($text) > $start + self::EXCERPT_CONTEXT ? "\u{2026}" : '';
        return $excerpt . $suffix;
    }

    private static function parentTitle(int $documentId, int $sectionId): ?string
    {
        $row = \db()->fetchOne(
            "SELECT p.title
               FROM hr_policy_sections s
               JOIN hr_policy_sections p ON p.id = s.parent_id
              WHERE s.id = ? AND s.policy_document_id = ? LIMIT 1",
            'ii', [$sectionId, $documentId]
        );
        return $row['title'] ?? null;
    }
}

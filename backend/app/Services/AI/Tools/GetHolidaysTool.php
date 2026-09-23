<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * getHolidays — the organisation's public holidays for a given year.
 *
 * ROLE RESTRICTION: gated on leave:view, the closest HR-facing permission that
 * every leave-consuming role holds (officer, employee, heads, HR, MD, super
 * admin). Roles without leave access — and delegated/act-as sessions that
 * resolve to none — never even see this tool.
 *
 * Data comes solely from the `holidays` maintenance table (migration 010);
 * recurring holidays (is_recurring = 1) are projected onto the requested year
 * by month/day, so a single stored "New Year" row answers for every year.
 * The model narrates the returned list and never invents a date.
 */
final class GetHolidaysTool implements AiToolInterface
{
    /** Hard cap on returned holidays (a year rarely exceeds ~20). */
    private const MAX_ROWS = 40;

    public function name(): string
    {
        return 'getHolidays';
    }

    public function description(): string
    {
        return 'Retrieve the public holidays the organisation observes for a given year (name, '
            . 'date, weekday and whether the holiday recurs annually). Use this when the user asks '
            . 'about public holidays, whether a specific date is a holiday, or how many holidays '
            . 'fall in a month or year. Defaults to the current year.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'year' => [
                    'type'        => 'string',
                    'pattern'     => '^[0-9]{4}$',
                    'description' => 'Year as YYYY (e.g. 2026). Defaults to the current year.',
                ],
            ],
            'required'   => [],
        ];
    }

    /** Public holiday information is part of leave planning. */
    public function requiredPermission(): string
    {
        return 'leave:view';
    }

    public function execute(AiToolContext $ctx, array $args): array
    {
        $year = isset($args['year']) && is_string($args['year'])
            && preg_match('/^\d{4}$/', $args['year']) === 1
            ? (int) $args['year']
            : (int) date('Y');

        $rows = $ctx->db()->fetchAll(
            'SELECT name, date, description, is_recurring
             FROM holidays
             WHERE YEAR(date) = ? OR is_recurring = 1
             ORDER BY date ASC',
            'i',
            [$year]
        );

        $holidays = [];
        foreach ($rows as $row) {
            $recurring = ((int) ($row['is_recurring'] ?? 0)) === 1;
            $source    = (string) $row['date'];
            $ts        = strtotime($source);
            if ($ts === false) {
                continue;
            }

            if ($recurring) {
                $month = (int) date('n', $ts);
                $day   = (int) date('j', $ts);
                if (!checkdate($month, $day, $year)) {
                    continue; // e.g. 29 February in a non-leap year
                }
                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            } elseif ((int) date('Y', $ts) === $year) {
                $date = substr($source, 0, 10);
            } else {
                continue;
            }

            $holidays[$date] = [
                'name'      => (string) $row['name'],
                'date'      => $date,
                'weekday'   => date('l', strtotime($date)),
                'recurring' => $recurring,
                'notes'     => $row['description'] !== null
                    ? mb_substr(trim((string) $row['description']), 0, 160)
                    : null,
            ];
        }

        ksort($holidays);
        $holidays = array_slice(array_values($holidays), 0, self::MAX_ROWS);

        $today = date('Y-m-d');
        $upcoming = [];
        foreach ($holidays as $h) {
            if ($h['date'] >= $today) {
                $upcoming[] = ['name' => $h['name'], 'date' => $h['date'], 'weekday' => $h['weekday']];
            }
            if (count($upcoming) === 3) {
                break;
            }
        }

        return [
            'year'     => $year,
            'count'    => count($holidays),
            'holidays' => $holidays,
            'next_holidays' => $upcoming,
            'note'     => count($holidays) === 0
                ? 'No public holidays are recorded for ' . $year . ' yet.'
                : 'Dates come from the HR holiday calendar.',
        ];
    }

    public function summarize(array $payload): string
    {
        if (!empty($payload['error'])) {
            return 'unavailable: ' . $payload['error'];
        }
        $count = (int) ($payload['count'] ?? 0);
        $next  = $payload['next_holidays'][0] ?? null;

        return $count . ' public holiday(s) in ' . ($payload['year'] ?? '?')
            . ($next !== null ? '; next: ' . $next['name'] . ' on ' . $next['date'] : '');
    }
}

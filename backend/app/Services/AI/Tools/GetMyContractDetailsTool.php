<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Models\Employee;

/**
 * getMyContractDetails — the caller's OWN employment/contract terms, built
 * from the employee record already resolved in the server-side context plus
 * that employee's contract history (employee_contracts, migration 057).
 *
 * Owner-scoped: the employee id comes from the context, never from model
 * arguments. Answers "when does my contract expire?", "how many contracts have
 * I had?", "am I permanent?" — questions HR fields constantly.
 *
 * SENSITIVE-FIELD FILTERING: salary, national ID, phone, email, home address
 * and next-of-kin are deliberately NOT returned; the payload goes into the
 * provider conversation and must stay work-related.
 */
final class GetMyContractDetailsTool implements AiToolInterface
{
    /** Employment types that carry contract terms (mirrors EmployeeService::isContractBased). */
    private const CONTRACT_BASED_TYPES = ['contract', 'csuite'];

    /** Expiry window used to flag "expires soon" in the payload. */
    private const EXPIRY_WARNING_DAYS = 60;

    public function name(): string
    {
        return 'getMyContractDetails';
    }

    public function description(): string
    {
        return 'Retrieve the signed-in employee\'s own employment and contract details: employment '
            . 'type, hire date, current contract number, contract start and end dates, remaining '
            . 'days before the contract expires, expiry warning, and the contract/renewal history. '
            . 'Use this for questions about contract expiry, contract renewal, how many contracts '
            . 'the employee has had, or whether their employment is permanent.';
    }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => new \stdClass(),
            'required'   => [],
        ];
    }

    /** Self-service: any authenticated user with an employee record. */
    public function requiredPermission(): string
    {
        return '';
    }

    public function execute(AiToolContext $ctx, array $args): array
    {
        $employeeId = $ctx->employeeId();
        if ($employeeId === null) {
            return [
                'error' => 'No employee record is linked to your account, so contract details cannot be shown.',
            ];
        }

        $row = $ctx->employee() ?? [];

        $employmentType = (string) ($row['employment_type'] ?? '');
        $contractBased  = in_array($employmentType, self::CONTRACT_BASED_TYPES, true);

        $history = $ctx->db()->fetchAll(
            'SELECT contract_number, contract_name, start_date, end_date, employment_type, renewal_date
             FROM employee_contracts
             WHERE employee_id = ?
             ORDER BY contract_number DESC, start_date DESC
             LIMIT 10',
            'i',
            [$employeeId]
        );

        $contracts = [];
        foreach ($history as $c) {
            $contracts[] = [
                'contract_number' => (int) $c['contract_number'],
                'name'            => $c['contract_name'] !== null ? (string) $c['contract_name'] : null,
                'start_date'      => $c['start_date'] !== null ? (string) $c['start_date'] : null,
                'end_date'        => $c['end_date'] !== null ? (string) $c['end_date'] : null,
                'employment_type' => $c['employment_type'] !== null ? (string) $c['employment_type'] : null,
                'renewed_on'      => $c['renewal_date'] !== null
                    ? substr((string) $c['renewal_date'], 0, 10)
                    : null,
            ];
        }

        $current = $this->currentContract($contracts, $row, $employmentType);
        $endDate = $current['end_date'] ?? null;

        $daysUntilExpiry = null;
        $status = $contractBased ? 'unknown' : 'permanent';
        if ($contractBased) {
            $status = 'ongoing';
            if ($endDate !== null) {
                $daysUntilExpiry = (int) round((strtotime($endDate) - strtotime(date('Y-m-d'))) / 86400);
                $status = $daysUntilExpiry < 0 ? 'expired' : 'active';
            }
        }

        return [
            'employee_number'   => isset($row['employee_id']) ? (string) $row['employee_id'] : null,
            'designation'       => isset($row['designation']) ? (string) $row['designation'] : null,
            'employment_type'   => $employmentType !== '' ? $employmentType : null,
            'employment_label'  => Employee::EMPLOYMENT_TYPES[$employmentType] ?? null,
            'contract_based'    => $contractBased,
            'hire_date'         => isset($row['hire_date']) ? (string) $row['hire_date'] : null,
            'current_contract'  => $current === null ? null : [
                'contract_number' => $current['contract_number'],
                'name'            => $current['name'],
                'start_date'      => $current['start_date'],
                'end_date'        => $endDate,
                'status'          => $status,
            ],
            'days_until_expiry' => $daysUntilExpiry,
            'expiry_warning'    => $daysUntilExpiry !== null
                && $daysUntilExpiry >= 0
                && $daysUntilExpiry <= self::EXPIRY_WARNING_DAYS,
            'contract_count'    => count($contracts),
            'contract_history'  => $contracts,
            'note'              => $contractBased
                ? 'Contract terms only. Salary, identity and contact data are not available through this tool.'
                : 'This employment type does not carry a contract end date.',
        ];
    }

    /**
     * Pick the CURRENT contract: the historical row with no end date first,
     * then the one that ends last, falling back to the employees table dates
     * when no contract history row exists yet.
     *
     * @param array<int, array<string, mixed>> $contracts
     * @param array<string, mixed>             $row
     * @return array<string, mixed>|null
     */
    private function currentContract(array $contracts, array $row, string $employmentType): ?array
    {
        foreach ($contracts as $c) {
            if ($c['end_date'] === null) {
                return $c;
            }
        }

        if ($contracts !== []) {
            usort($contracts, static function (array $a, array $b): int {
                return strcmp((string) $b['end_date'], (string) $a['end_date']);
            });
            return $contracts[0];
        }

        if (($row['contract_start_date'] ?? null) === null) {
            return null;
        }

        return [
            'contract_number' => null,
            'name'            => null,
            'start_date'      => (string) $row['contract_start_date'],
            'end_date'        => ($row['contract_end_date'] ?? null) !== null
                ? (string) $row['contract_end_date']
                : null,
            'employment_type' => $employmentType !== '' ? $employmentType : null,
            'renewed_on'      => null,
        ];
    }

    public function summarize(array $payload): string
    {
        if (!empty($payload['error'])) {
            return 'unavailable: ' . $payload['error'];
        }
        $type = (string) ($payload['employment_type'] ?? '?');
        if (empty($payload['contract_based'])) {
            return 'employment type ' . $type . ' (no contract terms)';
        }
        $end  = $payload['current_contract']['end_date'] ?? null;
        $days = $payload['days_until_expiry'] ?? null;

        return 'employment type ' . $type
            . ($end !== null ? '; contract ends ' . $end : '; no contract end date recorded')
            . ($days !== null ? ' (' . $days . ' day(s) away)' : '');
    }
}

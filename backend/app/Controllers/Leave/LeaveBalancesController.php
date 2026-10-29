<?php

declare(strict_types=1);

namespace App\Controllers\Leave;

use App\Controllers\BaseController;
use App\Helpers\Auth;
use App\Helpers\Database;
use App\Services\AuditService;
use App\Services\Leave\LeaveTypePolicy;

/**
 * Super-admin management of employee leave balances.
 */
final class LeaveBalancesController extends BaseController
{
    public function indexAction(): void
    {
        $this->authorize();

        try {
            $conn = Database::getInstance()->getConnection();
            $financialYears = $this->fetchRows(
                $conn,
                'SELECT id, year_name, start_date, end_date, is_active
                 FROM financial_years ORDER BY start_date DESC, id DESC'
            );
            $leaveTypes = $this->fetchRows(
                $conn,
                'SELECT id, name, is_active FROM leave_types ORDER BY id'
            );
            $employees = $this->fetchRows(
                $conn,
                "SELECT id, employee_id, first_name, last_name, surname, employee_status
                 FROM employees
                 ORDER BY first_name, last_name, surname, id"
            );

            $requestedYear = $_GET['financial_year_id'] ?? null;
            if ($requestedYear !== null && $requestedYear !== '') {
                $financialYearId = $this->positiveId($requestedYear, 'financial_year_id');
            } else {
                $activeYear = $conn->query(
                    'SELECT id FROM financial_years
                     WHERE is_active = 1 ORDER BY start_date DESC, id DESC LIMIT 1'
                )->fetch_assoc();
                $financialYearId = (int) ($activeYear['id'] ?? 0);
            }

            $balances = [];
            if ($financialYearId > 0) {
                $stmt = $conn->prepare(
                    'SELECT elb.id, elb.employee_id, e.employee_id AS employee_code,
                            e.first_name, e.last_name, e.surname, e.employee_status,
                            elb.leave_type_id, lt.name AS leave_type,
                            elb.financial_year_id, fy.year_name,
                            elb.allocated_days, elb.brought_forward_days,
                            elb.accumulated_days, elb.used_days, elb.remaining_days
                     FROM employee_leave_balances elb
                     LEFT JOIN employees e ON e.id = elb.employee_id
                     LEFT JOIN leave_types lt ON lt.id = elb.leave_type_id
                     LEFT JOIN financial_years fy ON fy.id = elb.financial_year_id
                     WHERE elb.financial_year_id = ?
                     ORDER BY e.last_name, e.first_name, lt.id, elb.id'
                );
                $stmt->bind_param('i', $financialYearId);
                $stmt->execute();
                $result = $stmt->get_result();
                $balances = $result->fetch_all(\MYSQLI_ASSOC);
                $stmt->close();

                $numericFields = [
                    'allocated_days',
                    'brought_forward_days',
                    'accumulated_days',
                    'used_days',
                    'remaining_days',
                ];
                foreach ($balances as &$balance) {
                    $balance['id'] = (int) $balance['id'];
                    $balance['employee_id'] = (int) $balance['employee_id'];
                    $balance['leave_type_id'] = (int) $balance['leave_type_id'];
                    $balance['financial_year_id'] = (int) $balance['financial_year_id'];
                    foreach ($numericFields as $field) {
                        $balance[$field] = (float) $balance[$field];
                    }
                }
                unset($balance);
            }

            foreach ($financialYears as &$year) {
                $year['id'] = (int) $year['id'];
                $year['is_active'] = (bool) $year['is_active'];
            }
            unset($year);

            foreach ($leaveTypes as &$leaveType) {
                $leaveType['id'] = (int) $leaveType['id'];
                $leaveType['is_active'] = (bool) $leaveType['is_active'];
            }
            unset($leaveType);

            foreach ($employees as &$employee) {
                $employee['id'] = (int) $employee['id'];
            }
            unset($employee);

            $this->success([
                'financial_years' => $financialYears,
                'leave_types' => $leaveTypes,
                'employees' => $employees,
                'selected_financial_year_id' => $financialYearId,
                'balances' => $balances,
            ]);
        } catch (\Throwable $e) {
            \logger()->error('Leave balance list failed', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
            $this->error('Failed to load leave balances. Please try again.', 500);
        }
    }

    public function updateAction(): void
    {
        $this->authorize();
        $data = $this->getJsonBody();

        try {
            $employeeId = $this->positiveId($data['employee_id'] ?? null, 'employee_id');
            $leaveTypeId = $this->positiveId($data['leave_type_id'] ?? null, 'leave_type_id');
            $financialYearId = $this->positiveId(
                $data['financial_year_id'] ?? null,
                'financial_year_id'
            );
            $allocated = $this->days($data['allocated_days'] ?? null, 'allocated_days', true);
            $broughtForward = $this->days(
                $data['brought_forward_days'] ?? null,
                'brought_forward_days',
                false
            );
            $requestedUsed = array_key_exists('used_days', $data)
                ? $this->days($data['used_days'], 'used_days', false)
                : null;

            $result = $this->transaction(function (\mysqli $conn) use (
                $employeeId,
                $leaveTypeId,
                $financialYearId,
                $allocated,
                $broughtForward,
                $requestedUsed
            ): array {
                $employee = $this->lockEmployee($conn, $employeeId);
                $this->requireReference($conn, 'leave_types', $leaveTypeId, 'Leave type');
                $this->requireReference($conn, 'financial_years', $financialYearId, 'Financial year');
                $rows = $this->balanceRows($conn, $employeeId, $leaveTypeId, $financialYearId, true);
                $this->assertSingleBalance($rows);

                $before = $rows[0] ?? null;
                $used = $requestedUsed ?? (float) ($before['used_days'] ?? 0);
                $saved = $this->saveBalance(
                    $conn,
                    $before,
                    $employeeId,
                    $leaveTypeId,
                    $financialYearId,
                    $allocated,
                    $used,
                    $broughtForward
                );

                $this->auditBalanceChange(
                    $employee,
                    $leaveTypeId,
                    $financialYearId,
                    $before,
                    $saved,
                    'UPDATE',
                    'Updated leave balance allocation, brought-forward days, and used days.'
                );

                return ['balance' => $saved];
            });

            $this->success($result, 'Leave balance updated.');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 422, 'VALIDATION_ERROR');
        } catch (\OutOfBoundsException $e) {
            $this->error($e->getMessage(), 404, 'NOT_FOUND');
        } catch (\DomainException $e) {
            $this->error($e->getMessage(), 409, 'BALANCE_CONFLICT');
        } catch (\Throwable $e) {
            \logger()->error('Leave balance update failed', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
            $this->error('Failed to update leave balance. No changes were saved.', 500);
        }
    }

    public function addAllocationAction(): void
    {
        $this->authorize();
        $data = $this->getJsonBody();

        try {
            $employeeId = $this->positiveId($data['employee_id'] ?? null, 'employee_id');
            $leaveTypeId = $this->positiveId($data['leave_type_id'] ?? null, 'leave_type_id');
            $financialYearId = $this->positiveId(
                $data['financial_year_id'] ?? null,
                'financial_year_id'
            );
            $amount = $this->days($data['days'] ?? null, 'days', true);
            if ($amount <= 0) {
                throw new \InvalidArgumentException('Allocation days must be greater than zero.');
            }

            $result = $this->transaction(function (\mysqli $conn) use (
                $employeeId,
                $leaveTypeId,
                $financialYearId,
                $amount
            ): array {
                $employee = $this->lockEmployee($conn, $employeeId);
                $this->requireReference($conn, 'leave_types', $leaveTypeId, 'Leave type');
                $this->requireReference($conn, 'financial_years', $financialYearId, 'Financial year');
                $rows = $this->balanceRows($conn, $employeeId, $leaveTypeId, $financialYearId, true);
                $this->assertSingleBalance($rows);

                $before = $rows[0] ?? null;
                $allocated = (float) ($before['allocated_days'] ?? 0) + $amount;
                if ($allocated > 999.99) {
                    throw new \InvalidArgumentException(
                        'The resulting allocation exceeds the supported balance range.'
                    );
                }
                $saved = $this->saveBalance(
                    $conn,
                    $before,
                    $employeeId,
                    $leaveTypeId,
                    $financialYearId,
                    $allocated,
                    (float) ($before['used_days'] ?? 0),
                    (float) ($before['brought_forward_days'] ?? 0)
                );

                $this->auditBalanceChange(
                    $employee,
                    $leaveTypeId,
                    $financialYearId,
                    $before,
                    $saved,
                    $before === null ? 'CREATE' : 'UPDATE',
                    'Added leave allocation.'
                );

                return ['balance' => $saved];
            });

            $this->success($result, 'Leave allocation added.');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 422, 'VALIDATION_ERROR');
        } catch (\OutOfBoundsException $e) {
            $this->error($e->getMessage(), 404, 'NOT_FOUND');
        } catch (\DomainException $e) {
            $this->error($e->getMessage(), 409, 'BALANCE_CONFLICT');
        } catch (\Throwable $e) {
            \logger()->error('Leave allocation failed', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
            $this->error('Failed to add leave allocation. No changes were saved.', 500);
        }
    }

    public function carryForwardAction(): void
    {
        $this->authorize();
        $data = $this->getJsonBody();

        try {
            $sourceYearId = $this->positiveId(
                $data['financial_year_id'] ?? null,
                'financial_year_id'
            );

            $result = $this->transaction(function (\mysqli $conn) use ($sourceYearId): array {
                $sourceYear = $this->financialYear($conn, $sourceYearId);
                $targetStmt = $conn->prepare(
                    'SELECT id, year_name FROM financial_years
                     WHERE start_date > ? ORDER BY start_date, id LIMIT 1'
                );
                $targetStmt->bind_param('s', $sourceYear['start_date']);
                $targetStmt->execute();
                $targetYear = $targetStmt->get_result()->fetch_assoc();
                $targetStmt->close();

                if (!$targetYear) {
                    throw new \InvalidArgumentException(
                        'There is no following financial year to carry balances into.'
                    );
                }

                $targetYearId = (int) $targetYear['id'];
                $annualTypeId = LeaveTypePolicy::TYPE_ANNUAL;
                $sourceStmt = $conn->prepare(
                    'SELECT DISTINCT employee_id
                     FROM employee_leave_balances
                     WHERE financial_year_id = ? AND leave_type_id = ?
                     ORDER BY CAST(employee_id AS UNSIGNED), employee_id'
                );
                $sourceStmt->bind_param('ii', $sourceYearId, $annualTypeId);
                $sourceStmt->execute();
                $sources = $sourceStmt->get_result()->fetch_all(\MYSQLI_ASSOC);
                $sourceStmt->close();

                $updated = 0;
                $created = 0;
                foreach ($sources as $source) {
                    $employeeId = (int) $source['employee_id'];
                    if ($employeeId <= 0) {
                        throw new \DomainException(
                            'A source annual balance has an invalid employee reference.'
                        );
                    }

                    $employee = $this->lockEmployee($conn, $employeeId);
                    $sourceRows = $this->balanceRows(
                        $conn,
                        $employeeId,
                        $annualTypeId,
                        $sourceYearId,
                        true
                    );
                    $this->assertSingleBalance($sourceRows);
                    if ($sourceRows === []) {
                        continue;
                    }
                    $source = $sourceRows[0];
                    $targets = $this->balanceRows(
                        $conn,
                        $employeeId,
                        $annualTypeId,
                        $targetYearId,
                        true
                    );
                    $this->assertSingleBalance($targets);
                    $before = $targets[0] ?? null;

                    $saved = $this->saveBalance(
                        $conn,
                        $before,
                        $employeeId,
                        $annualTypeId,
                        $targetYearId,
                        (float) ($before['allocated_days'] ?? 0),
                        (float) ($before['used_days'] ?? 0),
                        (float) $source['remaining_days']
                    );

                    $this->auditBalanceChange(
                        $employee,
                        $annualTypeId,
                        $targetYearId,
                        $before,
                        $saved,
                        $before === null ? 'CREATE' : 'UPDATE',
                        'Carried annual leave forward from '
                            . $sourceYear['year_name'] . ' to ' . $targetYear['year_name'] . '.',
                        [
                            'source_financial_year_id' => $sourceYearId,
                            'source_remaining_days' => (float) $source['remaining_days'],
                        ]
                    );

                    if ($before === null) {
                        $created++;
                    } else {
                        $updated++;
                    }
                }

                return [
                    'source_financial_year' => $sourceYear['year_name'],
                    'target_financial_year' => $targetYear['year_name'],
                    'updated' => $updated,
                    'created' => $created,
                    'total' => $updated + $created,
                ];
            });

            $this->success($result, 'Annual leave balances carried forward.');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 422, 'VALIDATION_ERROR');
        } catch (\OutOfBoundsException $e) {
            $this->error($e->getMessage(), 404, 'NOT_FOUND');
        } catch (\DomainException $e) {
            $this->error($e->getMessage(), 409, 'BALANCE_CONFLICT');
        } catch (\Throwable $e) {
            \logger()->error('Annual leave carry-forward failed', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
            $this->error('Carry-forward failed. No balances were changed.', 500);
        }
    }

    private function authorize(): void
    {
        $this->requirePermission('leave', 'manage');
        if (strtolower($this->getUserRole()) !== 'super_admin') {
            $this->forbidden('Only Super Admin may manage leave balances.');
        }
    }

    private function transaction(callable $operation): mixed
    {
        $conn = Database::getInstance()->getConnection();
        $conn->begin_transaction();
        try {
            $result = $operation($conn);
            $conn->commit();
            return $result;
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    private function positiveId(mixed $value, string $field): int
    {
        if (!is_scalar($value) || !ctype_digit((string) $value) || (int) $value <= 0) {
            throw new \InvalidArgumentException(ucfirst(str_replace('_', ' ', $field)) . ' is required.');
        }
        return (int) $value;
    }

    private function days(mixed $value, string $field, bool $nonnegative): float
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(ucfirst(str_replace('_', ' ', $field)) . ' must be a number.');
        }
        $days = round((float) $value, 2);
        if (!is_finite($days) || $days > 999.99 || $days < ($nonnegative ? 0 : -999.99)) {
            throw new \InvalidArgumentException(
                ucfirst(str_replace('_', ' ', $field)) . ' is outside the supported balance range.'
            );
        }
        return $days;
    }

    /**
     * @return array<string,mixed>
     */
    private function lockEmployee(\mysqli $conn, int $employeeId): array
    {
        $stmt = $conn->prepare(
            'SELECT id, employee_id, first_name, last_name, employee_status
             FROM employees WHERE id = ? FOR UPDATE'
        );
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $employee = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$employee) {
            throw new \OutOfBoundsException('Employee not found.');
        }
        return $employee;
    }

    private function requireReference(
        \mysqli $conn,
        string $table,
        int $id,
        string $label
    ): void {
        $stmt = $conn->prepare("SELECT id FROM {$table} WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exists) {
            throw new \OutOfBoundsException($label . ' not found.');
        }
    }

    private function financialYear(\mysqli $conn, int $id): array
    {
        $stmt = $conn->prepare(
            'SELECT id, year_name, start_date, end_date
             FROM financial_years WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $year = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$year) {
            throw new \OutOfBoundsException('Financial year not found.');
        }
        return $year;
    }

    private function balanceRows(
        \mysqli $conn,
        int $employeeId,
        int $leaveTypeId,
        int $financialYearId,
        bool $lock
    ): array {
        $sql = 'SELECT id, employee_id, leave_type_id, financial_year_id,
                       allocated_days, used_days, brought_forward_days,
                       accumulated_days, remaining_days
                FROM employee_leave_balances
                WHERE employee_id = ? AND leave_type_id = ? AND financial_year_id = ?
                ORDER BY id';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }

        $employeeKey = (string) $employeeId;
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sii', $employeeKey, $leaveTypeId, $financialYearId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(\MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private function assertSingleBalance(array $rows): void
    {
        if (count($rows) > 1) {
            throw new \DomainException(
                'Multiple balance rows exist for this employee, leave type and financial year. No changes were made.'
            );
        }
    }

    private function saveBalance(
        \mysqli $conn,
        ?array $existing,
        int $employeeId,
        int $leaveTypeId,
        int $financialYearId,
        float $allocated,
        float $used,
        float $broughtForward
    ): array {
        $accumulated = round($allocated + $broughtForward, 2);
        $remaining = round($accumulated - $used, 2);

        if ($existing !== null) {
            $id = (int) $existing['id'];
            $stmt = $conn->prepare(
                'UPDATE employee_leave_balances
                 SET allocated_days = ?, used_days = ?, brought_forward_days = ?,
                     accumulated_days = ?, remaining_days = ?
                 WHERE id = ?'
            );
            $stmt->bind_param(
                'dddddi',
                $allocated,
                $used,
                $broughtForward,
                $accumulated,
                $remaining,
                $id
            );
            $stmt->execute();
            $stmt->close();
        } else {
            $employeeKey = (string) $employeeId;
            $stmt = $conn->prepare(
                'INSERT INTO employee_leave_balances
                    (employee_id, leave_type_id, financial_year_id, allocated_days,
                     used_days, brought_forward_days, accumulated_days, remaining_days)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'siiddddd',
                $employeeKey,
                $leaveTypeId,
                $financialYearId,
                $allocated,
                $used,
                $broughtForward,
                $accumulated,
                $remaining
            );
            $stmt->execute();
            $id = (int) $stmt->insert_id;
            $stmt->close();
        }

        return [
            'id' => $id,
            'employee_id' => $employeeId,
            'leave_type_id' => $leaveTypeId,
            'financial_year_id' => $financialYearId,
            'allocated_days' => $allocated,
            'used_days' => $used,
            'brought_forward_days' => $broughtForward,
            'accumulated_days' => $accumulated,
            'remaining_days' => $remaining,
        ];
    }

    private function auditBalanceChange(
        array $employee,
        int $leaveTypeId,
        int $financialYearId,
        ?array $before,
        array $after,
        string $action,
        string $description,
        array $metadata = []
    ): void {
        $employeeName = trim(($employee['first_name'] ?? '') . ' ' . ($employee['last_name'] ?? ''));
        $auditId = AuditService::getInstance()->log(
            AuditService::MODULE_LEAVE,
            $action,
            $description,
            [
                'target_type' => 'EmployeeLeaveBalance',
                'target_id' => (int) $after['id'],
                'target_name' => $employeeName . ' (employee #' . (int) $employee['id'] . ')',
                'old_values' => $before === null ? null : $this->snapshot($before),
                'new_values' => $this->snapshot($after),
                'metadata' => array_merge($metadata, [
                    'employee_id' => (int) $employee['id'],
                    'employee_code' => $employee['employee_id'],
                    'leave_type_id' => $leaveTypeId,
                    'financial_year_id' => $financialYearId,
                ]),
            ]
        );

        if ($auditId === null) {
            throw new \RuntimeException('The leave balance change could not be written to the audit log.');
        }
    }

    private function snapshot(array $balance): array
    {
        return [
            'allocated_days' => (float) ($balance['allocated_days'] ?? 0),
            'used_days' => (float) ($balance['used_days'] ?? 0),
            'brought_forward_days' => (float) ($balance['brought_forward_days'] ?? 0),
            'accumulated_days' => (float) ($balance['accumulated_days'] ?? 0),
            'remaining_days' => (float) ($balance['remaining_days'] ?? 0),
        ];
    }

    private function fetchRows(\mysqli $conn, string $sql): array
    {
        $result = $conn->query($sql);
        return $result->fetch_all(\MYSQLI_ASSOC);
    }
}

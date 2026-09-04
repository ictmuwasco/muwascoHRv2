<?php

declare(strict_types=1);

namespace App\Services\Leave;

/**
 * A requested leave action would exceed the employee's available balance
 * (or otherwise violate balance rules). Maps to HTTP 422 with code
 * INSUFFICIENT_LEAVE_BALANCE.
 */
class InsufficientLeaveBalanceException extends LeaveException
{
}

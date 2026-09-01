<?php

declare(strict_types=1);

namespace App\Services\Leave;

/**
 * A leave workflow state transition that is not allowed by the business
 * rules (e.g. approving an already-approved application, rejecting a
 * cancelled one). Maps to HTTP 409 CONFLICT with code INVALID_TRANSITION.
 */
class InvalidLeaveTransitionException extends LeaveException
{
}

<?php

declare(strict_types=1);

namespace App\Events;

/**
 * Leave Event
 * 
 * Base class for leave-related events.
 */
abstract class LeaveEvent extends Event
{
    public function __construct(
        public readonly int $leaveId,
        public readonly int $employeeId,
        public readonly array $leaveData = []
    ) {
        parent::__construct();
    }
}

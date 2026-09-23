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
    /** @var int */
    public int $leaveId;

    /** @var int */
    public int $employeeId;

    /** @var array */
    public array $leaveData;

    // NOTE: no constructor property promotion with `readonly` here:
    // the codebase targets PHP >= 8.0 (composer.json) and `readonly`
    // properties are PHP 8.1+. Keep this file parseable by PHP 8.0
    // so the CI `php -l` gate stays green on every runner.
    public function __construct(int $leaveId, int $employeeId, array $leaveData = [])
    {
        $this->leaveId = $leaveId;
        $this->employeeId = $employeeId;
        $this->leaveData = $leaveData;
        parent::__construct();
    }
}

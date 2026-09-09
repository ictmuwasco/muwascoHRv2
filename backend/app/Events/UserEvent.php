<?php

declare(strict_types=1);

namespace App\Events;

/**
 * User Event
 * 
 * Base class for user-related events.
 */
abstract class UserEvent extends Event
{
    public function __construct(
        public readonly int $userId,
        public readonly array $userData = []
    ) {
        parent::__construct();
    }
}

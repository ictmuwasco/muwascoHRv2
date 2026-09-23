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
    /** @var int */
    public int $userId;

    /** @var array */
    public array $userData;

    // NOTE: no constructor property promotion with `readonly` here:
    // the codebase targets PHP >= 8.0 (composer.json) and `readonly`
    // properties are PHP 8.1+. Keep this file parseable by PHP 8.0
    // so the CI `php -l` gate stays green on every runner.
    public function __construct(int $userId, array $userData = [])
    {
        $this->userId = $userId;
        $this->userData = $userData;
        parent::__construct();
    }
}

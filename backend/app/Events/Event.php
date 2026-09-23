<?php

declare(strict_types=1);

namespace App\Events;

/**
 * Base Event Class
 * 
 * All events should extend this class.
 */
abstract class Event
{
    /**
     * When the event was dispatched.
     *
     * NOTE: plain typed property (no `readonly`): the codebase targets
     * PHP >= 8.0 (composer.json) and `readonly` is PHP 8.1+.
     *
     * @var \DateTimeImmutable
     */
    public \DateTimeImmutable $dispatchedAt;

    public function __construct()
    {
        $this->dispatchedAt = new \DateTimeImmutable();
    }

    /**
     * Get the event name (defaults to class name)
     */
    public function getName(): string
    {
        return static::class;
    }
}

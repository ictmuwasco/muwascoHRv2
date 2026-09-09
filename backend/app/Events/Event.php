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
     * When the event was dispatched
     */
    public readonly \DateTimeImmutable $dispatchedAt;

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

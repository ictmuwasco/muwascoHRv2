<?php

declare(strict_types=1);

namespace App\Events;

/**
 * Simple Event Dispatcher
 * 
 * Provides a basic event system for decoupled application components.
 * Allows registering listeners and dispatching events.
 * 
 * Usage:
 *   // Register a listener
 *   EventDispatcher::listen(UserCreatedEvent::class, function($event) {
 *       // Send welcome email
 *   });
 *   
 *   // Dispatch an event
 *   EventDispatcher::dispatch(new UserCreatedEvent($user));
 */
class EventDispatcher
{
    /** @var array<string, array<callable>> Event listeners */
    private static array $listeners = [];

    /** @var array<string, array> Deferred events for after response */
    private static array $deferredEvents = [];

    /**
     * Register a listener for an event
     */
    public static function listen(string $eventClass, callable $listener): void
    {
        if (!isset(self::$listeners[$eventClass])) {
            self::$listeners[$eventClass] = [];
        }
        self::$listeners[$eventClass][] = $listener;
    }

    /**
     * Dispatch an event to all registered listeners
     */
    public static function dispatch(object $event): void
    {
        $eventClass = get_class($event);

        if (!isset(self::$listeners[$eventClass])) {
            return;
        }

        foreach (self::$listeners[$eventClass] as $listener) {
            try {
                $listener($event);
            } catch (\Throwable $e) {
                // Log error but don't break the application
                error_log(sprintf(
                    '[Event] Listener error for %s: %s',
                    $eventClass,
                    $e->getMessage()
                ));
            }
        }
    }

    /**
     * Dispatch an event after the response is sent
     */
    public static function dispatchDeferred(object $event): void
    {
        $eventClass = get_class($event);
        
        if (!isset(self::$deferredEvents[$eventClass])) {
            self::$deferredEvents[$eventClass] = [];
        }
        
        self::$deferredEvents[$eventClass][] = $event;
    }

    /**
     * Process all deferred events
     */
    public static function processDeferred(): void
    {
        foreach (self::$deferredEvents as $eventClass => $events) {
            foreach ($events as $event) {
                self::dispatch($event);
            }
        }
        self::$deferredEvents = [];
    }

    /**
     * Check if an event has listeners
     */
    public static function hasListeners(string $eventClass): bool
    {
        return !empty(self::$listeners[$eventClass]);
    }

    /**
     * Get all registered listeners for an event
     */
    public static function getListeners(string $eventClass): array
    {
        return self::$listeners[$eventClass] ?? [];
    }

    /**
     * Remove all listeners for an event
     */
    public static function forget(string $eventClass): void
    {
        unset(self::$listeners[$eventClass]);
    }

    /**
     * Clear all registered listeners
     */
    public static function clear(): void
    {
        self::$listeners = [];
        self::$deferredEvents = [];
    }
}

<?php

declare(strict_types=1);

namespace App\Gates;

use App\Helpers\Session;
use App\Helpers\AuthorizationService;

/**
 * Gate
 *
 * Provides a centralized authorization system for checking permissions.
 * Gates are simple closures that determine if a user can perform an action.
 */
class Gate
{
    private static ?Gate $instance = null;
    private array $beforeCallbacks = [];
    private array $afterCallbacks = [];
    private array $definedGates = [];

    private function __construct()
    {
    }

    /**
     * Get the Gate instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Define a new gate.
     */
    public function define(string $ability, callable $callback): void
    {
        $this->definedGates[$ability] = $callback;
    }

    /**
     * Check if the user can perform an action.
     */
    public function allows(string $ability, array $arguments = []): bool
    {
        $userId = Session::getInstance()->get('user_id');
        $userRole = Session::getInstance()->get('user_role');

        // Run before callbacks
        foreach ($this->beforeCallbacks as $callback) {
            $result = $callback($userRole, $ability, $arguments);
            if ($result !== null) {
                return (bool)$result;
            }
        }

        // Check if gate is defined
        if (isset($this->definedGates[$ability])) {
            $result = call_user_func($this->definedGates[$ability], $userRole, ...$arguments);
            
            // Run after callbacks
            foreach ($this->afterCallbacks as $callback) {
                $result = $callback($userRole, $ability, $arguments, $result);
            }
            
            return (bool)$result;
        }

        // Default to authorization service
        $authService = new AuthorizationService();
        $resource = $arguments[0] ?? '';
        $action = $arguments[1] ?? 'view';

        return $authService->hasPermission($userRole, $resource, $action);
    }

    /**
     * Check if the user cannot perform an action.
     */
    public function denies(string $ability, array $arguments = []): bool
    {
        return !$this->allows($ability, $arguments);
    }

    /**
     * Define a before callback.
     */
    public function before(callable $callback): void
    {
        $this->beforeCallbacks[] = $callback;
    }

    /**
     * Define an after callback.
     */
    public function after(callable $callback): void
    {
        $this->afterCallbacks[] = $callback;
    }

    /**
     * Authorize an action or throw an exception.
     */
    public function authorize(string $ability, array $arguments = []): void
    {
        if (!$this->allows($ability, $arguments)) {
            throw new \App\Exceptions\AuthorizationException(
                "This action is unauthorized."
            );
        }
    }

    /**
     * Check if any of the given abilities are allowed.
     */
    public function any(string $abilities, array $arguments = []): bool
    {
        foreach ((array)$abilities as $ability) {
            if ($this->allows($ability, $arguments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if all of the given abilities are allowed.
     */
    public function all(string $abilities, array $arguments = []): bool
    {
        foreach ((array)$abilities as $ability) {
            if (!$this->allows($ability, $arguments)) {
                return false;
            }
        }

        return true;
    }
}
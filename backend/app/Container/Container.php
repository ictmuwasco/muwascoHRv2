<?php

declare(strict_types=1);

namespace App\Container;

use ArrayAccess;
use Closure;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use RuntimeException;

class Container implements ArrayAccess
{
    private static ?Container $instance = null;
    private array $instances = [];
    private array $bindings = [];
    private array $singletons = [];
    private array $aliases = [];

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function bind(string $abstract, string|Closure|null $concrete = null, bool $shared = false): void
    {
        if ($concrete === null) {
            $concrete = $abstract;
        }
        $this->bindings[$abstract] = $concrete;
        $this->singletons[$abstract] = $shared;
        unset($this->instances[$abstract]);
    }

    public function singleton(string $abstract, string|Closure|null $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    public function instance(string $abstract, mixed $instance): mixed
    {
        $this->instances[$abstract] = $instance;
        return $instance;
    }

    public function alias(string $abstract, string $alias): void
    {
        $this->aliases[$alias] = $abstract;
    }

    public function get(string $abstract): mixed
    {
        $abstract = $this->aliases[$abstract] ?? $abstract;
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }
        $instance = $this->build($abstract);
        if (!empty($this->singletons[$abstract])) {
            $this->instances[$abstract] = $instance;
        }
        return $instance;
    }

    public function has(string $abstract): bool
    {
        $abstract = $this->aliases[$abstract] ?? $abstract;
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }

    public function build(string $concrete): mixed
    {
        if ($concrete instanceof Closure) {
            return $concrete($this);
        }
        if (isset($this->bindings[$concrete])) {
            $binding = $this->bindings[$concrete];
            if ($binding instanceof Closure) {
                return $binding($this);
            }
            return $this->build($binding);
        }
        return $this->autoWire($concrete);
    }

    private function autoWire(string $concrete): object
    {
        try {
            $reflector = new ReflectionClass($concrete);
        } catch (ReflectionException $e) {
            throw new RuntimeException("Target class [{$concrete}] does not exist.");
        }
        if (!$reflector->isInstantiable()) {
            throw new RuntimeException("Target class [{$concrete}] is not instantiable.");
        }
        $constructor = $reflector->getConstructor();
        if ($constructor === null) {
            return new $concrete();
        }
        $dependencies = $this->resolveDependencies($constructor->getParameters());
        return $reflector->newInstanceArgs($dependencies);
    }

    private function resolveDependencies(array $parameters): array
    {
        $dependencies = [];
        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();
                if ($this->has($typeName)) {
                    $dependencies[] = $this->get($typeName);
                    continue;
                }
                try {
                    $dependencies[] = $this->autoWire($typeName);
                    continue;
                } catch (RuntimeException $e) {
                    // Fall through
                }
            }
            if ($parameter->isDefaultValueAvailable()) {
                $dependencies[] = $parameter->getDefaultValue();
                continue;
            }
            if ($type !== null && $type->allowsNull()) {
                $dependencies[] = null;
                continue;
            }
            throw new RuntimeException("Cannot resolve dependency [{$parameter->getName()}]");
        }
        return $dependencies;
    }

    public function call(callable|array $callback, array $parameters = []): mixed
    {
        if (is_array($callback)) {
            [$class, $method] = $callback;
            $instance = is_string($class) ? $this->get($class) : $class;
            try {
                $reflector = new \ReflectionMethod($instance, $method);
                $dependencies = $this->resolveDependencies($reflector->getParameters());
                return $instance->$method(...array_merge($dependencies, $parameters));
            } catch (ReflectionException $e) {
                throw new RuntimeException("Cannot call method [{$method}]");
            }
        }
        if ($callback instanceof Closure) {
            try {
                $reflector = new \ReflectionFunction($callback);
                $dependencies = $this->resolveDependencies($reflector->getParameters());
                return $callback(...array_merge($dependencies, $parameters));
            } catch (ReflectionException $e) {
                throw new RuntimeException("Cannot call closure");
            }
        }
        return call_user_func($callback, ...$parameters);
    }

    public function forget(string $abstract): void
    {
        unset($this->bindings[$abstract]);
        unset($this->instances[$abstract]);
        unset($this->singletons[$abstract]);
    }

    public function getBindings(): array
    {
        return array_keys($this->bindings);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->bind($offset, fn() => $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->forget($offset);
    }

    private function __clone() {}

    public function __wakeup()
    {
        throw new RuntimeException('Cannot unserialize container');
    }
}

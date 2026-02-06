<?php
/**
 * File: app/Bootstrap/container.php
 *
 * CRM V2 - Lightweight Dependency Injection Container
 * ------------------------------------------------------------
 * هدف:
 *  - یک container ساده ولی قدرتمند برای مدیریت سرویس‌ها (config, logger, db, ...)
 *  - پشتیبانی از:
 *      - singleton services (cached)
 *      - factories (new instance each time)
 *      - aliases
 *      - parameters (plain values)
 *      - extend decorator (wrap existing services)
 *      - protected closures (store closures as values)
 *      - ArrayAccess support ($app['db'])
 *
 * Usage:
 *  $c = new Container();
 *  $c->set('config', $config);
 *  $c->singleton('db', fn(Container $c) => new PDO(...));
 *  $pdo = $c->get('db'); // cached
 *
 *  $c->factory('uuid', fn() => bin2hex(random_bytes(16)));
 *  $id1 = $c->get('uuid'); // new each call
 *  $id2 = $c->get('uuid'); // new each call
 *
 * Aliases:
 *  $c->alias('pdo', 'db');
 *  $c->get('pdo') === $c->get('db')
 */

declare(strict_types=1);

namespace App\Bootstrap;

use ArrayAccess;
use Closure;
use RuntimeException;
use Throwable;

final class Container implements ArrayAccess
{
    /**
     * Plain values (parameters) OR already-instantiated objects.
     * @var array<string,mixed>
     */
    private array $items = [];

    /**
     * Singleton service definitions (cached after first build).
     * @var array<string,callable>
     */
    private array $singletons = [];

    /**
     * Factory service definitions (new instance every call).
     * @var array<string,callable>
     */
    private array $factories = [];

    /**
     * Cache for singleton services once resolved.
     * @var array<string,mixed>
     */
    private array $resolved = [];

    /**
     * Aliases: alias => target
     * @var array<string,string>
     */
    private array $aliases = [];

    /**
     * Protected closures: key => Closure (stored as value, not executed)
     * @var array<string,bool>
     */
    private array $protected = [];

    /**
     * Stack to detect circular dependencies.
     * @var array<int,string>
     */
    private array $resolvingStack = [];

    // -------------------------------------------------------------------------
    // Core API
    // -------------------------------------------------------------------------

    /**
     * Set a parameter/value directly.
     * If you pass a Closure, by default it will behave as "callable service"
     * ONLY if you register it via singleton() / factory(). Otherwise it's stored as value.
     */
    public function set(string $key, mixed $value): void
    {
        $key = $this->normalizeKey($key);

        // Remove any previous definition to avoid inconsistent states
        unset($this->singletons[$key], $this->factories[$key], $this->resolved[$key], $this->aliases[$key]);
        $this->items[$key] = $value;
    }

    /**
     * Get a resolved service or value.
     */
    public function get(string $key): mixed
    {
        $key = $this->normalizeKey($key);
        $key = $this->resolveAlias($key);

        // already resolved singleton?
        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        // plain value?
        if (array_key_exists($key, $this->items)) {
            $val = $this->items[$key];
            // If this is a protected closure, return as is.
            if ($val instanceof Closure && ($this->protected[$key] ?? false) === true) {
                return $val;
            }
            return $val;
        }

        // singleton definition?
        if (array_key_exists($key, $this->singletons)) {
            return $this->resolved[$key] = $this->build($key, $this->singletons[$key], true);
        }

        // factory definition?
        if (array_key_exists($key, $this->factories)) {
            return $this->build($key, $this->factories[$key], false);
        }

        throw new RuntimeException("Service not found: {$key}");
    }

    /**
     * Check whether key exists (value or service definition).
     */
    public function has(string $key): bool
    {
        $key = $this->normalizeKey($key);
        $key = $this->resolveAlias($key);

        return array_key_exists($key, $this->items)
            || array_key_exists($key, $this->singletons)
            || array_key_exists($key, $this->factories)
            || array_key_exists($key, $this->resolved);
    }

    /**
     * Remove a key definition/value/cache entirely.
     */
    public function forget(string $key): void
    {
        $key = $this->normalizeKey($key);

        unset(
            $this->items[$key],
            $this->singletons[$key],
            $this->factories[$key],
            $this->resolved[$key],
            $this->aliases[$key],
            $this->protected[$key]
        );
    }

    // -------------------------------------------------------------------------
    // Registration helpers
    // -------------------------------------------------------------------------

    /**
     * Register a singleton service: built once and cached.
     * Callable signature:
     *   fn(Container $c): mixed
     */
    public function singleton(string $key, callable $resolver): void
    {
        $key = $this->normalizeKey($key);

        unset($this->items[$key], $this->factories[$key], $this->resolved[$key]);
        $this->singletons[$key] = $resolver;
    }

    /**
     * Register a factory service: built on every get().
     */
    public function factory(string $key, callable $resolver): void
    {
        $key = $this->normalizeKey($key);

        unset($this->items[$key], $this->singletons[$key], $this->resolved[$key]);
        $this->factories[$key] = $resolver;
    }

    /**
     * Register an alias: aliasKey will resolve to targetKey.
     * Example:
     *   alias('pdo', 'db')
     */
    public function alias(string $aliasKey, string $targetKey): void
    {
        $aliasKey  = $this->normalizeKey($aliasKey);
        $targetKey = $this->normalizeKey($targetKey);

        if ($aliasKey === $targetKey) {
            throw new RuntimeException("Alias cannot point to itself: {$aliasKey}");
        }

        $this->aliases[$aliasKey] = $targetKey;
    }

    /**
     * Store a Closure as a value (do NOT execute automatically).
     * Useful when you want to store callbacks/config functions.
     */
    public function protect(string $key, Closure $closure): void
    {
        $key = $this->normalizeKey($key);
        $this->set($key, $closure);
        $this->protected[$key] = true;
    }

    /**
     * Extend an existing singleton service with a decorator.
     * Example:
     *   $c->extend('logger', fn($logger, Container $c) => new MyLogger($logger));
     */
    public function extend(string $key, callable $decorator): void
    {
        $key = $this->normalizeKey($key);
        $key = $this->resolveAlias($key);

        // If already resolved, decorate immediately and replace cache
        if (array_key_exists($key, $this->resolved)) {
            $this->resolved[$key] = $decorator($this->resolved[$key], $this);
            return;
        }

        // If singleton definition exists, wrap it
        if (array_key_exists($key, $this->singletons)) {
            $original = $this->singletons[$key];
            $this->singletons[$key] = function (Container $c) use ($original, $decorator) {
                $service = $original($c);
                return $decorator($service, $c);
            };
            return;
        }

        // If plain value exists, decorate and store as value
        if (array_key_exists($key, $this->items)) {
            $this->items[$key] = $decorator($this->items[$key], $this);
            return;
        }

        throw new RuntimeException("Cannot extend service '{$key}' because it does not exist.");
    }

    /**
     * Resolve and return all currently registered keys (debugging).
     * @return array<int,string>
     */
    public function keys(): array
    {
        $k = array_unique(array_merge(
            array_keys($this->items),
            array_keys($this->singletons),
            array_keys($this->factories),
            array_keys($this->resolved),
            array_keys($this->aliases)
        ));
        sort($k);
        return $k;
    }

    // -------------------------------------------------------------------------
    // Building & internal helpers
    // -------------------------------------------------------------------------

    private function build(string $key, callable $resolver, bool $isSingleton): mixed
    {
        $this->guardCircular($key);

        $this->resolvingStack[] = $key;

        try {
            // Common convention: resolver gets container as first arg.
            // But we also allow zero-arg closures for convenience.
            $result = $this->invokeResolver($resolver);
        } catch (Throwable $e) {
            $stack = implode(' -> ', $this->resolvingStack);
            array_pop($this->resolvingStack);
            throw new RuntimeException("Failed building service '{$key}'. Stack: {$stack}. Error: " . $e->getMessage(), 0, $e);
        }

        array_pop($this->resolvingStack);

        // If singleton, cache is handled by caller; here just return.
        // If factory, return new each time.
        return $result;
    }

    private function invokeResolver(callable $resolver): mixed
    {
        // Try with container argument if supported
        if ($resolver instanceof Closure) {
            $ref = new \ReflectionFunction($resolver);
            $params = $ref->getParameters();
            if (count($params) >= 1) {
                return $resolver($this);
            }
            return $resolver();
        }

        // Callable array/object-method or invokable
        // Try passing container; if fails, call without args.
        try {
            return $resolver($this);
        } catch (Throwable $e) {
            // fallback attempt
            return $resolver();
        }
    }

    private function guardCircular(string $key): void
    {
        if (in_array($key, $this->resolvingStack, true)) {
            $stack = implode(' -> ', array_merge($this->resolvingStack, [$key]));
            throw new RuntimeException("Circular dependency detected: {$stack}");
        }
    }

    private function resolveAlias(string $key): string
    {
        // Resolve chained aliases safely
        $seen = [];
        $cur = $key;

        while (isset($this->aliases[$cur])) {
            if (isset($seen[$cur])) {
                throw new RuntimeException("Alias loop detected near: {$cur}");
            }
            $seen[$cur] = true;
            $cur = $this->aliases[$cur];
        }

        return $cur;
    }

    private function normalizeKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            throw new RuntimeException("Container key cannot be empty.");
        }
        return $key;
    }

    // -------------------------------------------------------------------------
    // ArrayAccess implementation (so you can do $app['db'])
    // -------------------------------------------------------------------------

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string)$offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string)$offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set((string)$offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->forget((string)$offset);
    }
}

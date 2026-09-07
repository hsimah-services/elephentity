<?php

declare(strict_types=1);

namespace Clog;

use Closure;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * A container, in thirty lines, so the example depends on no particular one.
 *
 * The framework asks a container for two things: the classes it generated, and the
 * interfaces it generated for you to implement. `BootCheck` walks the second list and
 * refuses to start while any of them is unbound, so this only has to be able to answer
 * `has()` honestly and build each service once.
 *
 * A real project uses whatever it already has. Nothing here is Elephentity's shape.
 */
final class Container implements ContainerInterface
{
    /** @var array<string, Closure(self): object> */
    private array $factories = [];

    /** @var array<string, object> */
    private array $resolved = [];

    /**
     * @param Closure(self): object $factory
     */
    public function set(string $id, Closure $factory): self
    {
        $this->factories[$id] = $factory;

        return $this;
    }

    /**
     * Bind an interface to the class implementing it, so the contract and the
     * implementation are one entry rather than two.
     *
     * @param Closure(self): object $factory
     */
    public function bind(string $contract, Closure $factory): self
    {
        return $this->set($contract, $factory);
    }

    /**
     * Typed by the id, so wiring stays checkable.
     *
     * PSR-11 says `mixed`, which would make every constructor call in Bootstrap an
     * unchecked hand-off. The template is what turns "this container is wired
     * correctly" into something static analysis answers rather than the first request.
     *
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    public function get(string $id): object
    {
        $service = $this->resolved[$id] ??= ($this->factories[$id] ?? throw new RuntimeException(
            sprintf('Nothing is bound to %s.', $id),
        ))($this);

        if (!$service instanceof $id) {
            throw new RuntimeException(sprintf('%s is bound to a %s.', $id, $service::class));
        }

        return $service;
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }
}
